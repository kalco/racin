<?php

declare(strict_types=1);

namespace Racin\Auth\Middleware;

use Closure;
use Racin\Auth\AuthenticationException;
use Racin\Auth\Authorizable;
use Racin\Auth\AuthorizationException;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;

/**
 * "role:admin,manager" — the user needs at least one of the roles. Run after "auth".
 */
final class RequireRole implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user() ?? throw new AuthenticationException();

        if (!$user instanceof Authorizable || $roles === [] || !$user->hasRole(...$roles)) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
