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
 * "can:catalog.manage" — the user needs every listed permission. Run after "auth".
 */
final class RequirePermission implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user() ?? throw new AuthenticationException();

        if (!$user instanceof Authorizable || $permissions === []) {
            throw new AuthorizationException();
        }

        foreach ($permissions as $permission) {
            if (!$user->hasPermission($permission)) {
                throw new AuthorizationException();
            }
        }

        return $next($request);
    }
}
