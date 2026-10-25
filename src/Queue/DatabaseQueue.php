<?php

declare(strict_types=1);

namespace Racin\Queue;

use Racin\Database\Connection;
use Racin\Database\QueryBuilder;

/**
 * Jobs stored in a "jobs" table:
 * id, queue, payload, attempts, reserved_at, available_at, created_at (unix timestamps).
 *
 * Reserved jobs that are not finished within $retryAfter seconds (a crashed
 * worker) become available again.
 */
final class DatabaseQueue implements Queue
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'jobs',
        private readonly int $retryAfter = 90,
        private readonly bool $skipLocked = true,
    ) {
    }

    public function push(Job $job, int $delay = 0, ?string $queue = null): string
    {
        $now = time();

        return (string) $this->connection->table($this->table)->insertGetId([
            'queue' => $queue ?? $job->queue(),
            'payload' => Payload::encode(Payload::create($job)),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now + max(0, $delay),
            'created_at' => $now,
        ]);
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        return $this->connection->transaction(function () use ($queue): ?QueuedJob {
            $now = time();

            $query = $this->connection->table($this->table)
                ->where('queue', $queue)
                ->where(function (QueryBuilder $q) use ($now): void {
                    $q->where(fn (QueryBuilder $q) => $q->whereNull('reserved_at')->where('available_at', '<=', $now))
                        ->orWhere('reserved_at', '<=', $now - $this->retryAfter);
                })
                ->orderBy('id')
                ->limit(1)
                ->lockForUpdate();

            if ($this->skipLocked && $this->connection->driver() !== 'sqlite') {
                $query->skipLocked();
            }

            $row = $query->first();

            if ($row === null) {
                return null;
            }

            $attempts = (int) $row['attempts'] + 1;
            $this->connection->table($this->table)->where('id', $row['id'])->update(['reserved_at' => $now, 'attempts' => $attempts]);

            return new QueuedJob((string) $row['id'], (string) $row['queue'], Payload::decode((string) $row['payload']), $attempts);
        });
    }

    public function delete(QueuedJob $job): void
    {
        $this->connection->table($this->table)->where('id', (int) $job->id)->delete();
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $this->connection->table($this->table)->where('id', (int) $job->id)->update([
            'reserved_at' => null,
            'available_at' => time() + max(0, $delay),
        ]);
    }

    public function size(string $queue = 'default'): int
    {
        return $this->connection->table($this->table)->where('queue', $queue)->count();
    }
}
