<?php

declare(strict_types=1);

namespace Racin\Redis;

use Redis;
use RuntimeException;

/**
 * Creates phpredis connections from config/database.php "redis" entries.
 */
final class RedisFactory
{
    /** @var array<string, Redis> */
    private array $connections = [];

    /**
     * @param array<string, array<string, mixed>> $config name => {host, port, password, database, timeout, prefix}
     */
    public function __construct(private readonly array $config)
    {
    }

    public function connection(string $name = 'default'): Redis
    {
        return $this->connections[$name] ??= self::connect(
            $this->config[$name] ?? throw new RuntimeException("Redis connection [{$name}] is not configured."),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): Redis
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The "redis" PHP extension is required for Redis connections.');
        }

        $redis = new Redis();

        try {
            $redis->connect((string) ($config['host'] ?? '127.0.0.1'), (int) ($config['port'] ?? 6379), (float) ($config['timeout'] ?? 2.0));

            if (($config['password'] ?? '') !== '' && $config['password'] !== null) {
                $redis->auth($config['username'] ?? null ? [$config['username'], $config['password']] : $config['password']);
            }

            if ((int) ($config['database'] ?? 0) !== 0) {
                $redis->select((int) $config['database']);
            }

            // SCAN keeps going on empty batches instead of returning false early.
            $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        } catch (\RedisException $e) {
            throw new RuntimeException('Could not connect to Redis: ' . $e->getMessage(), 0, $e);
        }

        return $redis;
    }

    public function disconnect(string $name = 'default'): void
    {
        if (isset($this->connections[$name])) {
            try {
                $this->connections[$name]->close();
            } catch (\RedisException) {
            }

            unset($this->connections[$name]);
        }
    }
}
