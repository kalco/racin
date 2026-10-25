<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Commands\DestroyCommand;
use Racin\Console\Commands\GenerateCommand;
use Racin\Console\Generators\Field;
use Racin\Console\Generators\Names;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\DatabaseManager;
use Racin\Database\Migrations\Migrator;

/**
 * Runs the generators against an empty app in a temp directory.
 */
final class GeneratorTest extends TestCase
{
    private string $base;

    private DatabaseManager $db;

    /** @var resource */
    private $out;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-gen-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/routes', 0777, true);
        mkdir($this->base . '/database/migrations', 0777, true);
        file_put_contents($this->base . '/routes/api.php', <<<'PHP'
            <?php

            $router->group(['prefix' => 'v1'], function ($router): void {
                $router->get('/ping', fn () => 'pong');

                // racin:resources
            });

            PHP);
        mkdir($this->base . '/database/seeders', 0777, true);
        file_put_contents($this->base . '/database/seeders/DatabaseSeeder.php', <<<'PHP'
            <?php

            namespace Database\Seeders;

            final class DatabaseSeeder extends \Racin\Database\Seeder
            {
                public function run(): void
                {
                    $this->call(RolesSeeder::class);
                }
            }

            PHP);
        mkdir($this->base . '/postman');
        file_put_contents($this->base . '/postman/Test.postman_collection.json', json_encode([
            'info' => ['name' => 'Test', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'item' => [['name' => 'Health', 'item' => []], ['name' => 'Error examples', 'item' => []]],
            'variable' => [['key' => 'baseUrl', 'value' => 'http://x.test']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        $this->out = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->base);
    }

    public function testNames(): void
    {
        $names = new Names('BlogPost');

        self::assertSame(
            ['BlogPost', 'blog_posts', 'blogPost', 'BlogPosts', 'blogPosts', 'BlogPostsController', 'blog-posts', 'BlogPostSerializer'],
            [$names->model, $names->table, $names->variable, $names->pluralStudly, $names->pluralVariable, $names->controller, $names->route, $names->serializer],
        );
        self::assertSame('categories', (new Names('category'))->table);
        self::assertSame('people', (new Names('Person'))->table);
    }

    public function testFieldParsing(): void
    {
        $title = Field::parse('title:string{200}:unique');
        self::assertSame("\$table->string('title', 200)->unique();", $title->migrationLine('posts'));
        self::assertSame('required|string|max:200|unique:posts,title,{id}', $title->rule('posts'));

        $body = Field::parse('body?:text');
        self::assertSame("\$table->text('body')->nullable();", $body->migrationLine('posts'));
        self::assertSame('nullable|string', $body->rule('posts'));

        $price = Field::parse('price:decimal{10,2}');
        self::assertStringContainsString("decimal('price', 10, 2)", $price->migrationLine('posts'));
        self::assertSame('float', $price->cast());

        $user = Field::parse('user:references');
        self::assertSame('user_id', $user->column());
        self::assertSame('users', $user->referencedTable());
        self::assertSame('User', $user->referencedModel());
        self::assertStringContainsString('exists:users,id', $user->rule('posts'));

        self::assertSame('bool', Field::parse('published:boolean')->cast());
        self::assertStringContainsString("in:draft,live", Field::parse('state:enum{draft,live}')->rule('posts'));

        $this->expectException(\InvalidArgumentException::class);
        Field::parse('title:nonsense');
    }

    public function testScaffoldWritesEverythingAndDestroyRemovesIt(): void
    {
        $code = $this->generate(['scaffold', 'Gadget', 'name:string{80}', 'description?:text', 'price:decimal', 'active:boolean', 'owner:references?', 'photo:image']);
        self::assertSame(Command::SUCCESS, $code, $this->consoleOutput());

        $migrations = glob($this->base . '/database/migrations/*_create_gadgets_table.php') ?: [];
        self::assertCount(1, $migrations);

        $files = [
            'app/Models/Gadget.php',
            'app/Serializers/GadgetSerializer.php',
            'app/Controllers/Api/V1/GadgetsController.php',
            'app/Controllers/ApiController.php',
            'tests/GadgetsApiTest.php',
            substr($migrations[0], strlen($this->base) + 1),
        ];

        foreach ($files as $file) {
            self::assertFileExists($this->base . '/' . $file);
            $this->assertValidPhp($this->base . '/' . $file);
        }

        $model = (string) file_get_contents($this->base . '/app/Models/Gadget.php');
        self::assertStringContainsString("protected array \$fillable = ['name', 'description', 'price', 'active', 'owner_id'];", $model);   // images are set by the controller, not mass assignment
        self::assertStringContainsString("'name' => 'required|string|max:80'", $model);
        self::assertStringContainsString('public function owner(): BelongsTo', $model);
        self::assertStringContainsString("return \$this->belongsTo(Owner::class, 'owner_id');", $model);

        $controller = (string) file_get_contents($this->base . '/app/Controllers/Api/V1/GadgetsController.php');
        self::assertStringContainsString("#[SkipBeforeAction('authorizeRequest', only: ['index', 'show'])]", $controller);
        self::assertStringContainsString("\$this->beforeAction('setGadget', only: ['show', 'update', 'destroy']);", $controller);
        self::assertStringContainsString("\$this->checkPermission('gadgets', 'manage'), only: ['create', 'update', 'destroy']", $controller);
        self::assertStringContainsString('eachSerializer: GadgetSerializer::class', $controller);
        self::assertStringContainsString("storeImage('photo'", $controller);

        $serializer = (string) file_get_contents($this->base . '/app/Serializers/GadgetSerializer.php');
        self::assertStringContainsString('public function photoUrl(', $serializer);

        $migration = (string) file_get_contents($migrations[0]);
        self::assertStringContainsString("\$table->foreignId('owner_id')", $migration);
        self::assertStringContainsString("\$table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();", $migration);
        self::assertStringContainsString("\$table->string('photo', 255)->nullable();", $migration);

        $routes = (string) file_get_contents($this->base . '/routes/api.php');
        self::assertStringContainsString("\$router->resource('gadgets', \\App\\Controllers\\Api\\V1\\GadgetsController::class);\n    // racin:resources", $routes);
        $this->assertValidPhp($this->base . '/routes/api.php');

        // Seeder with sample rows that pass the field rules, registered in DatabaseSeeder.
        $this->assertValidPhp($this->base . '/database/seeders/GadgetSeeder.php');
        self::assertStringContainsString("seedFromFile(Gadget::class, __DIR__ . '/data/gadgets.json')", (string) file_get_contents($this->base . '/database/seeders/GadgetSeeder.php'));
        $rows = json_decode((string) file_get_contents($this->base . '/database/seeders/data/gadgets.json'), true);
        self::assertCount(5, $rows);
        self::assertSame(['name' => 'Name 1', 'price' => 9.99, 'active' => true], $rows[0], 'optional fields (description, owner, photo) are left out');
        self::assertStringContainsString('$this->call(RolesSeeder::class, GadgetSeeder::class);', (string) file_get_contents($this->base . '/database/seeders/DatabaseSeeder.php'));

        // Postman folder before "Error examples", with the upload request.
        $collection = json_decode((string) file_get_contents($this->base . '/postman/Test.postman_collection.json'), true);
        self::assertSame(['Health', 'Gadgets', 'Error examples'], array_column($collection['item'], 'name'));
        $requests = array_map(static fn (array $i): string => $i['request']['method'] . ' ' . $i['request']['url']['raw'], $collection['item'][1]['item']);
        self::assertSame([
            'GET {{baseUrl}}/v1/gadgets?page=1&per_page=10',
            'POST {{baseUrl}}/v1/gadgets',
            'GET {{baseUrl}}/v1/gadgets/{{gadgetId}}',
            'PUT {{baseUrl}}/v1/gadgets/{{gadgetId}}',
            'PATCH {{baseUrl}}/v1/gadgets/{{gadgetId}}',
            'POST {{baseUrl}}/v1/gadgets/{{gadgetId}}',
            'DELETE {{baseUrl}}/v1/gadgets/{{gadgetId}}',
            'GET {{baseUrl}}/v1/gadgets/{{gadgetId}}',
        ], $requests);
        self::assertSame(['name' => 'Name 901', 'price' => 9000.99, 'active' => true], json_decode($collection['item'][1]['item'][1]['request']['body']['raw'], true));
        self::assertSame(['_method', 'photo'], array_column($collection['item'][1]['item'][5]['request']['body']['formdata'], 'key'));
        self::assertContains('gadgetId', array_column($collection['variable'], 'key'));
        self::assertFileExists($this->base . '/postman/sample-image.png');

        // Without --force nothing is overwritten.
        self::assertSame(Command::FAILURE, $this->generate(['scaffold', 'Gadget', 'name:string']));
        self::assertStringContainsString('already exists', $this->consoleOutput());

        // "g" again with a different resource keeps the marker for the next one.
        self::assertSame(Command::SUCCESS, $this->generate(['scaffold', 'Widget', 'title:string']));
        self::assertSame(1, substr_count((string) file_get_contents($this->base . '/routes/api.php'), '// racin:resources'));

        self::assertSame(Command::SUCCESS, $this->destroy(['scaffold', 'Gadget']), $this->consoleOutput());

        foreach (['app/Models/Gadget.php', 'app/Serializers/GadgetSerializer.php', 'app/Controllers/Api/V1/GadgetsController.php', 'tests/GadgetsApiTest.php'] as $file) {
            self::assertFileDoesNotExist($this->base . '/' . $file);
        }

        self::assertSame([], glob($this->base . '/database/migrations/*_create_gadgets_table.php') ?: []);
        $routes = (string) file_get_contents($this->base . '/routes/api.php');
        self::assertStringNotContainsString('GadgetsController', $routes);
        self::assertStringContainsString('WidgetsController', $routes);
        self::assertFileExists($this->base . '/app/Controllers/ApiController.php', 'shared base controller stays');

        self::assertFileDoesNotExist($this->base . "/database/seeders/GadgetSeeder.php");
        self::assertFileDoesNotExist($this->base . '/database/seeders/data/gadgets.json');
        self::assertStringContainsString('$this->call(RolesSeeder::class, WidgetSeeder::class);', (string) file_get_contents($this->base . '/database/seeders/DatabaseSeeder.php'));
        $collection = json_decode((string) file_get_contents($this->base . '/postman/Test.postman_collection.json'), true);
        self::assertSame(['Health', 'Widgets', 'Error examples'], array_column($collection['item'], 'name'));
        self::assertNotContains('gadgetId', array_column($collection['variable'], 'key'));
    }

    public function testSeederAndPostmanForAnExistingModel(): void
    {
        if (!class_exists('App\Models\GenTestNote')) {
            eval(<<<'PHP'
                namespace App\Models;

                final class GenTestNote extends \Racin\Database\Model
                {
                    protected array $rules = [
                        'title' => 'required|string|max:40',
                        'code' => 'required|alpha_dash|unique:gen_test_notes,code,{id}',
                        'category_id' => 'required|integer|exists:categories,id',
                        'body' => 'nullable|string',
                    ];
                }
                PHP);
        }

        $this->db->connection()->unprepared('CREATE TABLE categories (id INTEGER PRIMARY KEY); INSERT INTO categories (id) VALUES (4), (6);');

        self::assertSame(Command::SUCCESS, $this->generate(['seeder', 'GenTestNote'], ['count' => '3']), $this->consoleOutput());
        $rows = json_decode((string) file_get_contents($this->base . '/database/seeders/data/gen_test_notes.json'), true);
        self::assertSame([
            ['title' => 'Title 1', 'code' => 'code-1', 'category_id' => 4],
            ['title' => 'Title 2', 'code' => 'code-2', 'category_id' => 6],
            ['title' => 'Title 3', 'code' => 'code-3', 'category_id' => 4],
        ], $rows, 'references use existing ids');
        self::assertStringContainsString("uniqueBy: 'code'", (string) file_get_contents($this->base . '/database/seeders/GenTestNoteSeeder.php'));

        self::assertSame(Command::SUCCESS, $this->generate(['postman', 'GenTestNote']));
        $collection = json_decode((string) file_get_contents($this->base . '/postman/Test.postman_collection.json'), true);
        self::assertSame('GenTestNotes', $collection['item'][1]['name']);
        self::assertCount(7, $collection['item'][1]['item'], 'no upload request without image fields');

        self::assertSame(Command::SUCCESS, $this->destroy(['seeder', 'GenTestNote']));
        self::assertSame(Command::SUCCESS, $this->destroy(['postman', 'GenTestNote']));
        self::assertFileDoesNotExist($this->base . '/database/seeders/GenTestNoteSeeder.php');
        self::assertStringContainsString('$this->call(RolesSeeder::class);', (string) file_get_contents($this->base . '/database/seeders/DatabaseSeeder.php'));
        self::assertSame(['Health', 'Error examples'], array_column(json_decode((string) file_get_contents($this->base . '/postman/Test.postman_collection.json'), true)['item'], 'name'));

        self::assertSame(Command::FAILURE, $this->generate(['seeder', 'NoSuchModel']));
        self::assertStringContainsString('Model App\Models\NoSuchModel not found', $this->consoleOutput());
    }

    public function testMigrationGenerators(): void
    {
        self::assertSame(Command::SUCCESS, $this->generate(['migration', 'AddSlugToPosts', 'slug:string:unique']));
        self::assertSame(Command::SUCCESS, $this->generate(['migration', 'RemoveLegacyFromPosts', 'legacy:string']));
        self::assertSame(Command::SUCCESS, $this->generate(['migration', 'CreateTagsTable', 'name:string']));

        $add = (string) file_get_contents((glob($this->base . '/database/migrations/*_add_slug_to_posts.php') ?: [''])[0]);
        self::assertStringContainsString("\$schema->table('posts'", $add);
        self::assertStringContainsString("\$table->string('slug', 255)->unique();", $add);
        self::assertStringContainsString("dropColumn('slug')", $add);

        $remove = (string) file_get_contents((glob($this->base . '/database/migrations/*_remove_legacy_from_posts.php') ?: [''])[0]);
        self::assertStringContainsString("dropColumn('legacy')", $remove);

        foreach (glob($this->base . '/database/migrations/*.php') ?: [] as $file) {
            $this->assertValidPhp($file);
        }

        self::assertSame(Command::FAILURE, $this->generate(['migration', 'AddNothingToPosts']));
    }

    public function testControllerAndModelWithoutMigration(): void
    {
        self::assertSame(Command::SUCCESS, $this->generate(['controller', 'Reports', 'index', 'monthlySales']));
        self::assertSame(Command::SUCCESS, $this->generate(['model', 'Note', 'body:text'], ['no-migration' => true]));

        $routes = (string) file_get_contents($this->base . '/routes/api.php');
        self::assertStringContainsString("'/reports/monthly-sales'", $routes);
        self::assertStringContainsString("'reports.monthlySales'", $routes);
        self::assertFileExists($this->base . '/app/Models/Note.php');
        self::assertSame([], glob($this->base . '/database/migrations/*notes*') ?: []);
        $this->assertValidPhp($this->base . '/app/Controllers/Api/V1/ReportsController.php');

        self::assertSame(Command::FAILURE, $this->generate(['controller', 'Bad', 'not-valid']));
        self::assertSame(Command::FAILURE, $this->generate(['wizard', 'Thing']));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $options
     */
    private function generate(array $arguments, array $options = []): int
    {
        return (new GenerateCommand($this->app(), $this->db))->handle(new Input($arguments, $options), new Output($this->out, $this->out, false));
    }

    /**
     * @param list<string> $arguments
     */
    private function destroy(array $arguments): int
    {
        $migrator = new Migrator($this->db, $this->base . '/database/migrations');

        return (new DestroyCommand($this->app(), $migrator))->handle(new Input($arguments), new Output($this->out, $this->out, false));
    }

    private function app(): Application
    {
        return new Application($this->base);
    }

    private function consoleOutput(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function assertValidPhp(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -l ' . escapeshellarg($file) . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
    }
}
