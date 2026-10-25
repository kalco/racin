<?php

declare(strict_types=1);

namespace Racin\Tests\Seeding;

use PHPUnit\Framework\TestCase;
use Racin\Console\Generators\SampleRows;
use Racin\Database\DatabaseManager;
use Racin\Database\DB;
use Racin\Database\Model;
use Racin\Database\Seeder;
use Racin\Validation\Validator;
use RuntimeException;

final class Article extends Model
{
    protected array $casts = ['id' => 'int', 'published' => 'bool', 'views' => 'int'];

    protected array $rules = [
        'title' => 'required|string|max:20',
        'slug' => 'required|alpha_dash|unique:articles,slug,{id}',
        'published' => 'boolean',
        'views' => 'integer|min:0',
    ];
}

final class FileSeeder extends Seeder
{
    public function __construct(private readonly string $file, private readonly ?string $uniqueBy = null)
    {
    }

    public function run(): void
    {
        $this->seedFromFile(Article::class, $this->file, $this->uniqueBy);
    }
}

final class SeederTest extends TestCase
{
    private DatabaseManager $db;

    private string $dir;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        Model::setConnectionResolver($this->db);
        DB::setManager($this->db);
        Validator::setConnectionResolver(fn () => $this->db->connection());
        $this->db->connection()->unprepared('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, published INTEGER NOT NULL DEFAULT 0, views INTEGER NOT NULL DEFAULT 0, created_at TEXT NULL, updated_at TEXT NULL)');

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-seed-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        Model::setConnectionResolver(null);
        DB::setManager(null);
        Validator::setConnectionResolver(null);
    }

    public function testSeedsJsonThroughTheModelAndSkipsWhenTableHasRows(): void
    {
        $file = $this->write('articles.json', json_encode([
            ['title' => 'First', 'slug' => 'first', 'published' => true, 'views' => 3],
            ['title' => 'Second', 'slug' => 'second', 'published' => false],
        ], JSON_THROW_ON_ERROR));

        $this->seed($file);
        self::assertSame(['first', 'second'], Article::query()->orderBy('id')->pluck('slug'));
        self::assertTrue(Article::findBy('slug', 'first')->published);
        self::assertNotNull(Article::findBy('slug', 'first')->created_at, 'timestamps are set');
        self::assertSame(['  articles: 2 row(s) from articles.json'], $this->lines);

        $this->lines = [];
        $this->seed($file);
        self::assertSame(2, Article::query()->count());
        self::assertStringContainsString('already has rows', $this->lines[0]);
    }

    public function testUniqueByUpdatesInsteadOfDuplicating(): void
    {
        $this->seed($this->write('a.json', '[{"title": "Old", "slug": "a"}]'), 'slug');
        $this->seed($this->write('b.json', '[{"title": "New", "slug": "a"}, {"title": "Other", "slug": "b"}]'), 'slug');

        self::assertSame(['New', 'Other'], Article::query()->orderBy('id')->pluck('title'));
    }

    public function testSeedsCsvWithHeaderRow(): void
    {
        $file = $this->write('articles.csv', "\xEF\xBB\xBFtitle,slug,published,views\nHello,hello,1,10\n\n\"Quoted, title\",quoted,0,\n");

        $this->seed($file);

        $rows = Article::query()->orderBy('id')->get();
        self::assertSame(['Hello', 'Quoted, title'], array_map(static fn (Article $a): string => $a->title, $rows));
        self::assertSame([10, 0], array_map(static fn (Article $a): int => $a->views, $rows), 'an empty cell uses the column default');
    }

    public function testInvalidRowStopsTheSeedWithItsNumberAndRollsBack(): void
    {
        $file = $this->write('bad.json', '[{"title": "Fine", "slug": "fine"}, {"title": "This title is far too long", "slug": "not valid!"}]');

        try {
            $this->seed($file);
            self::fail('Expected an error.');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('bad.json row 2: title: ', $e->getMessage());
            self::assertStringContainsString('slug: ', $e->getMessage());
        }

        self::assertSame(0, Article::query()->count(), 'nothing is kept');
    }

    public function testRejectsMissingOrMalformedFiles(): void
    {
        foreach ([$this->dir . '/missing.json', $this->write('object.json', '{"title": "x"}')] as $file) {
            try {
                $this->seed($file);
                self::fail("Accepted {$file}.");
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSampleRowsPassTheRules(): void
    {
        $rules = [
            'title' => 'required|string|max:8',
            'slug' => 'required|alpha_dash|unique:articles,slug,{id}',
            'published' => 'boolean',
            'views' => 'integer|min:0|max:2',
            'price' => 'required|numeric',
            'email' => 'required|email',
            'status' => 'required|in:draft,live',
            'category_id' => 'required|integer|exists:categories,id',
            'note' => 'nullable|string',
        ];

        $rows = SampleRows::make($rules, 3, static fn (string $table, string $column): array => $table === 'categories' ? [7, 9] : []);

        self::assertSame(
            ['title' => 'Title 1', 'slug' => 'slug-1', 'published' => true, 'views' => 1, 'price' => 9.99, 'email' => 'email1@example.com', 'status' => 'draft', 'category_id' => 7],
            $rows[0],
        );
        self::assertSame(['Title 3', 'slug-3', true, 2, 'draft', 7], [$rows[2]['title'], $rows[2]['slug'], $rows[2]['published'], $rows[2]['views'], $rows[2]['status'], $rows[2]['category_id']]);
        self::assertSame('live', $rows[1]['status']);
        self::assertSame(9, $rows[1]['category_id']);
        self::assertArrayNotHasKey('note', $rows[0], 'optional columns are left out');
        self::assertSame('Title 90', SampleRows::make(['title' => 'required|string|max:8'], 1, start: 901)[0]['title'], 'cut to max length');

        foreach ($rows as $row) {
            unset($rules['slug'], $rules['category_id']);
            self::assertTrue(Validator::make($row, $rules)->passes());
        }
    }

    private function seed(string $file, ?string $uniqueBy = null): void
    {
        (new FileSeeder($file, $uniqueBy))->setOutput(function (string $line): void {
            $this->lines[] = $line;
        })->run();
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->dir . '/' . $name, $content);

        return $this->dir . '/' . $name;
    }
}
