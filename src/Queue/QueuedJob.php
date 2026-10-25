<?php

declare(strict_types=1);

namespace Racin\Queue;

/**
 * A job taken from a queue, with its stored payload.
 */
final class QueuedJob
{
    /**
     * @param array{uuid: string, job: class-string<Job>, data: array<string, mixed>, tries: int, backoff: int|list<int>, pushed_at: int} $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $raw = '',
    ) {
    }

    public function name(): string
    {
        return $this->payload['job'];
    }
}
