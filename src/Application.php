<?php

declare(strict_types=1);

namespace Racin;

use ErrorException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Racin\Auth\Jwt;
use Racin\Auth\Middleware\Authenticate;
use Racin\Auth\Middleware\RequirePermission;
use Racin\Auth\Middleware\RequireRole;
use Racin\Auth\ModelUserProvider;
use Racin\Auth\TokenBlacklist;
use Racin\Auth\UserProvider;
use Racin\Broadcasting\ArrayBroadcaster;
use Racin\Broadcasting\Broadcaster;
use Racin\Broadcasting\ChannelRegistry;
use Racin\Broadcasting\LogBroadcaster;
use Racin\Broadcasting\NullBroadcaster;
use Racin\Broadcasting\WebSocketBroadcaster;
use Racin\Cache\ArrayStore;
use Racin\Cache\Cache;
use Racin\Cache\FileStore;
use Racin\Cache\RedisStore;
use Racin\Database\Migrations\Migrator;
use Racin\Image\ImageUploader;
use Racin\Storage\StorageManager;
use Racin\Http\Middleware\SetLocale;
use Racin\Mail\Address;
use Racin\Mail\ArrayTransport;
use Racin\Mail\LogTransport;
use Racin\Mail\Mailer;
use Racin\Mail\SmtpTransport;
use Racin\Mail\Transport;
use Racin\Queue\FailedJobRepository;
use Racin\Queue\QueueManager;
use Racin\Redis\RedisFactory;
use Racin\Translation\Translator;
use Racin\Config\Config;
use Racin\Config\Env;
use Racin\Console\Kernel as ConsoleKernel;
use Racin\Container\Container;
use Racin\Database\DatabaseManager;
use Racin\Database\DB;
use Racin\Database\Model;
use Racin\Exceptions\Handler;
use Racin\Http\JsonResponse;
use Racin\Http\Kernel;
use Racin\Http\Middleware\HandleCors;
use Racin\Http\Middleware\ThrottleRequests;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Log\Logger;
use Racin\Routing\Router;
use Racin\Support\ServiceProvider;
use Racin\Validation\Validator;
use RuntimeException;
use Throwable;

/**
 * The application: service container, configuration and entry points for HTTP and CLI.
 */
class Application extends Container
{
    public const string VERSION = '1.2.3';

    /** Middleware aliases that ship with the framework. */
    public const array MIDDLEWARE_ALIASES = [
        'auth' => Authenticate::class,
        'throttle' => ThrottleRequests::class,
        'cors' => HandleCors::class,
        'role' => RequireRole::class,
        'can' => RequirePermission::class,
        'locale' => SetLocale::class,
    ];

    private static ?Application $current = null;

    protected string $basePath;

    protected bool $booted = false;

    protected bool $routesLoaded = false;

    protected bool $handleErrors = true;

    /** @var list<ServiceProvider> */
    protected array $providers = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');

        self::$current = $this;

        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
        $this->alias(self::class, 'app');

        if (static::class !== self::class) {
            $this->instance(static::class, $this);
        }
    }

    public static function getInstance(): static
    {
        return self::$current ?? throw new RuntimeException('No Racin application has been created.');
    }

    /**
     * Disable global error/exception handlers (for test suites that install their own).
     */
    public function handleErrors(bool $enabled): static
    {
        $this->handleErrors = $enabled;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Paths & environment
    // ---------------------------------------------------------------------

    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . DIRECTORY_SEPARATOR . 'config', $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . DIRECTORY_SEPARATOR . 'storage', $path);
    }

    public function cachePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache', $path);
    }

    public function config(): Config
    {
        return $this->make(Config::class);
    }

    public function environment(): string
    {
        return (string) $this->config()->get('app.env', 'production');
    }

    public function isDebug(): bool
    {
        return (bool) $this->config()->get('app.debug', false);
    }

    // ---------------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------------

    /**
     * Load environment + configuration, register services and providers.
     */
    public function bootstrap(): static
    {
        if ($this->booted) {
            return $this;
        }

        Env::load($this->basePath('.env'));

        $cached = $this->cachePath('config.php');
        $config = is_file($cached) ? new Config(require $cached) : Config::fromDirectory($this->configPath());

        $this->instance(Config::class, $config);
        $this->alias(Config::class, 'config');

        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        if ($this->handleErrors) {
            $this->registerErrorHandling();
        }

        $this->registerCoreServices($config);

        Request::setTrustedProxies((array) $config->get('app.trusted_proxies', []));

        foreach ((array) $config->get('app.providers', []) as $class) {
            $provider = new $class($this);

            if (!$provider instanceof ServiceProvider) {
                throw new RuntimeException("[{$class}] must extend " . ServiceProvider::class . '.');
            }

            $provider->register();
            $this->providers[] = $provider;
        }

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;

        return $this;
    }

    /**
     * Load routes from bootstrap/cache/routes.php, or every file in routes/.
     */
    public function loadRoutes(): Router
    {
        $router = $this->make(Router::class);

        if ($this->routesLoaded) {
            return $router;
        }

        $cache = $this->cachePath('routes.php');

        if (is_file($cache)) {
            $router->loadCache(require $cache);
        } else {
            // routes/channels.php defines WebSocket channel authorization, not HTTP routes.
            $files = array_filter(
                glob($this->basePath('routes' . DIRECTORY_SEPARATOR . '*.php')) ?: [],
                static fn (string $file): bool => basename($file) !== 'channels.php',
            );
            sort($files);

            foreach ($files as $file) {
                (static function (Router $router, Application $app) use ($file): void {
                    require $file;
                })($router, $this);
            }
        }

        $this->routesLoaded = true;

        return $router;
    }

    // ---------------------------------------------------------------------
    // Entry points
    // ---------------------------------------------------------------------

    public function handle(Request $request): Response
    {
        $this->bootstrap();
        $this->loadRoutes();

        return $this->make(Kernel::class)->handle($request);
    }

    /**
     * Handle the current HTTP request and send the response.
     */
    public function run(?Request $request = null): void
    {
        $request ??= Request::capture();

        try {
            $response = $this->handle($request);
        } catch (Throwable $e) {
            $response = $this->renderFatal($e);
        }

        $response->send(!$request->isMethod('HEAD'));
    }

    /**
     * Run a console command. Returns the exit code.
     *
     * @param list<string> $argv
     */
    public function runConsole(array $argv): int
    {
        $this->bootstrap();

        return $this->make(ConsoleKernel::class)->run($argv);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    protected function registerCoreServices(Config $config): void
    {
        $this->singleton(LoggerInterface::class, fn (): Logger => new Logger(
            (string) $config->get('logging.path', $this->storagePath('logs')),
            (string) $config->get('logging.level', 'debug'),
            $this->environment(),
            (bool) $config->get('logging.daily', true),
        ));
        $this->alias(LoggerInterface::class, Logger::class);
        $this->alias(LoggerInterface::class, 'log');

        $translator = new Translator(
            $this->basePath('lang'),
            (string) $config->get('app.locale', 'en'),
            (string) $config->get('app.fallback_locale', 'en'),
            array_values((array) $config->get('app.locales', [(string) $config->get('app.locale', 'en')])),
        );
        $this->instance(Translator::class, $translator);
        $this->alias(Translator::class, 'translator');
        Validator::setTranslator($translator);

        $this->singleton(Handler::class, fn (): Handler => $this->build(
            (string) $config->get('app.exception_handler', Handler::class),
            ['debug' => $this->isDebug(), 'logger' => $this->make(LoggerInterface::class), 'translator' => $translator],
        ));

        $database = new DatabaseManager((array) $config->get('database', []));
        $this->instance(DatabaseManager::class, $database);
        $this->alias(DatabaseManager::class, 'db');
        DB::setManager($database);
        Model::setConnectionResolver($database);
        Validator::setConnectionResolver(static fn () => $database->connection());

        $this->singleton(Migrator::class, fn (): Migrator => new Migrator($database, $this->basePath('database' . DIRECTORY_SEPARATOR . 'migrations')));

        $this->singleton(RedisFactory::class, fn (): RedisFactory => new RedisFactory((array) $config->get('database.redis', [])));

        $this->singleton(Cache::class, function () use ($config): Cache {
            $store = match ($driver = (string) $config->get('cache.driver', 'file')) {
                'file' => new FileStore((string) $config->get('cache.path', $this->storagePath('cache' . DIRECTORY_SEPARATOR . 'data'))),
                'array' => new ArrayStore(),
                'redis' => new RedisStore(
                    $this->make(RedisFactory::class)->connection((string) $config->get('cache.redis_connection', 'default')),
                    (string) $config->get('cache.redis_prefix', 'racin:cache:'),
                ),
                default => throw new RuntimeException("Unsupported cache driver [{$driver}]."),
            };

            return new Cache($store, (string) $config->get('cache.prefix', ''));
        });
        $this->alias(Cache::class, 'cache');

        $this->singleton(TokenBlacklist::class, fn (): TokenBlacklist => new TokenBlacklist($this->make(Cache::class)));

        // Without config/filesystems.php: "public" = public/uploads served at APP_URL/uploads, "local" = storage/app.
        $this->singleton(StorageManager::class, fn (): StorageManager => new StorageManager(array_replace_recursive([
            'default' => 'public',
            'disks' => [
                'public' => [
                    'root' => $this->basePath('public' . DIRECTORY_SEPARATOR . 'uploads'),
                    'url' => rtrim((string) $config->get('app.url', env('APP_URL', '')), '/') . '/uploads',
                ],
                'local' => ['root' => $this->storagePath('app')],
            ],
        ], (array) $config->get('filesystems', []))));

        $this->singleton(ImageUploader::class, fn (): ImageUploader => new ImageUploader(
            $this->make(StorageManager::class)->disk($config->get('filesystems.images.disk')),
            (int) $config->get('filesystems.images.max_width', 1600),
            (int) $config->get('filesystems.images.max_height', 1600),
            (int) $config->get('filesystems.images.thumb_width', 400),
            (int) $config->get('filesystems.images.thumb_height', 400),
            (string) $config->get('filesystems.images.format', 'webp'),
            (int) $config->get('filesystems.images.quality', 82),
        ));

        $this->singleton(QueueManager::class, fn (): QueueManager => new QueueManager($this, (array) $config->get('queue', [])));
        $this->alias(QueueManager::class, 'queue');

        $this->singleton(FailedJobRepository::class, fn (): FailedJobRepository => new FailedJobRepository(
            static fn () => $database->connection($config->get('queue.failed.connection')),
            (string) $config->get('queue.failed.table', 'failed_jobs'),
        ));

        $this->singleton(Transport::class, function () use ($config): Transport {
            return match ($driver = (string) $config->get('mail.mailer', 'log')) {
                'smtp' => new SmtpTransport(
                    (string) $config->get('mail.smtp.host', '127.0.0.1'),
                    (int) $config->get('mail.smtp.port', 587),
                    is_string($encryption = $config->get('mail.smtp.encryption')) && $encryption !== '' ? $encryption : null,
                    is_string($username = $config->get('mail.smtp.username')) && $username !== '' ? $username : null,
                    is_string($password = $config->get('mail.smtp.password')) ? $password : null,
                    (float) $config->get('mail.smtp.timeout', 10),
                    (string) $config->get('mail.smtp.local_domain', 'localhost'),
                ),
                'log' => new LogTransport($this->make(LoggerInterface::class)),
                'array' => new ArrayTransport(),
                default => throw new RuntimeException("Unsupported mailer [{$driver}]."),
            };
        });

        $this->singleton(Mailer::class, function () use ($config): Mailer {
            $from = $config->get('mail.from.address');

            return new Mailer(
                $this->make(Transport::class),
                is_string($from) && $from !== '' ? new Address($from, (string) $config->get('mail.from.name', '')) : null,
                $this->make(QueueManager::class),
            );
        });
        $this->alias(Mailer::class, 'mailer');

        $this->singleton(Broadcaster::class, function () use ($config): Broadcaster {
            return match ($driver = (string) $config->get('broadcasting.driver', 'null')) {
                'websocket' => new WebSocketBroadcaster(
                    (string) $config->get('broadcasting.websocket.internal_host', '127.0.0.1'),
                    (int) $config->get('broadcasting.websocket.internal_port', 6002),
                    $this->appKey(),
                    $this->make(LoggerInterface::class),
                    0.3,
                    $this->make(Cache::class),
                ),
                'log' => new LogBroadcaster($this->make(LoggerInterface::class)),
                'array' => new ArrayBroadcaster(),
                // BROADCAST_DRIVER=null in .env arrives as an empty value (env() converts "null")
                'null', '' => new NullBroadcaster(),
                default => throw new RuntimeException("Unsupported broadcast driver [{$driver}]."),
            };
        });

        $this->singleton(ChannelRegistry::class, function (): ChannelRegistry {
            $channels = new ChannelRegistry();
            $file = $this->basePath('routes' . DIRECTORY_SEPARATOR . 'channels.php');

            if (is_file($file)) {
                (static function (ChannelRegistry $channels, Application $app) use ($file): void {
                    require $file;
                })($channels, $this);
            }

            return $channels;
        });

        $this->singleton(Jwt::class, fn (): Jwt => new Jwt(
            $this->appKey(),
            (string) $config->get('auth.algorithm', 'HS256'),
            (int) $config->get('auth.ttl', 3600),
            is_string($issuer = $config->get('auth.issuer')) && $issuer !== '' ? $issuer : null,
            (int) $config->get('auth.leeway', 30),
        ));

        $this->singleton(UserProvider::class, function () use ($config): UserProvider {
            $provider = $config->get('auth.provider');

            return $provider !== null
                ? $this->make((string) $provider)
                : new ModelUserProvider((string) $config->get('auth.model'));
        });

        $this->singleton(Router::class, function () use ($config): Router {
            $router = new Router($this);
            $aliases = (array) $config->get('app.middleware_aliases', []) + self::MIDDLEWARE_ALIASES;

            foreach ($aliases as $name => $class) {
                $router->aliasMiddleware((string) $name, $class);
            }

            foreach ((array) $config->get('app.middleware_groups', []) as $name => $middleware) {
                $router->middlewareGroup((string) $name, (array) $middleware);
            }

            return $router;
        });

        $this->singleton(Kernel::class, fn (): Kernel => new Kernel(
            $this,
            $this->make(Router::class),
            $this->make(Handler::class),
            array_values((array) $config->get('app.middleware', [])),
        ));
    }

    /**
     * The decoded APP_KEY ("base64:..." values are decoded).
     */
    public function appKey(): string
    {
        $key = (string) $this->config()->get('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set. Run "php racin key:generate".');
        }

        return $key;
    }

    protected function registerErrorHandling(): void
    {
        error_reporting(E_ALL);

        if (PHP_SAPI !== 'cli') {
            ini_set('display_errors', '0');
        }

        set_error_handler(function (int $level, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
                $this->logQuietly('warning', "Deprecated: {$message} in {$file}:{$line}");

                return true;
            }

            throw new ErrorException($message, 0, $level, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            if (PHP_SAPI === 'cli') {
                $this->logQuietly('error', $e->getMessage(), ['exception' => $e]);
                fwrite(STDERR, $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);

                return;
            }

            $this->renderFatal($e)->send();
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);

            if (PHP_SAPI === 'cli') {
                $this->logQuietly('critical', $error['message'], ['exception' => $exception]);

                return;
            }

            $this->renderFatal($exception)->send();
        });
    }

    protected function renderFatal(Throwable $e): Response
    {
        try {
            return $this->make(Handler::class)->handle(null, $e);
        } catch (Throwable) {
            return new JsonResponse(['message' => 'Server error.'], 500);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function logQuietly(string $level, string $message, array $context = []): void
    {
        try {
            if ($this->bound(LoggerInterface::class)) {
                $this->make(LoggerInterface::class)->log($level, $message, $context);
            }
        } catch (Throwable) {
            error_log($message);
        }
    }

    private function joinPath(string $base, string $path): string
    {
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}
