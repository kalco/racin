<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Application;
use Racin\Auth\Jwt;
use Racin\Auth\UserProvider;
use Racin\Cache\FileStore;
use Racin\Config\Env;
use Racin\Console\Kernel as ConsoleKernel;
use Racin\Console\Output;
use Racin\Http\Request;
use Racin\Http\Response;

final class ArrayUserProvider implements UserProvider
{
    public function retrieveById(int|string $id, array $claims = []): ?array
    {
        return (int) $id === 1 ? ['id' => 1, 'name' => 'Ana'] : null;
    }
}

final class ApplicationTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-app-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/routes', 0777, true);

        $this->writeConfig(false);
        file_put_contents($this->base . '/routes/api.php', <<<'PHP'
            <?php
            use Racin\Http\Request;
            use Racin\Validation\Validator;

            $router->get('/ok', fn () => ['ok' => true]);
            $router->get('/boom', fn () => throw new RuntimeException('secret internals'));
            $router->get('/warning', fn () => [$undefined]);
            $router->post('/validate', fn (Request $r) => $r->validate(['email' => 'required|email']));
            $router->get('/me', fn (Request $r) => ['user' => $r->user()])->middleware('auth');
            $router->get('/limited', fn () => ['ok' => true])->middleware('throttle:2,1');
            $router->get('/stacked', fn () => ['ok' => true])->middleware('throttle:10,1', 'throttle:3,1');
            PHP);
    }

    protected function tearDown(): void
    {
        Env::reset();
        $this->deleteDirectory($this->base);
    }

    private function writeConfig(bool $debug): void
    {
        $config = [
            'app' => [
                'name' => 'Test',
                'env' => 'testing',
                'debug' => $debug,
                'key' => 'base64:' . base64_encode(str_repeat('k', 32)),
                'middleware' => ['cors'],
                'providers' => [],
            ],
            'auth' => ['provider' => ArrayUserProvider::class, 'ttl' => 60],
            'cache' => ['driver' => 'array'],
            'cors' => ['allowed_origins' => ['https://*.example.com'], 'exposed_headers' => ['X-RateLimit-Remaining']],
            'logging' => ['path' => $this->base . '/storage/logs', 'level' => 'debug'],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ];

        foreach ($config as $name => $values) {
            file_put_contents("{$this->base}/config/{$name}.php", '<?php return ' . var_export($values, true) . ';');
        }
    }

    private function app(): Application
    {
        return (new Application($this->base))->handleErrors(false)->bootstrap();
    }

    private function send(Application $app, string $method, string $uri, array $data = [], array $headers = []): Response
    {
        return $app->handle(Request::create($method, $uri, $data, $headers));
    }

    private static function json(Response $response): mixed
    {
        return json_decode($response->content(), true);
    }

    public function testSuccessfulJsonResponse(): void
    {
        $response = $this->send($this->app(), 'GET', '/ok');

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame(['ok' => true], self::json($response));
    }

    public function testServerErrorsHideDetailsUnlessDebugAndAreLogged(): void
    {
        $response = $this->send($this->app(), 'GET', '/boom');

        self::assertSame(500, $response->status());
        self::assertSame(['message' => 'Server error.'], self::json($response));
        self::assertStringContainsString('secret internals', (string) file_get_contents(glob($this->base . '/storage/logs/*.log')[0]));

        $this->writeConfig(true);
        $debug = self::json($this->send($this->app(), 'GET', '/boom'));

        self::assertSame('secret internals', $debug['message']);
        self::assertSame('RuntimeException', $debug['exception']);
        self::assertNotEmpty($debug['trace']);
    }

    public function testPhpWarningsBecomeErrorsWhenHandlerEnabled(): void
    {
        $app = (new Application($this->base))->bootstrap();

        try {
            self::assertSame(500, $this->send($app, 'GET', '/warning')->status());
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function testValidationAndNotFoundShapes(): void
    {
        $app = $this->app();

        $invalid = $this->send($app, 'POST', '/validate', ['email' => 'nope']);
        self::assertSame(422, $invalid->status());
        self::assertSame(['message' => 'The given data was invalid.', 'errors' => ['email' => ['The email must be a valid email address.']]], self::json($invalid));

        $valid = $this->send($app, 'POST', '/validate', ['email' => 'a@b.co', 'extra' => 1]);
        self::assertSame(['email' => 'a@b.co'], self::json($valid));

        self::assertSame(404, $this->send($app, 'GET', '/missing')->status());
    }

    public function testCorsHeadersAreAddedEvenToErrorResponses(): void
    {
        $app = $this->app();

        $allowed = $this->send($app, 'GET', '/missing', [], ['Origin' => 'https://app.example.com']);
        self::assertSame(404, $allowed->status());
        self::assertSame('https://app.example.com', $allowed->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $allowed->getHeader('Vary'));

        $denied = $this->send($app, 'GET', '/ok', [], ['Origin' => 'https://evil.com']);
        self::assertNull($denied->getHeader('Access-Control-Allow-Origin'));

        $preflight = $this->send($app, 'OPTIONS', '/ok', [], [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ]);
        self::assertSame(204, $preflight->status());
        self::assertSame('POST', $preflight->getHeader('Access-Control-Allow-Methods'));
        self::assertSame('authorization, content-type', $preflight->getHeader('Access-Control-Allow-Headers'));
    }

    public function testAuthMiddleware(): void
    {
        $app = $this->app();
        $jwt = $app->make(Jwt::class);

        $missing = $this->send($app, 'GET', '/me');
        self::assertSame(401, $missing->status());
        self::assertSame('Bearer', $missing->getHeader('WWW-Authenticate'));

        $unknownUser = $this->send($app, 'GET', '/me', [], ['Authorization' => 'Bearer ' . $jwt->encode(['sub' => 2])]);
        self::assertSame(401, $unknownUser->status());

        $ok = $this->send($app, 'GET', '/me', [], ['Authorization' => 'Bearer ' . $jwt->encode(['sub' => 1])]);
        self::assertSame(['user' => ['id' => 1, 'name' => 'Ana']], self::json($ok));
    }

    public function testThrottle(): void
    {
        $app = $this->app();

        $statuses = array_map(fn (): int => $this->send($app, 'GET', '/limited')->status(), range(1, 3));
        $blocked = $this->send($app, 'GET', '/limited');

        self::assertSame([200, 200, 429], $statuses);
        self::assertNotNull($blocked->getHeader('Retry-After'));
        self::assertSame('0', $blocked->getHeader('X-RateLimit-Remaining'));

        // Two limiters on one route count independently (each request counts once per limiter).
        $stacked = array_map(fn (): int => $this->send($app, 'GET', '/stacked')->status(), range(1, 4));
        self::assertSame([200, 200, 200, 429], $stacked);
    }

    public function testConsoleCommands(): void
    {
        $app = $this->app();
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $console = new ConsoleKernel($app, new Output($stdout, $stderr, false));

        self::assertSame(0, $console->run(['racin', 'route:list']));
        self::assertSame(0, $console->run(['racin', 'make:controller', 'Admin/report']));
        self::assertSame(1, $console->run(['racin', 'make:controller', '../evil']));
        self::assertSame(1, $console->run(['racin', 'nope']));
        self::assertSame(0, $console->run(['racin', 'config:cache']));
        self::assertFileExists($this->base . '/bootstrap/cache/config.php');
        self::assertSame(0, $console->run(['racin', 'config:clear']));

        rewind($stdout);
        $output = (string) stream_get_contents($stdout);

        self::assertStringContainsString('/limited', $output);
        self::assertFileExists($this->base . '/app/Controllers/Admin/Report.php');
        self::assertStringContainsString('namespace App\Controllers\Admin;', (string) file_get_contents($this->base . '/app/Controllers/Admin/Report.php'));
        self::assertFileDoesNotExist($this->base . '/bootstrap/cache/config.php');
    }

    public function testKeyGenerateWritesEnvAndRefusesToOverwrite(): void
    {
        file_put_contents($this->base . '/.env', "APP_NAME=x\nAPP_KEY=\n");
        $console = new ConsoleKernel($this->app(), new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), false));

        self::assertSame(0, $console->run(['racin', 'key:generate']));
        self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', (string) file_get_contents($this->base . '/.env'));
        self::assertSame(1, $console->run(['racin', 'key:generate']));
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        (new FileStore($directory))->flush();
        @rmdir($directory);
    }
}
