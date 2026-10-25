<?php

declare(strict_types=1);

namespace Racin\Auth;

use Racin\Cache\Cache;

/**
 * Revoked token ids ("jti"), kept in the cache until the token would have expired.
 * Use a shared, persistent cache (file or redis) in production.
 */
final class TokenBlacklist
{
    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @param array<string, mixed> $claims the verified token payload
     */
    public function revoke(array $claims): void
    {
        $jti = $claims['jti'] ?? null;

        if (!is_string($jti) || $jti === '') {
            return;
        }

        $ttl = isset($claims['exp']) && is_int($claims['exp']) ? max(1, $claims['exp'] - time() + 60) : null;

        $this->cache->put($this->key($jti), true, $ttl);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function isRevoked(array $claims): bool
    {
        $jti = $claims['jti'] ?? null;

        return is_string($jti) && $this->cache->get($this->key($jti)) === true;
    }

    private function key(string $jti): string
    {
        return 'jwt:revoked:' . hash('sha256', $jti);
    }
}
