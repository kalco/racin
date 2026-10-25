<?php

declare(strict_types=1);

namespace Racin\Database;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * A lazily opened PDO connection (MySQL / MariaDB / SQLite).
 */
class Connection
{
    protected ?PDO $pdo = null;

    protected int $transactions = 0;

    protected bool $logging = false;

    /** @var list<array{sql: string, bindings: list<mixed>, time: float}> */
    protected array $queryLog = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config, protected string $name = 'default')
    {
        $driver = $config['driver'] ?? 'mysql';

        if (!in_array($driver, ['mysql', 'sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException("Unsupported database driver [{$driver}].");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    /**
     * A connection config value ("charset", "collation", ...), or the whole config.
     */
    public function getConfig(?string $key = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? null);
    }

    public function tablePrefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    public function getPdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function setPdo(?PDO $pdo): void
    {
        $this->pdo = $pdo;
        $this->transactions = 0;
    }

    public function disconnect(): void
    {
        $this->setPdo(null);
    }

    // ---------------------------------------------------------------------
    // Queries
    // ---------------------------------------------------------------------

    public function table(string|Expression $table): QueryBuilder
    {
        return (new QueryBuilder($this))->from($table);
    }

    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * Schema builder (create/alter/drop tables) for this connection.
     */
    public function schema(): Schema\Schema
    {
        return new Schema\Schema($this);
    }

    /**
     * Quote a value as an SQL string literal (for DDL defaults; use bindings for queries).
     */
    public function quoteString(string $value): string
    {
        $escaped = str_replace("'", "''", $value);

        return "'" . ($this->driver() === 'mysql' ? str_replace('\\', '\\\\', $escaped) : $escaped) . "'";
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $statement): array => $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        return $this->run($sql, $bindings, static function (PDOStatement $statement): ?array {
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        });
    }

    /**
     * First column of the first row.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        return $this->run($sql, $bindings, static function (PDOStatement $statement): mixed {
            $value = $statement->fetchColumn();

            return $value === false ? null : $value;
        });
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): bool
    {
        return $this->statement($sql, $bindings);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return int affected rows
     */
    public function update(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return int affected rows
     */
    public function delete(string $sql, array $bindings = []): int
    {
        return $this->affectingStatement($sql, $bindings);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, static fn (): bool => true);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, static fn (PDOStatement $statement): int => $statement->rowCount());
    }

    /**
     * Run raw SQL without bindings (schema files, multiple statements).
     */
    public function unprepared(string $sql): void
    {
        try {
            $this->getPdo()->exec($sql);
        } catch (PDOException $e) {
            throw new QueryException($sql, [], $e);
        }
    }

    public function lastInsertId(): string
    {
        return (string) $this->getPdo()->lastInsertId();
    }

    // ---------------------------------------------------------------------
    // Transactions
    // ---------------------------------------------------------------------

    /**
     * Run a callback in a transaction; commits on success, rolls back on any exception.
     *
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->commit();

        return $result;
    }

    /**
     * Start a transaction; nested calls use savepoints.
     */
    public function beginTransaction(): void
    {
        if ($this->transactions === 0) {
            $this->getPdo()->beginTransaction();
        } else {
            $this->getPdo()->exec('SAVEPOINT trans' . ($this->transactions + 1));
        }

        $this->transactions++;
    }

    public function commit(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        if ($this->transactions === 1) {
            $this->getPdo()->commit();
        } else {
            $this->getPdo()->exec('RELEASE SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions--;
    }

    public function rollBack(): void
    {
        if ($this->transactions === 0) {
            return;
        }

        if ($this->transactions === 1) {
            $this->getPdo()->rollBack();
        } else {
            $this->getPdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactions);
        }

        $this->transactions--;
    }

    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    // ---------------------------------------------------------------------
    // Identifiers
    // ---------------------------------------------------------------------

    /**
     * Quote a column reference: "name", "users.name", "u.*", "name as alias".
     */
    public function wrap(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->value;
        }

        $value = trim($value);

        if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', $value, $match) === 1) {
            return $this->wrap($match[1]) . ' AS ' . $this->wrapSegment($match[2]);
        }

        return implode('.', array_map($this->wrapSegment(...), explode('.', $value)));
    }

    /**
     * Quote a table reference, applying the configured prefix: "users", "users as u".
     */
    public function wrapTable(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return $table->value;
        }

        $table = trim($table);

        if (preg_match('/^(\S+)\s+(?:as\s+)?(\S+)$/i', $table, $match) === 1) {
            return $this->wrapTable($match[1]) . ' AS ' . $this->wrapSegment($match[2]);
        }

        $segments = explode('.', $table);
        $segments[count($segments) - 1] = $this->tablePrefix() . $segments[count($segments) - 1];

        return implode('.', array_map($this->wrapSegment(...), $segments));
    }

    protected function wrapSegment(string $segment): string
    {
        if ($segment === '*') {
            return '*';
        }

        if (preg_match('/^[A-Za-z0-9_$]+$/', $segment) !== 1) {
            throw new InvalidArgumentException("Invalid SQL identifier [{$segment}].");
        }

        $quote = $this->driver() === 'mysql' ? '`' : '"';

        return $quote . $segment . $quote;
    }

    // ---------------------------------------------------------------------
    // Query log
    // ---------------------------------------------------------------------

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /**
     * @return list<array{sql: string, bindings: list<mixed>, time: float}>
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @template T
     * @param array<int|string, mixed> $bindings
     * @param Closure(PDOStatement): T $handler
     * @return T
     */
    protected function run(string $sql, array $bindings, Closure $handler): mixed
    {
        $start = hrtime(true);

        try {
            $statement = $this->getPdo()->prepare($sql);
            $this->bind($statement, $bindings);
            $statement->execute();
            $result = $handler($statement);
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw new QueryException($sql, array_values($bindings), $e);
        }

        if ($this->logging) {
            $this->queryLog[] = ['sql' => $sql, 'bindings' => array_values($bindings), 'time' => (hrtime(true) - $start) / 1e6];
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    protected function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim($key, ':');

            [$value, $type] = match (true) {
                is_int($value) => [$value, PDO::PARAM_INT],
                is_bool($value) => [(int) $value, PDO::PARAM_INT],
                $value === null => [null, PDO::PARAM_NULL],
                $value instanceof \DateTimeInterface => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
                $value instanceof \BackedEnum => [$value->value, is_int($value->value) ? PDO::PARAM_INT : PDO::PARAM_STR],
                is_scalar($value) || $value instanceof \Stringable => [(string) $value, PDO::PARAM_STR],
                default => throw new InvalidArgumentException('Cannot bind a value of type ' . get_debug_type($value) . '.'),
            };

            $statement->bindValue($parameter, $value, $type);
        }
    }

    protected function connect(): PDO
    {
        $config = $this->config;
        $options = ($config['options'] ?? []) + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => (bool) ($config['persistent'] ?? false),
        ];

        try {
            if ($this->driver() === 'sqlite') {
                $pdo = new PDO('sqlite:' . ($config['database'] ?? ':memory:'), null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');

                return $pdo;
            }

            if ($this->driver() === 'pgsql') {
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 5432), $config['database'] ?? '');

                if (!empty($config['sslmode'])) {
                    $dsn .= ';sslmode=' . self::safeName((string) $config['sslmode']);
                }

                $pdo = new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);
                $pdo->exec("SET client_encoding TO 'UTF8'");

                if (!empty($config['schema'])) {
                    $pdo->exec('SET search_path TO "' . self::safeName((string) $config['schema']) . '"');
                }

                if (!empty($config['timezone'])) {
                    $statement = $pdo->prepare('SELECT set_config(\'TimeZone\', ?, false)');
                    $statement->execute([(string) $config['timezone']]);
                }

                return $pdo;
            }

            $charset = self::safeName((string) ($config['charset'] ?? 'utf8mb4'));
            $dsn = isset($config['unix_socket']) && $config['unix_socket'] !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $config['unix_socket'], $config['database'] ?? '', $charset)
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306), $config['database'] ?? '', $charset);

            $pdo = new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

            if (!empty($config['collation'])) {
                $pdo->exec("SET NAMES '{$charset}' COLLATE '" . self::safeName((string) $config['collation']) . "'");
            }

            if (array_key_exists('strict', $config)) {
                $pdo->exec($config['strict']
                    ? "SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
                    : "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
            }

            if (!empty($config['timezone'])) {
                $statement = $pdo->prepare('SET time_zone = ?');
                $statement->execute([(string) $config['timezone']]);
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseException("Could not connect to database [{$this->name}]: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private static function safeName(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid charset/collation name [{$value}].");
        }

        return $value;
    }
}
