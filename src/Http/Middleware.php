<?php

declare(strict_types=1);

namespace Racin\Http;

use Closure;

/**
 * HTTP middleware.
 *
 * Call $next($request) to continue down the stack, or return a Response to
 * short-circuit. Parameters given as "alias:a,b" are passed as extra string
 * arguments, so implementations may declare additional optional parameters:
 *
 *     public function handle(Request $request, Closure $next, string $max = '60'): Response
 */
interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
