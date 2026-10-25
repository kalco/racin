<?php

declare(strict_types=1);

namespace Racin\Cache;

use Closure;

/**
 * Cache front-end with key prefixing and remember().
 *
 * Note: a stored null is indistinguishable from a miss.
 */
class Cache
{
    public function __construct(private readonly Store $store, private readonly string $prefix = '')
    {
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->store->get($this->prefix . $key);

        return $value ?? ($default instanceof Closure ? $default() : $default);
    }

    public function has(string $key): bool
    {
        return $this->store->get($this->prefix . $key) !== null;
    }

    /**
     * @param int|null $ttl seconds; null = forever
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->put($this->prefix . $key, $value, $ttl);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->add($this->prefix . $key, $value, $ttl);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value);
    }

    /**
     * Return the cached value, or compute, store and return it.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $value = $this->store->get($this->prefix . $key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->store->increment($this->prefix . $key, $by, $ttl);
    }

    public function decrement(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->store->increment($this->prefix . $key, -$by, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($this->prefix . $key);
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }
}
