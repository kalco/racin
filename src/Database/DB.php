<?php

declare(strict_types=1);

namespace Racin\Database;

use Closure;
use RuntimeException;

/**
 * Static access to the default database connection.
 *
 * @method static QueryBuilder table(string|Expression $table)
 * @method static list<array<string, mixed>> select(string $sql, array $bindings = [])
 * @method static array<string, mixed>|null selectOne(string $sql, array $bindings = [])
 * @method static mixed scalar(string $sql, array $bindings = [])
 * @method static bool insert(string $sql, array $bindings = [])
 * @method static int update(string $sql, array $bindings = [])
 * @method static int delete(string $sql, array $bindings = [])
 * @method static bool statement(string $sql, array $bindings = [])
 * @method static void unprepared(string $sql)
 * @method static mixed transaction(Closure $callback)
 * @method static void beginTransaction()
 * @method static void commit()
 * @method static void rollBack()
 */
final class DB
{
    private static ?DatabaseManager $manager = null;

    public static function setManager(?DatabaseManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function manager(): DatabaseManager
    {
        return self::$manager ?? throw new RuntimeException('The database manager has not been set. Is the application booted?');
    }

    public static function connection(?string $name = null): Connection
    {
        return self::manager()->connection($name);
    }

    public static function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * @param list<mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::connection()->{$method}(...$arguments);
    }
}
