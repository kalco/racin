<?php

declare(strict_types=1);

namespace Racin\Queue;

use Closure;
use Racin\Database\Connection;
use Racin\Database\QueryBuilder;
use Throwable;

/**
 * Jobs that used up their tries, in a "failed_jobs" table:
 * id, uuid, connection, queue, payload, exception, failed_at.
 */
final class FailedJobRepository
{
    /**
     * @param Connection|Closure(): Connection $connection a closure defers connecting until the table is used
     */
    public function __construct(private Connection|Closure $connection, private readonly string $table = 'failed_jobs')
    {
    }

    public function log(string $connection, string $queue, array $payload, Throwable $exception): void
    {
        $this->query()->insert([
            'uuid' => (string) ($payload['uuid'] ?? Payload::uuid()),
            'connection' => $connection,
            'queue' => $queue,
            'payload' => Payload::encode($payload),
            'exception' => mb_substr($exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString(), 0, 60000),
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->query()->orderByDesc('id')->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->query()->where('id', $id)->first();

        return is_array($row) ? $row : null;
    }

    public function forget(int $id): bool
    {
        return $this->query()->where('id', $id)->delete() > 0;
    }

    public function flush(): int
    {
        return $this->query()->whereRaw('1 = 1')->delete();
    }

    private function query(): QueryBuilder
    {
        if ($this->connection instanceof Closure) {
            $this->connection = ($this->connection)();
        }

        return $this->connection->table($this->table);
    }
}
