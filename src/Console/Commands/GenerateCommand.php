<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use InvalidArgumentException;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Generators\Field;
use Racin\Console\Generators\Names;
use Racin\Console\Generators\PostmanFolder;
use Racin\Console\Generators\SampleRows;
use Racin\Console\Generators\Stubs;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\DatabaseManager;
use Racin\Database\Model;
use Racin\Support\Str;

/**
 * Rails-style generators:
 *
 *   php racin g scaffold Post title:string{200} body:text? price:decimal published:boolean user:references cover:image
 *   php racin g model Tag name:string:unique
 *   php racin g controller Reports index show
 *   php racin g migration AddSlugToPosts slug:string:unique
 *   php racin g serializer Post
 *   php racin g seeder Post [--count=10]     database/seeders/PostSeeder.php + data/posts.json (sample rows from the model's rules)
 *   php racin g postman Post                CRUD folder in postman/*.postman_collection.json
 */
final class GenerateCommand extends Command
{
    public const string ROUTE_MARKER = '// racin:resources';

    protected string $name = 'generate';

    protected array $aliases = ['g'];

    protected string $description = 'Generate code: scaffold | resource | model | controller | migration | serializer | seeder | postman';

    protected string $usage = 'g scaffold <Name> field:type[...] | g model <Name> field:type | g controller <Name> action... | g migration <Name> field:type | g serializer <Name> [attribute...] | g seeder <Name> [--count=5] | g postman <Name>  (--force overwrites)';

    private Output $output;

    private bool $force = false;

    public function __construct(private readonly Application $app, private readonly DatabaseManager $db)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $this->output = $output;
        $this->force = $input->hasOption('force');
        $type = $input->argument(0);
        $name = $input->argument(1);
        $rest = array_slice($input->arguments, 2);

        if ($type === null || $name === null) {
            $output->error('Usage: php racin ' . $this->usage);
            $output->line('Field types: ' . implode(', ', Field::TYPES) . '   (suffix ? = nullable, :unique, :index, {args})');

            return self::FAILURE;
        }

        try {
            return match ($type) {
                'scaffold', 'resource' => $this->scaffold(new Names($name), Field::parseAll($rest)),
                'model' => $this->model(new Names($name), Field::parseAll($rest), !$input->hasOption('no-migration')),
                'controller' => $this->controller(new Names($name), $rest),
                'migration' => $this->migration($name, Field::parseAll($rest)),
                'serializer' => $this->serializer(new Names($name), $rest),
                'seeder' => $this->seeder(new Names($name), $this->modelRules(new Names($name)), $this->count($input)),
                'postman' => $this->postman(new Names($name), $this->modelRules(new Names($name)), $this->controllerImages(new Names($name))),
                default => throw new InvalidArgumentException("Unknown generator [{$type}]. Use scaffold, resource, model, controller, migration, serializer, seeder or postman."),
            };
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param list<Field> $fields
     */
    private function scaffold(Names $names, array $fields): int
    {
        $this->ensureApiController();
        $this->model($names, $fields, true);
        $this->write("app/Serializers/{$names->serializer}.php", Stubs::serializer($names, $this->serializerAttributes($fields), array_values(array_filter($fields, static fn (Field $f): bool => $f->isImage()))));
        $this->write("app/Controllers/Api/V1/{$names->controller}.php", Stubs::resourceController($names, $fields));
        $this->write("tests/{$names->pluralStudly}ApiTest.php", Stubs::test($names, $fields));
        $this->addRoute("\$router->resource('{$names->route}', \\App\\Controllers\\Api\\V1\\{$names->controller}::class);");

        $rules = [];

        foreach ($fields as $field) {
            $rules[$field->column()] = $field->rule($names->table);
        }

        $this->seeder($names, $rules, 5);
        $this->postman($names, $rules, array_values(array_map(static fn (Field $f): string => $f->column(), array_filter($fields, static fn (Field $f): bool => $f->isImage()))), optional: true);

        $this->output->line('');
        $this->output->comment('Next steps:');
        $this->output->line('  php racin migrate');
        $this->output->line("  php racin db:seed --class={$names->model}Seeder   (rows from database/seeders/data/{$names->table}.json)");
        $this->output->line("  php racin route:list            (/v1/{$names->route})");
        $this->output->line("  Writes need the \"{$names->table}.manage\" permission (admins have all permissions).");

        return self::SUCCESS;
    }

    /**
     * @param list<Field> $fields
     */
    private function model(Names $names, array $fields, bool $withMigration): int
    {
        if ($withMigration) {
            $this->write($this->migrationPath("create_{$names->table}_table"), Stubs::createMigration($names->table, $fields));
        }

        $this->write("app/Models/{$names->model}.php", Stubs::model($names, $fields));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $actions
     */
    private function controller(Names $names, array $actions): int
    {
        $actions = $actions === [] ? ['index'] : $actions;

        foreach ($actions as $action) {
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $action) !== 1) {
                throw new InvalidArgumentException("Invalid action name [{$action}] (camelCase).");
            }
        }

        $this->ensureApiController();
        $this->write("app/Controllers/Api/V1/{$names->controller}.php", Stubs::plainController($names, $actions));
        $class = "\\App\\Controllers\\Api\\V1\\{$names->controller}::class";

        foreach ($actions as $action) {
            [$method, $uri] = match ($action) {
                'index' => ['get', "/{$names->route}"],
                'show' => ['get', "/{$names->route}/{id:\\d+}"],
                'create' => ['post', "/{$names->route}"],
                'update' => ['patch', "/{$names->route}/{id:\\d+}"],
                'destroy' => ['delete', "/{$names->route}/{id:\\d+}"],
                default => ['get', "/{$names->route}/" . str_replace('_', '-', Str::snake($action))],
            };

            $this->addRoute("\$router->{$method}('{$uri}', [{$class}, '{$action}'])->name('{$names->route}.{$action}');");
        }

        return self::SUCCESS;
    }

    /**
     * @param list<Field> $fields
     */
    private function migration(string $name, array $fields): int
    {
        $snake = Str::snake($name);

        if (preg_match('/^create_(\w+?)(_table)?$/', $snake, $m) === 1) {
            $content = Stubs::createMigration($m[1], $fields);
            $file = "create_{$m[1]}_table";
        } elseif (preg_match('/^add_\w+_to_(\w+)$/', $snake, $m) === 1) {
            $this->requireFields($fields, $name);
            $content = Stubs::addColumnsMigration($m[1], $fields);
            $file = $snake;
        } elseif (preg_match('/^remove_\w+_from_(\w+)$/', $snake, $m) === 1) {
            $this->requireFields($fields, $name);
            $content = Stubs::removeColumnsMigration($m[1], $fields);
            $file = $snake;
        } else {
            $content = Stubs::migration();
            $file = $snake;
        }

        $this->write($this->migrationPath($file), $content);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $attributes
     */
    private function serializer(Names $names, array $attributes): int
    {
        if ($attributes === []) {
            $attributes = $this->tableColumns($names->table) ?: ['id', 'created_at', 'updated_at'];
        }

        foreach ($attributes as $attribute) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $attribute) !== 1) {
                throw new InvalidArgumentException("Invalid attribute name [{$attribute}].");
            }
        }

        $this->write("app/Serializers/{$names->serializer}.php", Stubs::serializer($names, array_values($attributes)));

        return self::SUCCESS;
    }

    /**
     * database/seeders/<Model>Seeder.php + data/<table>.json with rows that pass the model's rules,
     * registered in DatabaseSeeder so "migrate:fresh --seed" includes it.
     *
     * @param array<string, string|list<mixed>> $rules
     */
    private function seeder(Names $names, array $rules, int $count): int
    {
        $rows = SampleRows::make($rules, $count, $this->existingIds(...));
        $uniqueBy = null;

        foreach ($rules as $column => $rule) {
            if (is_string($rule) && str_contains($rule, 'unique:')) {
                $uniqueBy = (string) $column;
                break;
            }
        }

        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->write("database/seeders/data/{$names->table}.json", $json . "\n");
        $this->write("database/seeders/{$names->model}Seeder.php", Stubs::seeder($names, $uniqueBy));
        $this->registerSeeder("{$names->model}Seeder");

        return self::SUCCESS;
    }

    /**
     * A folder with list/create/get/PUT/PATCH/upload/DELETE requests in the project's Postman collection.
     *
     * @param array<string, string|list<mixed>> $rules
     * @param list<string> $images
     */
    private function postman(Names $names, array $rules, array $images, bool $optional = false): int
    {
        $collection = (glob($this->app->basePath('postman/*.postman_collection.json')) ?: [])[0] ?? null;

        if ($collection === null) {
            if ($optional) {
                return self::SUCCESS;
            }

            throw new InvalidArgumentException('No postman/*.postman_collection.json in this project.');
        }

        // Numbered from 901 so unique values do not clash with seeded rows.
        PostmanFolder::add($collection, $names, SampleRows::make($rules, 3, $this->existingIds(...), start: 901), $images);
        $this->output->line('  ' . $this->output->color('postman  ', '32') . '  ' . basename($collection) . ' → folder "' . PostmanFolder::folderName($names) . '"');

        $sample = dirname($collection) . '/' . PostmanFolder::SAMPLE_IMAGE;

        if ($images !== [] && !is_file($sample) && function_exists('imagepng')) {
            $image = imagecreatetruecolor(640, 480);
            imagefilledrectangle($image, 0, 0, 639, 479, (int) imagecolorallocate($image, 40, 120, 200));
            imagepng($image, $sample);
            $this->output->line('  ' . $this->output->color('create   ', '32') . '  postman/' . PostmanFolder::SAMPLE_IMAGE);
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Validation rules of an existing model (limited to the table's columns once it is migrated).
     *
     * @return array<string, string|list<mixed>>
     */
    private function modelRules(Names $names): array
    {
        $class = "App\\Models\\{$names->model}";

        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException("Model {$class} not found. Create it first: php racin g model {$names->model} field:type ...");
        }

        $rules = (fn (): array => $this->rules)->call(new $class());
        $columns = $this->tableColumns($names->table);

        return $columns === [] ? $rules : array_intersect_key($rules, array_flip($columns));
    }

    /**
     * Image fields the generated controller stores ($this->storeImage('photo', ...)).
     *
     * @return list<string>
     */
    private function controllerImages(Names $names): array
    {
        $file = $this->app->basePath("app/Controllers/Api/V1/{$names->controller}.php");

        if (!is_file($file) || preg_match_all("/storeImage\\('([a-z0-9_]+)'/", (string) file_get_contents($file), $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<int|string>
     */
    private function existingIds(string $table, string $column): array
    {
        try {
            return $this->db->connection()->table($table)->orderBy($column)->limit(20)->pluck($column);
        } catch (\Throwable) {
            return [];
        }
    }

    private function count(Input $input): int
    {
        $count = $input->option('count');

        return is_numeric($count) ? max(1, min(1000, (int) $count)) : 5;
    }

    private function registerSeeder(string $class): void
    {
        $file = $this->app->basePath('database/seeders/DatabaseSeeder.php');
        $source = is_file($file) ? (string) file_get_contents($file) : '';

        if (str_contains($source, "{$class}::class")) {
            return;
        }

        $updated = preg_replace_callback(
            '/\$this->call\(([^;]*?)\);/s',
            static fn (array $m): string => '$this->call(' . (trim($m[1]) === '' ? '' : rtrim($m[1], ", \n\t") . ', ') . "{$class}::class);",
            $source,
            1,
            $replaced,
        );

        if ($replaced === 0 || !is_string($updated)) {
            $this->output->comment("  Add {$class}::class to DatabaseSeeder's \$this->call(...) to include it in db:seed.");

            return;
        }

        file_put_contents($file, $updated);
        $this->output->line('  ' . $this->output->color('seeder   ', '32') . "  DatabaseSeeder now calls {$class}");
    }

    /**
     * @param list<Field> $fields
     * @return list<string>
     */
    private function serializerAttributes(array $fields): array
    {
        $attributes = ['id'];

        foreach ($fields as $field) {
            if ($field->isImage()) {
                $attributes[] = $field->name . '_url';
                $attributes[] = $field->name . '_thumb_url';
            } else {
                $attributes[] = $field->column();
            }
        }

        return [...$attributes, 'created_at', 'updated_at'];
    }

    private function ensureApiController(): void
    {
        if (!is_file($this->app->basePath('app/Controllers/ApiController.php'))) {
            $this->write('app/Controllers/ApiController.php', Stubs::apiController());
        }
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->app->basePath($relative);

        if (is_file($path) && !$this->force) {
            throw new InvalidArgumentException("{$relative} already exists (use --force to overwrite).");
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $existed = is_file($path);
        file_put_contents($path, $content);
        $this->output->line('  ' . $this->output->color($existed ? 'overwrite' : 'create   ', $existed ? '33' : '32') . '  ' . $relative);
    }

    private function migrationPath(string $name): string
    {
        $existing = glob($this->app->basePath("database/migrations/*_{$name}.php")) ?: [];

        if ($existing !== [] && !$this->force) {
            throw new InvalidArgumentException('A migration "' . $name . '" already exists: ' . basename($existing[0]));
        }

        return 'database/migrations/' . ($existing !== [] ? basename($existing[0]) : date('Y_m_d_His') . "_{$name}.php");
    }

    private function addRoute(string $line): void
    {
        $file = $this->app->basePath('routes/api.php');
        $routes = is_file($file) ? (string) file_get_contents($file) : "<?php\n\ndeclare(strict_types=1);\n";

        if (str_contains($routes, $line)) {
            $this->output->line('  ' . $this->output->color('identical', '34') . '  routes/api.php');

            return;
        }

        if (str_contains($routes, self::ROUTE_MARKER)) {
            $routes = preg_replace_callback(
                '/^([ \t]*)' . preg_quote(self::ROUTE_MARKER, '/') . '/m',
                static fn (array $m): string => $m[1] . $line . "\n" . $m[1] . self::ROUTE_MARKER,
                $routes,
                1,
            );
        } else {
            $routes = rtrim($routes) . "\n\n\$router->group(['prefix' => 'v1', 'as' => 'v1.'], function (Racin\\Routing\\Router \$router): void {\n    {$line}\n    " . self::ROUTE_MARKER . "\n});\n";
        }

        file_put_contents($file, $routes);
        $this->output->line('  ' . $this->output->color('route    ', '32') . '  ' . $line);
    }

    /**
     * @param list<Field> $fields
     */
    private function requireFields(array $fields, string $name): void
    {
        if ($fields === []) {
            throw new InvalidArgumentException("Migration {$name} needs fields, e.g. slug:string:unique.");
        }
    }

    /**
     * @return list<string>
     */
    private function tableColumns(string $table): array
    {
        try {
            return $this->db->connection()->schema()->getColumns($table);
        } catch (\Throwable) {
            return [];
        }
    }
}
