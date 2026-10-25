<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use InvalidArgumentException;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Generators\Names;
use Racin\Console\Generators\PostmanFolder;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\Migrations\Migrator;

/**
 * Undo a generator, like "rails destroy":
 *
 *   php racin d scaffold Post      model, migration, serializer, controller, test, route, seeder and Postman folder
 *   php racin d model Post         model and its create migration
 *   php racin d controller Reports
 *   php racin d serializer Post
 *   php racin d seeder Post        seeder, its data file and the DatabaseSeeder call
 *   php racin d postman Post       the Postman folder
 *
 * A migration that has already run is kept (roll it back first) unless --force is given.
 */
final class DestroyCommand extends Command
{
    protected string $name = 'destroy';

    protected array $aliases = ['d'];

    protected string $description = 'Remove generated code: scaffold | resource | model | controller | serializer | seeder | postman';

    protected string $usage = 'd scaffold <Name> | d model <Name> | d controller <Name> | d serializer <Name> | d seeder <Name> | d postman <Name>  (--force also removes migrations that already ran)';

    private Output $output;

    public function __construct(private readonly Application $app, private readonly Migrator $migrator)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $this->output = $output;
        $type = $input->argument(0);
        $name = $input->argument(1);

        if ($type === null || $name === null) {
            $output->error('Usage: php racin ' . $this->usage);

            return self::FAILURE;
        }

        try {
            $names = new Names($name);
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return self::FAILURE;
        }

        $force = $input->hasOption('force');

        match ($type) {
            'scaffold', 'resource' => $this->scaffold($names, $force),
            'model' => $this->model($names, $force),
            'controller' => $this->controller($names),
            'serializer' => $this->remove("app/Serializers/{$names->serializer}.php"),
            'seeder' => $this->seeder($names),
            'postman' => $this->postman($names),
            default => $output->error("Unknown generator [{$type}]."),
        };

        return self::SUCCESS;
    }

    private function scaffold(Names $names, bool $force): void
    {
        $this->model($names, $force);
        $this->remove("app/Serializers/{$names->serializer}.php");
        $this->controller($names);
        $this->remove("tests/{$names->pluralStudly}ApiTest.php");
        $this->seeder($names);
        $this->postman($names);
    }

    private function seeder(Names $names): void
    {
        $class = "{$names->model}Seeder";
        $this->remove("database/seeders/{$class}.php");
        $this->remove("database/seeders/data/{$names->table}.json");

        $file = $this->app->basePath('database/seeders/DatabaseSeeder.php');
        $source = is_file($file) ? (string) file_get_contents($file) : '';
        $name = '(?<![\w\\\\])' . preg_quote($class, '/') . '::class';
        $updated = preg_replace("/,\\s*{$name}|{$name},\\s*/", '', $source, 1, $count);

        if ($count > 0 && is_string($updated)) {
            file_put_contents($file, $updated);
            $this->output->line('  ' . $this->output->color('seeder   ', '31') . "  DatabaseSeeder no longer calls {$class}");
        }
    }

    private function postman(Names $names): void
    {
        foreach (glob($this->app->basePath('postman/*.postman_collection.json')) ?: [] as $collection) {
            if (PostmanFolder::remove($collection, $names)) {
                $this->output->line('  ' . $this->output->color('postman  ', '31') . '  ' . basename($collection) . ' → removed folder "' . PostmanFolder::folderName($names) . '"');
            }
        }
    }

    private function model(Names $names, bool $force): void
    {
        $this->remove("app/Models/{$names->model}.php");

        $ran = array_column(array_filter($this->safeStatus(), static fn (array $row): bool => $row['ran']), 'migration');

        foreach (glob($this->app->basePath("database/migrations/*_create_{$names->table}_table.php")) ?: [] as $file) {
            $migration = basename($file, '.php');

            if (in_array($migration, $ran, true) && !$force) {
                $this->output->comment("  keep       database/migrations/{$migration}.php (already ran: php racin migrate:rollback first, or use --force)");
                continue;
            }

            $this->remove('database/migrations/' . basename($file));
        }
    }

    private function controller(Names $names): void
    {
        $this->remove("app/Controllers/Api/V1/{$names->controller}.php");

        $file = $this->app->basePath('routes/api.php');

        if (!is_file($file)) {
            return;
        }

        $class = "\\App\\Controllers\\Api\\V1\\{$names->controller}::class";
        $lines = explode("\n", (string) file_get_contents($file));
        $kept = array_values(array_filter($lines, static fn (string $line): bool => !str_contains($line, $class)));

        if (count($kept) !== count($lines)) {
            file_put_contents($file, implode("\n", $kept));
            $this->output->line('  ' . $this->output->color('route    ', '31') . '  removed ' . (count($lines) - count($kept)) . " route line(s) for {$names->controller}");
        }
    }

    private function remove(string $relative): void
    {
        $path = $this->app->basePath($relative);

        if (is_file($path)) {
            unlink($path);
            $this->output->line('  ' . $this->output->color('remove   ', '31') . '  ' . $relative);
        }
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    private function safeStatus(): array
    {
        try {
            return $this->migrator->status();
        } catch (\Throwable) {
            return []; // no database: treat every migration as not run
        }
    }
}
