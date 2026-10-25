<?php

declare(strict_types=1);

namespace Racin\Http\Middleware;

use Closure;
use Racin\Auth\Guard;
use Racin\Cache\Cache;
use Racin\Http\Exceptions\TooManyRequestsHttpException;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;

/**
 * Fixed-window rate limiting: "throttle:60,1" = 60 requests per 1 minute,
 * counted per authenticated user (or per IP) and per route.
 */
final class ThrottleRequests implements Middleware
{
    public function __construct(private readonly Cache $cache, private readonly ?Guard $guard = null)
    {
    }

    public function handle(Request $request, Closure $next, string $maxAttempts = '60', string $decayMinutes = '1'): Response
    {
        $max = max(1, (int) $maxAttempts);
        $decay = max(1, (int) round((float) $decayMinutes * 60));
        // The limit is part of the key so stacked limiters (group + route) keep separate counters.
        $key = 'throttle:' . sha1($this->identity($request) . '|' . $this->routeIdentity($request) . "|{$max}/{$decay}");

        $this->cache->add($key . ':timer', time() + $decay, $decay);
        $resetAt = (int) $this->cache->get($key . ':timer', time() + $decay);
        $hits = $this->cache->increment($key, 1, $decay);
        $retryAfter = max(1, $resetAt - time());

        if ($hits > $max) {
            throw new TooManyRequestsHttpException($retryAfter, [
                'X-RateLimit-Limit' => (string) $max,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $resetAt,
            ]);
        }

        $response = $next($request);

        return $response
            ->header('X-RateLimit-Limit', (string) $max)
            ->header('X-RateLimit-Remaining', (string) max(0, $max - $hits));
    }

    private function identity(Request $request): string
    {
        // Controllers authenticate in a filter, after middleware: read the token here too.
        $user = $request->user() ?? $this->guard?->user($request);

        if (is_object($user) && method_exists($user, 'getKey') && $user->getKey() !== null) {
            return 'user:' . $user->getKey();
        }

        return 'ip:' . ($request->ip() ?? 'unknown');
    }

    private function routeIdentity(Request $request): string
    {
        $route = $request->route();

        return $route === null ? $request->path() : implode('|', $route->methods()) . ' ' . $route->uri();
    }
}
