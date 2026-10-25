<?php

declare(strict_types=1);

namespace Racin\Cache;

use Redis;

/**
 * Redis cache store. Integers are stored raw (so INCRBY works); everything else
 * is serialized. flush() only deletes keys with this store's prefix.
 */
final class RedisStore implements Store
{
    private const string SERIALIZED = 'r:';

    public function __construct(private readonly Redis $redis, private readonly string $prefix = 'racin:cache:')
    {
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($this->prefix . $key);

        return $value === false ? null : $this->decode((string) $value);
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            return $this->forget($key);
        }

        $encoded = $this->encode($value);

        return $ttl === null
            ? (bool) $this->redis->set($this->prefix . $key, $encoded)
            : (bool) $this->redis->setex($this->prefix . $key, $ttl, $encoded);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $options = ['nx'];

        if ($ttl !== null && $ttl > 0) {
            $options['ex'] = $ttl;
        }

        return (bool) $this->redis->set($this->prefix . $key, $this->encode($value), $options);
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $full = $this->prefix . $key;

        // Set the expiry only when the key is created, atomically.
        $script = <<<'LUA'
            local value = redis.call('INCRBY', KEYS[1], ARGV[1])
            if value == tonumber(ARGV[1]) and tonumber(ARGV[2]) > 0 then
                redis.call('EXPIRE', KEYS[1], ARGV[2])
            end
            return value
            LUA;

        return (int) $this->redis->eval($script, [$full, $by, $ttl ?? 0], 1);
    }

    public function forget(string $key): bool
    {
        $this->redis->del($this->prefix . $key);

        return true;
    }

    public function flush(): bool
    {
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 500);

            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($iterator > 0);

        return true;
    }

    private function encode(mixed $value): string
    {
        return is_int($value) ? (string) $value : self::SERIALIZED . serialize($value);
    }

    private function decode(string $value): mixed
    {
        if (str_starts_with($value, self::SERIALIZED)) {
            return unserialize(substr($value, strlen(self::SERIALIZED)));
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value;
    }
}
