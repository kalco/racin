<?php

declare(strict_types=1);

namespace Racin\Queue;

use InvalidArgumentException;
use Racin\Container\Container;
use Racin\Database\DatabaseManager;
use Racin\Redis\RedisFactory;

/**
 * Builds queue connections from config/queue.php.
 *
 * @mixin Queue
 */
final class QueueManager
{
    /** @var array<string, Queue> */
    private array $queues = [];

    /**
     * @param array{default?: string, connections?: array<string, array<string, mixed>>} $config
     */
    public function __construct(private readonly Container $container, private readonly array $config)
    {
    }

    public function connection(?string $name = null): Queue
    {
        $name ??= $this->getDefaultConnection();

        return $this->queues[$name] ??= $this->make($name);
    }

    public function getDefaultConnection(): string
    {
        return (string) ($this->config['default'] ?? 'sync');
    }

    /**
     * Push onto the default connection.
     */
    public function push(Job $job, int $delay = 0, ?string $queue = null): string
    {
        return $this->connection()->push($job, $delay, $queue);
    }

    private function make(string $name): Queue
    {
        $config = $this->config['connections'][$name] ?? throw new InvalidArgumentException("Queue connection [{$name}] is not configured.");

        return match ($driver = (string) ($config['driver'] ?? '')) {
            'sync' => new SyncQueue($this->container),
            'database' => new DatabaseQueue(
                $this->container->make(DatabaseManager::class)->connection($config['connection'] ?? null),
                (string) ($config['table'] ?? 'jobs'),
                (int) ($config['retry_after'] ?? 90),
                (bool) ($config['skip_locked'] ?? true),
            ),
            'redis' => new RedisQueue(
                $this->container->make(RedisFactory::class)->connection((string) ($config['connection'] ?? 'default')),
                (string) ($config['prefix'] ?? 'racin:queue:'),
                (int) ($config['retry_after'] ?? 90),
            ),
            default => throw new InvalidArgumentException("Unsupported queue driver [{$driver}]."),
        };
    }
}
