<?php

declare(strict_types=1);

namespace Racin\Database;

use InvalidArgumentException;

/**
 * Creates and caches named connections from config/database.php.
 *
 * @mixin Connection
 */
class DatabaseManager
{
    /** @var array<string, Connection> */
    protected array $connections = [];

    /**
     * @param array{default?: string, connections?: array<string, array<string, mixed>>} $config
     */
    public function __construct(protected array $config)
    {
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->getDefaultConnection();

        if (!isset($this->connections[$name])) {
            $config = $this->config['connections'][$name] ?? throw new InvalidArgumentException("Database connection [{$name}] is not configured.");
            $this->connections[$name] = new Connection($config, $name);
        }

        return $this->connections[$name];
    }

    public function getDefaultConnection(): string
    {
        return (string) ($this->config['default'] ?? 'mysql');
    }

    /**
     * Drop a cached connection (it reconnects on next use).
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultConnection();

        ($this->connections[$name] ?? null)?->disconnect();
        unset($this->connections[$name]);
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
