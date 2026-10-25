<?php

declare(strict_types=1);

namespace Racin\Auth\Middleware;

use Closure;
use Racin\Auth\Guard;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;

/**
 * Requires "Authorization: Bearer <jwt>" (see Auth\Guard). Sets $request->user()
 * and the "token" request attribute (the verified claims).
 */
final class Authenticate implements Middleware
{
    public function __construct(private readonly Guard $guard)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->guard->authenticate($request);

        return $next($request);
    }
}
