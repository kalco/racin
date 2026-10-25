<?php

declare(strict_types=1);

namespace Racin\Queue;

use Racin\Container\Container;

/**
 * Runs jobs immediately in the current process (local development, tests).
 * Exceptions propagate to the caller; there are no retries.
 */
final class SyncQueue implements Queue
{
    public function __construct(private readonly Container $container)
    {
    }

    public function push(Job $job, int $delay = 0, ?string $queue = null): string
    {
        $payload = Payload::create($job);

        Worker::execute($this->container, $payload);

        return $payload['uuid'];
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        return null;
    }

    public function delete(QueuedJob $job): void
    {
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }
}
