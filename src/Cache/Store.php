<?php

declare(strict_types=1);

namespace Racin\Cache;

interface Store
{
    public function get(string $key): mixed;

    /**
     * @param int|null $ttl seconds; null = forever
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Store only if the key does not exist yet. Returns true when stored.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Atomically add $by to an integer value (created with $ttl when missing).
     */
    public function increment(string $key, int $by = 1, ?int $ttl = null): int;

    public function forget(string $key): bool;

    public function flush(): bool;
}
