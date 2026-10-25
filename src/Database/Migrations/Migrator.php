<?php

declare(strict_types=1);

namespace Racin\Database\Migrations;

use Racin\Database\Connection;
use Racin\Database\DatabaseManager;
use Racin\Database\Schema\Blueprint;
use RuntimeException;
use Throwable;

/**
 * Runs migration files in filename order and records them in the "migrations" table.
 *
 * On SQLite and PostgreSQL each migration runs in a transaction; MySQL/MariaDB
 * commit DDL implicitly, so a failed migration there may leave partial changes.
 */
final class Migrator
{
    public const string TABLE = 'migrations';

    /** @var list<callable(string): void> */
    private array $listeners = [];

    public function __construct(private readonly DatabaseManager $db, private readonly string $path)
    {
    }

    /**
     * @param callable(string): void $listener receives progress lines
     */
    public function onOutput(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Run pending migrations. Returns the names that ran.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->ensureRepository();

        $ran = $this->ranMigrations();
        $pending = array_values(array_diff(array_keys($this->files()), $ran));

        if ($pending === []) {
            $this->emit('Nothing to migrate.');

            return [];
        }

        $batch = $this->lastBatch() + 1;

        foreach ($pending as $name) {
            $this->runMigration($name, 'up', function () use ($name, $batch): void {
                $this->repository()->table(self::TABLE)->insert(['migration' => $name, 'batch' => $batch]);
            });
        }

        return $pending;
    }

    /**
     * Roll back the last $steps batches. Returns the names rolled back.
     *
     * @return list<string>
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $last = $this->lastBatch();

        if ($last === 0) {
            $this->emit('Nothing to roll back.');

            return [];
        }

        $names = array_map('strval', $this->repository()->table(self::TABLE)
            ->where('batch', '>', max(0, $last - max(1, $steps)))
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration'));

        foreach ($names as $name) {
            $this->runMigration($name, 'down', function () use ($name): void {
                $this->repository()->table(self::TABLE)->where('migration', $name)->delete();
            });
        }

        return $names;
    }

    /**
     * Drop every table and migrate from scratch.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $this->repository()->schema()->dropAllTables();
        $this->emit('Dropped all tables.');

        return $this->migrate();
    }

    /**
     * @return list<array{migration: string, ran: bool, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureRepository();
        $batches = $this->repository()->table(self::TABLE)->pluck('batch', 'migration');
        $rows = [];

        foreach (array_keys($this->files()) as $name) {
            $rows[] = ['migration' => $name, 'ran' => isset($batches[$name]), 'batch' => isset($batches[$name]) ? (int) $batches[$name] : null];
        }

        foreach ($batches as $name => $batch) {
            if (!isset($this->files()[$name])) {
                $rows[] = ['migration' => (string) $name . ' (file missing)', 'ran' => true, 'batch' => (int) $batch];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string> migration name => file path, in run order
     */
    public function files(): array
    {
        $files = [];

        foreach (glob(rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }

        ksort($files);

        return $files;
    }

    private function runMigration(string $name, string $direction, callable $record): void
    {
        $file = $this->files()[$name] ?? throw new RuntimeException("Migration file for [{$name}] not found.");
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("[{$file}] must return an instance of " . Migration::class . '.');
        }

        $connection = $this->db->connection($migration->connection);
        $transactional = $connection->driver() !== 'mysql';
        $start = hrtime(true);

        if ($transactional) {
            $connection->beginTransaction();
        }

        try {
            $migration->{$direction}($connection->schema());
            $record();

            if ($transactional) {
                $connection->commit();
            }
        } catch (Throwable $e) {
            if ($transactional) {
                $connection->rollBack();
            }

            throw new RuntimeException(($direction === 'up' ? 'Migrating' : 'Rolling back') . " [{$name}] failed: " . $e->getMessage(), 0, $e);
        }

        $this->emit(sprintf('%s %s (%.1f ms)', $direction === 'up' ? 'Migrated:  ' : 'Rolled back:', $name, (hrtime(true) - $start) / 1e6));
    }

    private function repository(): Connection
    {
        return $this->db->connection();
    }

    private function ensureRepository(): void
    {
        $schema = $this->repository()->schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('migration', 255)->unique();
                $table->integer('batch');
            });
        }
    }

    /**
     * @return list<string>
     */
    private function ranMigrations(): array
    {
        return array_map('strval', $this->repository()->table(self::TABLE)->orderBy('migration')->pluck('migration'));
    }

    private function lastBatch(): int
    {
        return (int) ($this->repository()->table(self::TABLE)->max('batch') ?? 0);
    }

    private function emit(string $line): void
    {
        foreach ($this->listeners as $listener) {
            $listener($line);
        }
    }
}
