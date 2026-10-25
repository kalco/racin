<?php

declare(strict_types=1);

namespace Racin\Cache;

/**
 * In-memory store; lives for one request/process (tests, local dev).
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['expires'] !== 0 && $item['expires'] <= time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            return $this->forget($key);
        }

        $this->items[$key] = ['value' => $value, 'expires' => $ttl === null ? 0 : time() + $ttl];

        return true;
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $current = $this->get($key);

        if ($current === null) {
            $this->put($key, $by, $ttl);

            return $by;
        }

        $this->items[$key]['value'] = (int) $current + $by;

        return $this->items[$key]['value'];
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }
}
