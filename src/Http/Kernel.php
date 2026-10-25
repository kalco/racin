<?php

declare(strict_types=1);

namespace Racin\Http;

use Racin\Container\Container;
use Racin\Exceptions\Handler;
use Racin\Routing\Pipeline;
use Racin\Routing\Router;
use Throwable;

/**
 * Request -> global middleware -> router (route middleware -> action) -> Response.
 *
 * Exceptions thrown by routes are rendered *inside* the global middleware
 * stack, so error responses still get CORS and similar headers.
 */
class Kernel
{
    /**
     * @param list<string> $middleware global middleware (aliases or class names)
     */
    public function __construct(
        protected Container $container,
        protected Router $router,
        protected Handler $handler,
        protected array $middleware = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);

        try {
            return (new Pipeline($this->container))
                ->through($this->router->resolveMiddleware($this->middleware))
                ->run($request, function (Request $request): Response {
                    try {
                        return $this->router->dispatch($request);
                    } catch (Throwable $e) {
                        return $this->handler->handle($request, $e);
                    }
                });
        } catch (Throwable $e) {
            return $this->handler->handle($request, $e);
        }
    }

    public function router(): Router
    {
        return $this->router;
    }
}
