<?php

declare(strict_types=1);

namespace Racin\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Racin\Database\Connection;
use Racin\Database\DatabaseManager;
use Racin\Database\Expression;
use Racin\Database\Model;
use Racin\Database\ModelNotFoundException;
use Racin\Database\QueryBuilder;
use Racin\Database\QueryException;
use RuntimeException;

final class Product extends Model
{
    protected array $fillable = ['name', 'price', 'active', 'tags', 'category_id'];

    protected array $hidden = ['secret'];

    protected array $casts = ['price' => 'float', 'active' => 'bool', 'tags' => 'array'];
}

final class ProductCategory extends Model
{
    protected bool $timestamps = false;
}

final class DatabaseTest extends TestCase
{
    private DatabaseManager $db;

    protected function setUp(): void
    {
        $this->db = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        Model::setConnectionResolver($this->db);

        $this->db->connection()->unprepared(<<<'SQL'
            CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL);
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NULL,
                name TEXT NOT NULL UNIQUE,
                price REAL NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                tags TEXT NULL,
                secret TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            );
            SQL);

        $this->table()->insert([
            ['name' => 'Apple', 'price' => 1.5, 'active' => 1, 'category_id' => 1],
            ['name' => 'Banana', 'price' => 0.5, 'active' => 1, 'category_id' => 1],
            ['name' => 'Cherry', 'price' => 4.0, 'active' => 0, 'category_id' => 2],
            ['name' => 'Durian', 'price' => 9.0, 'active' => 1, 'category_id' => null],
        ]);
        $this->db->connection()->table('product_categories')->insert([['title' => 'Fruit'], ['title' => 'Exotic']]);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver(null);
    }

    private function table(): QueryBuilder
    {
        return $this->db->connection()->table('products');
    }

    public function testWhereVariants(): void
    {
        self::assertSame(['Apple', 'Banana'], $this->table()->where('active', 1)->where('price', '<', 2)->orderBy('name')->pluck('name'));
        self::assertSame(['Apple', 'Banana', 'Durian'], $this->table()->where(['active' => 1])->orderBy('id')->pluck('name'));
        self::assertSame(['Durian'], $this->table()->whereNull('category_id')->pluck('name'));
        self::assertSame(['Banana', 'Cherry'], $this->table()->whereIn('name', ['Banana', 'Cherry'])->orderBy('name')->pluck('name'));
        self::assertSame([], $this->table()->whereIn('name', [])->pluck('name'));
        self::assertSame(4, $this->table()->whereNotIn('name', [])->count());
        self::assertSame(['Apple', 'Cherry'], $this->table()->whereBetween('price', [1, 5])->orderBy('id')->pluck('name'));
        self::assertSame(['Cherry'], $this->table()->where('name', 'like', 'Ch%')->pluck('name'));
        self::assertSame(['Durian'], $this->table()->where('category_id', null)->pluck('name'));
    }

    public function testNestedOrConditionsAreGrouped(): void
    {
        $query = $this->table()
            ->where('active', 1)
            ->where(fn (QueryBuilder $q) => $q->where('price', '>', 5)->orWhere('name', 'Apple'))
            ->orderBy('name');

        self::assertSame(['Apple', 'Durian'], $query->pluck('name'));
        self::assertSame(
            'SELECT * FROM "products" WHERE "active" = ? AND ("price" > ? OR "name" = ?) ORDER BY "name" ASC',
            $query->toSql(),
        );
        self::assertSame([1, 5, 'Apple'], $query->getBindings());
    }

    public function testMysqlQuotingWithoutConnecting(): void
    {
        $mysql = new Connection(['driver' => 'mysql', 'prefix' => 'app_']);

        self::assertSame(
            'SELECT `u`.`id`, `u`.`name` AS `n` FROM `app_users` AS `u` LEFT JOIN `app_posts` AS `p` ON `p`.`user_id` = `u`.`id` WHERE `u`.`id` IN (?, ?) LIMIT 10 OFFSET 20 FOR UPDATE',
            $mysql->table('users as u')
                ->select('u.id', 'u.name as n')
                ->leftJoin('posts as p', 'p.user_id', '=', 'u.id')
                ->whereIn('u.id', [1, 2])
                ->forPage(3, 10)
                ->lockForUpdate()
                ->toSql(),
        );
    }

    public function testRejectsInjectionInIdentifiersAndOperators(): void
    {
        try {
            $this->table()->where('name; DROP TABLE products', 'x')->get();
            self::fail('Expected identifier rejection');
        } catch (InvalidArgumentException) {
        }

        try {
            $this->table()->orderBy('name', 'desc; --');
            self::fail('Expected direction rejection');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->table()->where('name', 'OR 1=1 --', 'x');
    }

    public function testValuesAreBoundNotInterpolated(): void
    {
        $evil = "x' OR '1'='1";

        self::assertSame(0, $this->table()->where('name', $evil)->count());
        self::assertSame(0, $this->table()->whereIn('name', [$evil])->count());
    }

    public function testAggregatesAndPagination(): void
    {
        self::assertSame(4, $this->table()->count());
        self::assertEqualsWithDelta(15.0, $this->table()->sum('price'), 0.001);
        self::assertEqualsWithDelta(3.75, $this->table()->avg('price'), 0.001);
        self::assertEqualsWithDelta(9.0, $this->table()->max('price'), 0.001);
        self::assertSame(2, $this->table()->select('category_id')->whereNotNull('category_id')->groupBy('category_id')->count());
        self::assertTrue($this->table()->where('name', 'Apple')->exists());
        self::assertSame('Banana', $this->table()->where('price', 0.5)->value('name'));
        self::assertSame([1 => 'Apple', 2 => 'Banana'], $this->table()->where('category_id', 1)->pluck('name', 'id'));

        $page = $this->table()->orderBy('id')->paginate(3, 2);

        self::assertSame(4, $page->total);
        self::assertSame(2, $page->lastPage);
        self::assertSame(['Durian'], array_column($page->items, 'name'));
        self::assertSame(['current_page' => 2, 'per_page' => 3, 'total' => 4, 'last_page' => 2], $page->jsonSerialize()['meta']);
    }

    public function testJoins(): void
    {
        $rows = $this->db->connection()->table('products as p')
            ->join('product_categories as c', 'c.id', '=', 'p.category_id')
            ->select('p.name', 'c.title')
            ->where('c.title', 'Exotic')
            ->get();

        self::assertSame([['name' => 'Cherry', 'title' => 'Exotic']], $rows);
    }

    public function testWritesAndGuards(): void
    {
        $id = $this->table()->insertGetId(['name' => 'Elderberry', 'price' => 3]);

        self::assertSame(5, $id);
        self::assertSame(1, $this->table()->where('id', $id)->update(['price' => 3.5]));
        self::assertSame(1, $this->table()->where('id', $id)->increment('price', 2));
        self::assertEqualsWithDelta(5.5, $this->table()->where('id', $id)->value('price'), 0.001);
        self::assertSame(1, $this->table()->where('id', $id)->update(['price' => new Expression('"price" * 2')]));
        self::assertEqualsWithDelta(11.0, $this->table()->where('id', $id)->value('price'), 0.001);
        self::assertSame(1, $this->table()->where('id', $id)->delete());

        try {
            $this->table()->update(['price' => 0]);
            self::fail('Expected guard');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $this->table()->delete();
    }

    public function testUniqueViolationIsDetected(): void
    {
        try {
            $this->table()->insert(['name' => 'Apple']);
            self::fail('Expected QueryException');
        } catch (QueryException $e) {
            self::assertTrue($e->isUniqueViolation());
            self::assertStringNotContainsString("'Apple'", $e->getMessage());
        }
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function (Connection $c): void {
            $c->table('products')->insert(['name' => 'Outer']);

            try {
                $c->transaction(function (Connection $c): void {
                    $c->table('products')->insert(['name' => 'Inner']);

                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame(0, $connection->transactionLevel());
        self::assertTrue($this->table()->where('name', 'Outer')->exists());
        self::assertFalse($this->table()->where('name', 'Inner')->exists());
    }

    public function testModelCrudCastsAndSerialization(): void
    {
        $product = Product::create(['name' => 'Fig', 'price' => '2.25', 'active' => false, 'tags' => ['sweet'], 'secret' => 'ignored']);

        self::assertTrue($product->exists);
        self::assertIsInt($product->getKey());
        self::assertNull($product->secret, 'secret is not fillable');
        self::assertNotNull($product->created_at);

        $fresh = Product::findOrFail($product->getKey());
        self::assertSame(2.25, $fresh->price);
        self::assertFalse($fresh->active);
        self::assertSame(['sweet'], $fresh->tags);
        self::assertArrayNotHasKey('secret', $fresh->toArray());

        $fresh->active = 'false';
        self::assertFalse($fresh->active, 'the string "false" casts to false');
        $fresh->active = '1';
        self::assertTrue($fresh->active);
        $fresh->active = false;

        $fresh->price = 3;
        self::assertSame(['price' => 3], $fresh->getDirty());
        $fresh->save();
        self::assertSame(3.0, Product::find($product->getKey())?->price);

        self::assertTrue($fresh->delete());
        self::assertNull(Product::find($product->getKey()));
    }

    public function testModelQueriesReturnModels(): void
    {
        $active = Product::where('active', 1)->orderBy('price', 'desc')->get();
        self::assertContainsOnlyInstancesOf(Product::class, $active);
        self::assertSame('Durian', $active[0]->name);

        $page = Product::query()->orderBy('id')->paginate(2);
        self::assertInstanceOf(Product::class, $page->items[0]);
        self::assertSame('Apple', $page->jsonSerialize()['data'][0]['name']);

        self::assertSame(1, Product::destroy(1));
        self::assertSame(3, Product::count());
        self::assertSame('product_categories', (new ProductCategory())->getTable());
        self::assertSame('Fruit', ProductCategory::find(1)?->title);
    }

    public function testFindOrFailThrows404(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Product [999] not found.');

        Product::findOrFail(999);
    }
}
