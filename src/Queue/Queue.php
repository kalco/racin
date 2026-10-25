<?php

declare(strict_types=1);

namespace Racin\Queue;

interface Queue
{
    /**
     * Store a job; returns its id.
     *
     * @param int $delay seconds before the job becomes available
     */
    public function push(Job $job, int $delay = 0, ?string $queue = null): string;

    /**
     * Reserve the next available job, or null when the queue is empty.
     */
    public function pop(string $queue = 'default'): ?QueuedJob;

    /**
     * Remove a finished (or permanently failed) job.
     */
    public function delete(QueuedJob $job): void;

    /**
     * Put a reserved job back to be retried after $delay seconds.
     */
    public function release(QueuedJob $job, int $delay = 0): void;

    public function size(string $queue = 'default'): int;
}
