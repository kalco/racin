<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Commands\GenerateCommand;
use Racin\Console\Commands\InitCommand;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\DatabaseManager;

final class InitCommandTest extends TestCase
{
    private string $base;

    /** @var resource */
    private $out;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-init-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . 'shop.local';
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/composer.json', "{\n    \"name\": \"me/shop\",\n    \"require\": {\"rokastudio/racin\": \"^1.2\"}\n}\n");
        $this->out = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->base);
        rmdir(dirname($this->base));
    }

    public function testCreatesAWorkingStarterProject(): void
    {
        self::assertSame(Command::SUCCESS, $this->init(['url' => 'http://shop.local']), $this->consoleOutput());

        foreach ([
            'public/index.php', 'public/.htaccess', 'public/uploads/.htaccess', '.env', '.env.example', '.gitignore', 'phpunit.xml',
            'config/app.php', 'config/auth.php', 'config/database.php', 'config/filesystems.php', 'routes/api.php',
            'app/Models/User.php', 'app/Controllers/ApiController.php', 'app/Controllers/Api/V1/AuthController.php',
            'database/seeders/DatabaseSeeder.php', 'database/seeders/AdminUserSeeder.php', 'tests/ApiTestCase.php', 'tests/AuthApiTest.php',
            'storage/logs/.gitignore',
        ] as $file) {
            self::assertFileExists($this->base . '/' . $file);

            if (str_ends_with($file, '.php')) {
                exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -l ' . escapeshellarg($this->base . '/' . $file) . ' 2>&1', $lines, $status);
                self::assertSame(0, $status, implode("\n", $lines));
            }
        }

        self::assertCount(1, glob($this->base . '/database/migrations/*_create_users_table.php') ?: []);

        $env = (string) file_get_contents($this->base . '/.env');
        self::assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $env);
        self::assertStringContainsString('APP_NAME="Shop API"', $env);
        self::assertStringContainsString('APP_URL=http://shop.local', $env);
        self::assertStringContainsString("DB_DATABASE=shop\n", $env);
        self::assertStringContainsString('APP_KEY=' . "\n", (string) file_get_contents($this->base . '/.env.example'), 'the example has no key');
        self::assertStringContainsString("'redis_prefix' => 'shop_api:cache:'", (string) file_get_contents($this->base . '/config/cache.php'));
        self::assertStringContainsString('// racin:resources', (string) file_get_contents($this->base . '/routes/api.php'));

        $composer = json_decode((string) file_get_contents($this->base . '/composer.json'), true);
        self::assertSame(['App\\' => 'app/', 'Database\\Seeders\\' => 'database/seeders/'], $composer['autoload']['psr-4']);
        self::assertSame(['Tests\\' => 'tests/'], $composer['autoload-dev']['psr-4']);
        self::assertSame(['rokastudio/racin' => '^1.2'], $composer['require'], 'the rest is kept');
    }

    public function testRunningAgainKeepsFilesUnlessForced(): void
    {
        $this->init();
        $env = (string) file_get_contents($this->base . '/.env');
        file_put_contents($this->base . '/routes/api.php', '<?php // mine');
        $migrations = glob($this->base . '/database/migrations/*.php') ?: [];

        $this->init();
        self::assertSame('<?php // mine', file_get_contents($this->base . '/routes/api.php'));
        self::assertStringContainsString('0 file(s) created', $this->consoleOutput());

        $this->init(['force' => true]);
        self::assertStringContainsString('// racin:resources', (string) file_get_contents($this->base . '/routes/api.php'));
        self::assertSame($env, file_get_contents($this->base . '/.env'), '.env is never overwritten');
        self::assertSame($migrations, glob($this->base . '/database/migrations/*.php') ?: [], 'no second users migration');
    }

    public function testGeneratorsWorkInTheStarterProject(): void
    {
        $this->init();
        $db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        ftruncate($this->out, 0);
        rewind($this->out);

        $code =(new GenerateCommand(new Application($this->base), $db))->handle(new Input(['scaffold', 'Post', 'title:string']), new Output($this->out, $this->out, false));
        self::assertSame(Command::SUCCESS, $code, $this->consoleOutput());

        self::assertStringContainsString('$this->call(AdminUserSeeder::class, PostSeeder::class);', (string) file_get_contents($this->base . '/database/seeders/DatabaseSeeder.php'));
        self::assertStringContainsString("\$router->resource('posts', \\App\\Controllers\\Api\\V1\\PostsController::class);\n        // racin:resources", (string) file_get_contents($this->base . '/routes/api.php'));
        self::assertStringNotContainsString('app/Controllers/ApiController.php', $this->consoleOutput(), 'the starter ApiController is reused');
    }

    public function testSeederIsRegisteredIntoAnEmptyCallList(): void
    {
        mkdir($this->base . '/database/seeders', 0777, true);
        file_put_contents($this->base . '/database/seeders/DatabaseSeeder.php', "<?php\n\$this->call();\n");
        mkdir($this->base . '/app/Models', 0777, true);

        if (!class_exists('App\Models\InitTestTag')) {
            eval('namespace App\Models; final class InitTestTag extends \Racin\Database\Model { protected array $rules = ["name" => "required|string|max:20"]; }');
        }

        $db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        (new GenerateCommand(new Application($this->base), $db))->handle(new Input(['seeder', 'InitTestTag']), new Output($this->out, $this->out, false));

        self::assertSame("<?php\n\$this->call(InitTestTagSeeder::class);\n", file_get_contents($this->base . '/database/seeders/DatabaseSeeder.php'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function init(array $options = []): int
    {
        ftruncate($this->out, 0);
        rewind($this->out);

        return (new InitCommand(new Application($this->base)))->handle(new Input([], $options), new Output($this->out, $this->out, false));
    }

    private function consoleOutput(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }
}
