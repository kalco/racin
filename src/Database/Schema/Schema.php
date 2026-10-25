<?php

declare(strict_types=1);

namespace Racin\Database\Schema;

use Closure;
use Racin\Database\Connection;

/**
 * Create, change and drop tables portably (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 *     $schema->create('posts', function (Blueprint $table) {
 *         $table->id();
 *         $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 *         $table->string('title', 200);
 *         $table->text('body')->nullable();
 *         $table->boolean('published')->default(false)->index();
 *         $table->timestamps();
 *     });
 */
final class Schema
{
    private readonly Grammar $grammar;

    public function __construct(private readonly Connection $connection)
    {
        $this->grammar = new Grammar($connection);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param Closure(Blueprint): void $callback
     */
    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->run($this->grammar->compileCreate($blueprint));
    }

    /**
     * Add/drop columns and indexes on an existing table.
     *
     * @param Closure(Blueprint): void $callback
     */
    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        // SQLite refuses to drop a column that an index uses: drop those indexes first.
        if ($blueprint->dropColumns !== [] && $this->connection->driver() === 'sqlite') {
            $wrapped = $this->connection->wrap($this->connection->tablePrefix() . $table);

            foreach ($this->connection->select("PRAGMA index_list({$wrapped})") as $index) {
                if (($index['origin'] ?? '') !== 'c') {
                    continue; // automatic indexes (primary key / inline UNIQUE) cannot be dropped this way
                }

                $columns = array_column($this->connection->select('PRAGMA index_info(' . $this->connection->wrap((string) $index['name']) . ')'), 'name');

                if (array_intersect($columns, $blueprint->dropColumns) !== [] && !in_array($index['name'], $blueprint->dropIndexes, true)) {
                    $blueprint->dropIndex((string) $index['name']);
                }
            }
        }

        $this->run($this->grammar->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->connection->statement('DROP TABLE ' . $this->connection->wrapTable($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->statement('DROP TABLE IF EXISTS ' . $this->connection->wrapTable($table));
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->statement('ALTER TABLE ' . $this->connection->wrapTable($from) . ' RENAME TO ' . $this->connection->wrapTable($to));
    }

    public function hasTable(string $table): bool
    {
        return in_array($this->connection->tablePrefix() . $table, $this->getTables(), true);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->getColumns($table), true);
    }

    /**
     * Column names of a table, in table order.
     *
     * @return list<string>
     */
    public function getColumns(string $table): array
    {
        $table = $this->connection->tablePrefix() . $table;

        return match ($this->connection->driver()) {
            'sqlite' => array_column($this->connection->select('PRAGMA table_info(' . $this->connection->wrap($table) . ')'), 'name'),
            'pgsql' => array_column($this->connection->select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
                [$table],
            ), 'column_name'),
            default => array_column($this->connection->select(
                'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                [$table],
            ), 'name'),
        };
    }

    /**
     * Base tables in the current database/schema (with prefix).
     *
     * @return list<string>
     */
    public function getTables(): array
    {
        $rows = match ($this->connection->driver()) {
            'sqlite' => $this->connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
            'pgsql' => $this->connection->select('SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'),
            default => $this->connection->select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"),
        };

        return array_map('strval', array_column($rows, 'name'));
    }

    /**
     * Drop every table in the database. Destructive — used by migrate:fresh.
     */
    public function dropAllTables(): void
    {
        $tables = $this->getTables();
        $driver = $this->connection->driver();

        if ($driver === 'mysql') {
            $this->connection->statement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($driver === 'sqlite') {
            $this->connection->statement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($tables as $table) {
                $this->connection->statement('DROP TABLE IF EXISTS ' . $this->connection->wrap($table) . ($driver === 'pgsql' ? ' CASCADE' : ''));
            }
        } finally {
            if ($driver === 'mysql') {
                $this->connection->statement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($driver === 'sqlite') {
                $this->connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    /**
     * After inserting explicit ids on PostgreSQL, move the identity sequence past MAX(id).
     */
    public function syncSequence(string $table, string $column = 'id'): void
    {
        if ($this->connection->driver() !== 'pgsql') {
            return;
        }

        $wrapped = $this->connection->wrapTable($table);
        $this->connection->statement(sprintf(
            "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(%s) FROM %s), 0) + 1, false)",
            $this->connection->wrap($column),
            $wrapped,
        ), [$this->connection->tablePrefix() . $table, $column]);
    }

    /**
     * The DDL that create() would run, without running it.
     *
     * @param Closure(Blueprint): void $callback
     * @return list<string>
     */
    public function toSql(string $table, Closure $callback, bool $create = true): array
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        return $create ? $this->grammar->compileCreate($blueprint) : $this->grammar->compileAlter($blueprint);
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }
}
