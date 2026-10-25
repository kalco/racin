<?php

declare(strict_types=1);

namespace Racin\Console\Generators;

use Racin\Support\Str;

/**
 * Source code templates for "php racin generate".
 */
final class Stubs
{
    /**
     * @param list<Field> $fields
     */
    public static function createMigration(string $table, array $fields): string
    {
        $lines = ["\$table->id();"];

        foreach ($fields as $field) {
            $lines[] = $field->migrationLine($table);
        }

        $lines[] = '$table->timestamps();';

        $up = "        \$schema->create('{$table}', function (Blueprint \$table): void {\n" . self::indent($lines, 12) . "\n        });";
        $down = "        \$schema->dropIfExists('{$table}');";

        return self::migration($up, $down);
    }

    /**
     * @param list<Field> $fields
     */
    public static function addColumnsMigration(string $table, array $fields): string
    {
        $lines = array_map(static fn (Field $f): string => $f->migrationLine($table), $fields);
        $columns = implode(', ', array_map(static fn (Field $f): string => var_export($f->column(), true), $fields));

        $up = "        \$schema->table('{$table}', function (Blueprint \$table): void {\n" . self::indent($lines, 12) . "\n        });";
        $down = "        \$schema->table('{$table}', fn (Blueprint \$table) => \$table->dropColumn({$columns}));";

        return self::migration($up, $down);
    }

    /**
     * @param list<Field> $fields
     */
    public static function removeColumnsMigration(string $table, array $fields): string
    {
        $columns = implode(', ', array_map(static fn (Field $f): string => var_export($f->column(), true), $fields));
        $lines = array_map(static fn (Field $f): string => $f->migrationLine($table), $fields);

        $up = "        \$schema->table('{$table}', fn (Blueprint \$table) => \$table->dropColumn({$columns}));";
        $down = "        \$schema->table('{$table}', function (Blueprint \$table): void {\n" . self::indent($lines, 12) . "\n        });";

        return self::migration($up, $down);
    }

    public static function migration(string $up = '        //', string $down = '        //'): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Racin\Database\Migrations\Migration;
            use Racin\Database\Schema\Blueprint;
            use Racin\Database\Schema\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;
    }

    /**
     * @param list<Field> $fields
     */
    public static function model(Names $names, array $fields): string
    {
        $fillable = array_map(static fn (Field $f): string => var_export($f->column(), true), array_values(array_filter($fields, static fn (Field $f): bool => !$f->isImage())));
        $casts = ["'id' => 'int'"];
        $rules = [];
        $properties = [' * @property int $id'];
        $relations = [];
        $uses = ['Racin\Database\Model'];
        $images = array_values(array_filter($fields, static fn (Field $f): bool => $f->isImage()));

        foreach ($fields as $field) {
            if (($cast = $field->cast()) !== null) {
                $casts[] = var_export($field->column(), true) . ' => ' . var_export($cast, true);
            }

            $rules[] = var_export($field->column(), true) . ' => ' . var_export($field->rule($names->table), true) . ',';
            $properties[] = ' * @property ' . self::phpType($field) . ' $' . $field->column();

            if ($field->isReference()) {
                $uses[] = 'Racin\Database\Relations\BelongsTo';
                $relations[] = <<<PHP
                        public function {$field->name}(): BelongsTo
                        {
                            return \$this->belongsTo({$field->referencedModel()}::class, '{$field->column()}');
                        }
                    PHP;
            }
        }

        $properties[] = ' * @property string|null $created_at';
        $properties[] = ' * @property string|null $updated_at';

        if ($images !== []) {
            $uses[] = 'Racin\Image\ImageUploader';
            $deletes = implode("\n", array_map(static fn (Field $f): string => "        app(ImageUploader::class)->delete(\$this->getOriginal('{$f->column()}'));", $images));
            $relations[] = <<<PHP
                    /**
                     * Remove the stored image files with the record.
                     */
                    protected function afterDelete(): void
                    {
                {$deletes}
                    }
                PHP;
        }

        $useLines = implode("\n", array_map(static fn (string $u): string => "use {$u};", array_unique($uses)));
        $fillableList = implode(', ', $fillable);
        $castList = implode(', ', $casts);
        $ruleLines = self::indent($rules, 8);
        $propertyLines = implode("\n", $properties);
        $extra = $relations === [] ? '' : "\n" . implode("\n\n", $relations) . "\n";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Models;

            {$useLines}

            /**
            {$propertyLines}
             */
            final class {$names->model} extends Model
            {
                /** @var list<string> attributes that may be mass assigned */
                protected array \$fillable = [{$fillableList}];

                /** @var array<string, string> */
                protected array \$casts = [{$castList}];

                /** @var array<string, string> validated on every save ("{id}" = this record) */
                protected array \$rules = [
            {$ruleLines}
                ];
            {$extra}}

            PHP;
    }

    /**
     * @param list<string> $attributes
     * @param list<Field> $images
     */
    public static function serializer(Names $names, array $attributes, array $images = []): string
    {
        $uses = ['App\Models\\' . $names->model, 'Racin\Http\Serializer'];
        $methods = [];

        foreach ($images as $image) {
            $uses[] = 'Racin\Image\ImageUploader';
            $camel = Str::camel($image->name);   // the serializer calls camelCase methods for "<name>_url"
            $methods[] = <<<PHP
                    public function {$camel}Url({$names->model} \${$names->variable}): ?string
                    {
                        return storage_url(\${$names->variable}->{$image->column()});
                    }

                    public function {$camel}ThumbUrl({$names->model} \${$names->variable}): ?string
                    {
                        return storage_url(ImageUploader::thumbPath(\${$names->variable}->{$image->column()}));
                    }
                PHP;
        }

        $useLines = implode("\n", array_map(static fn (string $u): string => "use {$u};", array_unique($uses)));
        $attributeList = implode(', ', array_map(static fn (string $a): string => var_export($a, true), $attributes));
        $extra = $methods === [] ? '' : "\n" . implode("\n\n", $methods) . "\n";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Serializers;

            {$useLines}

            /**
             * JSON shape of a {$names->model}. Computed attributes are public methods named after
             * the attribute in camelCase; add relations like ['user' => UserSerializer::class].
             */
            final class {$names->serializer} extends Serializer
            {
                /** @var list<string> */
                protected array \$attributes = [{$attributeList}];

                /** @var array<string, class-string<Serializer>> */
                protected array \$relations = [];
            {$extra}}

            PHP;
    }

    /**
     * @param list<Field> $fields
     */
    public static function resourceController(Names $names, array $fields): string
    {
        $permit = implode(', ', array_map(
            static fn (Field $f): string => var_export($f->column(), true),
            array_values(array_filter($fields, static fn (Field $f): bool => !$f->isImage())),
        ));
        $images = array_values(array_filter($fields, static fn (Field $f): bool => $f->isImage()));
        $v = $names->variable;
        $model = $names->model;

        if ($images === []) {
            $create = "        \${$v} = {$model}::create(\$this->{$v}Params());";
            $update = "        \$this->{$v}->update(\$this->{$v}Params());";
        } else {
            $store = implode("\n", array_map(static fn (Field $f): string => "        \${$v}->{$f->column()} = \$this->storeImage('{$f->column()}', '{$names->table}');", $images));
            $replace = implode(', ', array_map(static fn (Field $f): string => "...\$this->replaceImage(\$this->{$v}, '{$f->column()}', '{$names->table}')", $images));
            $create = "        \${$v} = new {$model}(\$this->{$v}Params());\n{$store}\n        \${$v}->save();";
            $update = "        \$this->{$v}->fill(\$this->{$v}Params());\n        \$replaced = [{$replace}];\n        \$this->{$v}->save();\n        \$this->deleteImages(\$replaced);";
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Controllers\Api\V1;

            use App\Controllers\ApiController;
            use App\Models\\{$model};
            use App\Serializers\\{$names->serializer};
            use Racin\Http\Attributes\SkipBeforeAction;
            use Racin\Http\Response;

            /**
             * /v1/{$names->route} — reading is public, changes need the "{$names->table}.manage" permission
             * (admins have every permission; grant it to other roles in RolesAndPermissionsSeeder).
             */
            #[SkipBeforeAction('authorizeRequest', only: ['index', 'show'])]
            final class {$names->controller} extends ApiController
            {
                private {$model} \${$v};

                public function __construct()
                {
                    // before_action: run in this order before the listed actions
                    \$this->beforeAction(fn () => \$this->checkPermission('{$names->table}', 'manage'), only: ['create', 'update', 'destroy']);
                    \$this->beforeAction('set{$model}', only: ['show', 'update', 'destroy']);
                }

                // GET /v1/{$names->route}?page=&per_page=
                public function index(): Response
                {
                    \${$names->pluralVariable} = {$model}::query()->latest('id')->paginate(\$this->perPage(), \$this->page());

                    return \$this->render(json: \${$names->pluralVariable}, eachSerializer: {$names->serializer}::class);
                }

                // GET /v1/{$names->route}/{id}
                public function show(): Response
                {
                    return \$this->render(json: \$this->{$v}, serializer: {$names->serializer}::class);
                }

                // POST /v1/{$names->route}
                public function create(): Response
                {
            {$create}

                    return \$this->render(json: \${$v}, serializer: {$names->serializer}::class, status: 'created');
                }

                // PATCH /v1/{$names->route}/{id}
                public function update(): Response
                {
            {$update}

                    return \$this->render(json: \$this->{$v}, serializer: {$names->serializer}::class);
                }

                // DELETE /v1/{$names->route}/{id}
                public function destroy(): Response
                {
                    \$this->{$v}->delete();

                    return \$this->head('no_content');
                }

                protected function set{$model}(int \$id): void
                {
                    \$this->{$v} = {$model}::findOrFail(\$id);
                }

                /**
                 * Strong parameters: the only attributes clients may set.
                 *
                 * @return array<string, mixed>
                 */
                private function {$v}Params(): array
                {
                    return \$this->params()->permit({$permit});
                }
            }

            PHP;
    }

    public static function seeder(Names $names, ?string $uniqueBy): string
    {
        $call = $uniqueBy === null
            ? "\$this->seedFromFile({$names->model}::class, __DIR__ . '/data/{$names->table}.json');"
            : "\$this->seedFromFile({$names->model}::class, __DIR__ . '/data/{$names->table}.json', uniqueBy: '{$uniqueBy}');";
        $when = $uniqueBy === null
            ? 'Skipped while the table already has rows.'
            : "Re-runnable: rows are matched by \"{$uniqueBy}\" and updated instead of duplicated.";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Database\Seeders;

            use App\Models\\{$names->model};
            use Racin\Database\Seeder;

            /**
             * {$names->model} rows from data/{$names->table}.json (edit it, or point this at a .csv with a header row).
             * Rows go through the model, so its \$rules are checked. {$when}
             *
             *     php racin db:seed --class={$names->model}Seeder
             */
            final class {$names->model}Seeder extends Seeder
            {
                public function run(): void
                {
                    {$call}
                }
            }

            PHP;
    }

    /**
     * @param list<string> $actions
     */
    public static function plainController(Names $names, array $actions): string
    {
        $methods = [];

        foreach ($actions as $action) {
            $methods[] = <<<PHP
                    public function {$action}(): Response
                    {
                        return \$this->render(json: ['action' => '{$action}']);
                    }
                PHP;
        }

        $body = implode("\n\n", $methods);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Controllers\Api\V1;

            use App\Controllers\ApiController;
            use Racin\Http\Response;

            final class {$names->controller} extends ApiController
            {
            {$body}
            }

            PHP;
    }

    /**
     * Base controller for the app: authentication filter, permissions, pagination and image helpers.
     */
    public static function apiController(): string
    {
        return (string) file_get_contents(__DIR__ . '/stubs/ApiController.php.stub');
    }

    /**
     * @param list<Field> $fields
     */
    public static function test(Names $names, array $fields): string
    {
        $otherReferences = array_values(array_filter($fields, static fn (Field $f): bool => $f->isReference() && $f->referencedTable() !== 'users'));
        $attributes = [];
        $updateField = null;

        foreach ($fields as $field) {
            if ($field->isImage()) {
                continue;
            }

            if ($field->isReference()) {
                $value = $field->referencedTable() === 'users' ? '$admin->id' : '$this->' . Str::camel($field->name) . 'Id()';
                $attributes[] = var_export($field->column(), true) . " => {$value},";
                continue;
            }

            $attributes[] = var_export($field->column(), true) . ' => ' . self::export($field->sample('{$suffix}')) . ',';
            $updateField ??= in_array($field->type, ['string', 'text', 'longtext'], true) && !$field->unique ? $field : null;
        }

        $attributeLines = self::indent($attributes, 12);
        $update = $updateField !== null
            ? "[" . var_export($updateField->column(), true) . " => 'Changed']"
            : '[]';
        $hasRequired = array_filter($fields, static fn (Field $f): bool => !$f->nullable && $f->type !== 'boolean') !== [];
        $helpers = '';

        foreach ($otherReferences as $reference) {
            $method = Str::camel($reference->name) . 'Id';
            $helpers .= <<<PHP

                    /**
                     * TODO: return the id of an existing {$reference->referencedTable()} row, e.g. insert one with DB::table('{$reference->referencedTable()}')->insertGetId([...]).
                     */
                    private function {$method}(): int
                    {
                        self::markTestIncomplete('Provide a {$reference->referencedTable()} record in {$method}().');
                    }

                PHP;
        }

        $validation = $hasRequired
            ? "\n        \$this->assertStatus(422, \$this->request('POST', '/v1/{$names->route}', [], \$admin));"
            : '';

        $image = array_values(array_filter($fields, static fn (Field $f): bool => $f->isImage()))[0] ?? null;

        if ($image !== null) {
            $column = $image->column();
            $helpers .= <<<PHP

                    public function testImageUpload(): void
                    {
                        \$root = sys_get_temp_dir() . '/racin-' . bin2hex(random_bytes(4));
                        \$this->app->config()->set('filesystems.disks.public.root', \$root . '/uploads');
                        \$this->app->config()->set('filesystems.disks.public.url', 'http://test/uploads');
                        mkdir(\$root);
                        \$png = \$root . '/sample.png';
                        imagepng(imagecreatetruecolor(64, 48), \$png);

                        [\$admin, \$auth] = \$this->actingAs('admin@example.com', admin: true);
                        \$request = \Racin\Http\Request::create('POST', '/v1/{$names->route}', \$this->attributes(\$admin), \$auth + ['Accept' => 'application/json'], files: ['{$column}' => \Racin\Http\UploadedFile::fake(\$png)]);
                        \$created = \$this->app->handle(\$request);
                        \$this->assertStatus(201, \$created);
                        \$data = \$this->json(\$created)['data'];

                        \$file = \$root . '/uploads/' . substr((string) \$data['{$column}_url'], strlen('http://test/uploads/'));
                        self::assertFileExists(\$file);
                        self::assertNotNull(\$data['{$column}_thumb_url']);

                        // Deleting the record removes the files.
                        \$this->assertStatus(204, \$this->request('DELETE', "/v1/{$names->route}/{\$data['id']}", [], \$auth));
                        self::assertFileDoesNotExist(\$file);

                        \$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\$root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

                        foreach (\$items as \$item) {
                            \$item->isDir() ? rmdir(\$item->getPathname()) : unlink(\$item->getPathname());
                        }

                        rmdir(\$root);
                    }

                PHP;
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Tests;

            /**
             * Generated by "php racin generate scaffold {$names->model}": adjust to your rules.
             */
            final class {$names->pluralStudly}ApiTest extends ApiTestCase
            {
                /**
                 * @return array<string, mixed>
                 */
                private function attributes(\App\Models\User \$admin, string \$suffix = '1'): array
                {
                    return [
            {$attributeLines}
                    ];
                }

                public function testCrud(): void
                {
                    [\$admin, \$auth] = \$this->actingAs('admin@example.com', admin: true);

                    \$created = \$this->request('POST', '/v1/{$names->route}', \$this->attributes(\$admin), \$auth);
                    \$this->assertStatus(201, \$created);
                    \$id = \$this->json(\$created)['data']['id'];

                    \$this->assertStatus(200, \$this->request('GET', "/v1/{$names->route}/{\$id}"));
                    self::assertSame(1, \$this->json(\$this->request('GET', '/v1/{$names->route}'))['meta']['total']);
                    \$this->assertStatus(200, \$this->request('PATCH', "/v1/{$names->route}/{\$id}", {$update}, \$auth));
                    \$this->assertStatus(204, \$this->request('DELETE', "/v1/{$names->route}/{\$id}", [], \$auth));
                    \$this->assertStatus(404, \$this->request('GET', "/v1/{$names->route}/{\$id}"));
                }

                public function testAccessAndValidation(): void
                {
                    [, \$customer] = \$this->actingAs('customer@example.com');
                    [, \$admin] = \$this->actingAs('admin@example.com', admin: true);

                    \$this->assertStatus(401, \$this->request('POST', '/v1/{$names->route}', []));
                    \$this->assertStatus(403, \$this->request('POST', '/v1/{$names->route}', [], \$customer));{$validation}
                }
            {$helpers}}

            PHP;
    }

    private static function phpType(Field $field): string
    {
        $type = match ($field->type) {
            'integer', 'bigint', 'smallint', 'tinyint', 'references' => 'int',
            'decimal', 'float' => 'float',
            'boolean' => 'bool',
            'json' => 'array',
            default => 'string',
        };

        return $field->nullable ? $type . '|null' : $type;
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            is_array($value) => '[' . implode(', ', array_map(static fn ($k, $v): string => var_export($k, true) . ' => ' . self::export($v), array_keys($value), $value)) . ']',
            is_string($value) && str_contains($value, '{$suffix}') => '"' . addcslashes($value, '"\\') . '"',
            default => var_export($value, true),
        };
    }

    /**
     * @param list<string> $lines
     */
    private static function indent(array $lines, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(static fn (string $line): string => $pad . $line, $lines));
    }
}
