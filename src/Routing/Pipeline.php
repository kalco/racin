<?php

declare(strict_types=1);

namespace Racin\Routing;

use Closure;
use Racin\Container\Container;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;
use UnexpectedValueException;

/**
 * Sends a request through a stack of middleware towards a destination.
 */
final class Pipeline
{
    /** @var list<array{0: Middleware|Closure|class-string, 1: list<string>}> */
    private array $pipes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param list<array{0: Middleware|Closure|class-string, 1: list<string>}> $pipes [middleware, parameters] pairs
     */
    public function through(array $pipes): self
    {
        $this->pipes = $pipes;

        return $this;
    }

    /**
     * @param Closure(Request): Response $destination
     */
    public function run(Request $request, Closure $destination): Response
    {
        $stack = array_reduce(
            array_reverse($this->pipes),
            fn (Closure $next, array $pipe): Closure => fn (Request $request): Response => $this->invoke($pipe, $request, $next),
            $destination,
        );

        return $stack($request);
    }

    /**
     * @param array{0: Middleware|Closure|class-string, 1: list<string>} $pipe
     */
    private function invoke(array $pipe, Request $request, Closure $next): Response
    {
        [$middleware, $parameters] = $pipe;

        if (is_string($middleware)) {
            $middleware = $this->container->make($middleware);
        }

        if ($middleware instanceof Closure) {
            $response = $middleware($request, $next, ...$parameters);
        } elseif ($middleware instanceof Middleware) {
            $response = $middleware->handle($request, $next, ...$parameters);
        } else {
            throw new UnexpectedValueException(get_debug_type($middleware) . ' does not implement ' . Middleware::class . '.');
        }

        if (!$response instanceof Response) {
            throw new UnexpectedValueException('Middleware must return a ' . Response::class . ' instance.');
        }

        return $response;
    }
}
