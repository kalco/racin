<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use FilesystemIterator;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Generators\Stubs;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Starter files for a new API in the current project (after "composer require rokastudio/racin"):
 *
 *   vendor/bin/racin init [--name="Shop API"] [--url=http://shop.local] [--db=shop] [--force]
 *
 * config/*, public/ (front controller, .htaccess, uploads), .env (+ .env.example, with APP_KEY),
 * routes/api.php, App\Models\User + users migration, register/login/me/logout (AuthController),
 * ApiController, DatabaseSeeder + AdminUserSeeder, tests (in-memory SQLite) and phpunit.xml.
 * Existing files are kept unless --force is given. composer.json gets the App\, Database\Seeders\
 * and Tests\ autoload entries.
 */
final class InitCommand extends Command
{
    protected string $name = 'init';

    protected string $description = 'Create the starter files of a new API project (config, public, .env, auth, tests)';

    protected string $usage = 'init [--name="My API"] [--url=http://my-api.local] [--db=my_api] [--force]';

    private Output $output;

    private bool $force = false;

    /** @var array{created: int, skipped: int} */
    private array $counts = ['created' => 0, 'skipped' => 0];

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $this->output = $output;
        $this->force = $input->hasOption('force');

        $folder = basename($this->app->basePath());
        $name = self::option($input, 'name') ?? ucwords(str_replace(['-', '_', '.'], ' ', (string) preg_replace('/\.(local|test|localhost)$/', '', $folder))) . ' API';
        $slug = Str::slug($name, '_') ?: 'app';
        $values = [
            '{{APP_NAME}}' => $name,
            '{{SLUG}}' => $slug,
            '{{APP_URL}}' => self::option($input, 'url') ?? 'http://localhost:8000',
            '{{DB_NAME}}' => self::option($input, 'db') ?? (string) preg_replace('/[^a-z0-9_]/', '_', strtolower((string) preg_replace('/\.(local|test|localhost)$/', '', $folder))),
        ];

        $output->line("Racin starter files for \"{$name}\" in " . $this->app->basePath());

        $stubs = __DIR__ . '/../Generators/stubs/init';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubs, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stubs) + 1));
            $relative = substr($relative, 0, -strlen('.stub'));

            if (str_contains($relative, '{{DATE}}')) {
                $relative = $this->migrationName($relative);
            }

            $this->write($relative, strtr((string) file_get_contents($file->getPathname()), $values));
        }

        $this->write('app/Controllers/ApiController.php', Stubs::apiController());
        $this->writeEnv();
        $composer = $this->updateComposerJson();

        $output->line('');
        $output->info("{$this->counts['created']} file(s) created, {$this->counts['skipped']} kept (use --force to overwrite).");
        $output->line('');
        $output->comment('Next steps:');

        if ($composer) {
            $output->line('  composer dump-autoload                       (App\\, Database\\Seeders\\, Tests\\ were added to composer.json)');
        }

        $output->line('  composer require --dev phpunit/phpunit       (for the tests)');
        $output->line('  edit .env: DB_* and ADMIN_EMAIL / ADMIN_PASSWORD');
        $output->line('  vendor/bin/racin migrate --seed              (users table + first admin)');
        $output->line('  web server document root: public/   (or: vendor/bin/racin serve)');
        $output->line('  vendor/bin/racin g scaffold Post title:string body:text   (a whole resource)');
        $output->line('  vendor/bin/phpunit');

        return self::SUCCESS;
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->app->basePath($relative);

        if (is_file($path) && !$this->force) {
            $this->counts['skipped']++;
            $this->output->line('  ' . $this->output->color('exists   ', '33') . '  ' . $relative);

            return;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $existed = is_file($path);
        file_put_contents($path, $content);
        $this->counts['created']++;
        $this->output->line('  ' . $this->output->color($existed ? 'overwrite' : 'create   ', $existed ? '33' : '32') . '  ' . $relative);
    }

    /**
     * The users migration keeps its first timestamp, so "init --force" does not add a second one.
     */
    private function migrationName(string $relative): string
    {
        $suffix = substr($relative, strpos($relative, '{{DATE}}') + strlen('{{DATE}}'));
        $existing = glob($this->app->basePath('database/migrations/*' . $suffix)) ?: [];

        return $existing !== []
            ? 'database/migrations/' . basename($existing[0])
            : str_replace('{{DATE}}', date('Y_m_d_His'), $relative);
    }

    /**
     * .env from .env.example with a fresh APP_KEY (an existing .env is never touched).
     */
    private function writeEnv(): void
    {
        $env = $this->app->basePath('.env');

        if (is_file($env)) {
            $this->counts['skipped']++;
            $this->output->line('  ' . $this->output->color('exists   ', '33') . '  .env (run "vendor/bin/racin key:generate" if APP_KEY is empty)');

            return;
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        $content = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, (string) file_get_contents($this->app->basePath('.env.example')), 1);
        file_put_contents($env, $content);
        $this->counts['created']++;
        $this->output->line('  ' . $this->output->color('create   ', '32') . '  .env (with a new APP_KEY)');
    }

    /**
     * Adds the App\, Database\Seeders\ and Tests\ autoload entries. Returns whether composer.json changed.
     */
    private function updateComposerJson(): bool
    {
        $file = $this->app->basePath('composer.json');

        if (!is_file($file)) {
            $this->output->comment('  composer.json not found: add "App\\\\": "app/", "Database\\\\Seeders\\\\": "database/seeders/" (autoload) and "Tests\\\\": "tests/" (autoload-dev).');

            return false;
        }

        $composer = json_decode((string) file_get_contents($file), true);

        if (!is_array($composer)) {
            $this->output->error('  composer.json is not valid JSON; autoload entries not added.');

            return false;
        }

        $before = $composer;
        $composer['autoload']['psr-4'] = ($composer['autoload']['psr-4'] ?? []) + ['App\\' => 'app/', 'Database\\Seeders\\' => 'database/seeders/'];
        $composer['autoload-dev']['psr-4'] = ($composer['autoload-dev']['psr-4'] ?? []) + ['Tests\\' => 'tests/'];

        if ($composer === $before) {
            return false;
        }

        file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->output->line('  ' . $this->output->color('update   ', '32') . '  composer.json (autoload: App\\, Database\\Seeders\\, Tests\\)');

        return true;
    }

    private static function option(Input $input, string $name): ?string
    {
        $value = $input->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
