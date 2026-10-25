<?php

declare(strict_types=1);

namespace Racin\Routing;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Racin\Container\ArgumentTypeException;
use Racin\Container\Container;
use Racin\Http\Controller;
use Racin\Http\Exceptions\MethodNotAllowedHttpException;
use Racin\Http\Exceptions\NotFoundHttpException;
use Racin\Http\JsonResponse;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Routing\Attributes\Middleware as MiddlewareAttribute;
use Racin\Routing\Attributes\Prefix;
use Racin\Routing\Attributes\Route as RouteAttribute;
use Racin\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

class Router
{
    public const array VERBS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<Route> */
    protected array $routes = [];

    /** @var array<string, array<string, Route>> method => path => route, for parameterless routes */
    protected array $staticRoutes = [];

    /** @var list<array{prefix: string, middleware: list<string>, as: string}> */
    protected array $groupStack = [];

    /** @var array<string, class-string|Closure> */
    protected array $middlewareAliases = [];

    /** @var array<string, list<string>> */
    protected array $middlewareGroups = [];

    public function __construct(protected Container $container)
    {
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    public function get(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    public function post(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['POST'], $uri, $action);
    }

    public function put(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['PUT'], $uri, $action);
    }

    public function patch(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['DELETE'], $uri, $action);
    }

    public function options(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(['OPTIONS'], $uri, $action);
    }

    public function any(string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute(self::VERBS, $uri, $action);
    }

    /**
     * @param list<string> $methods
     */
    public function match(array $methods, string $uri, Closure|array|string $action): Route
    {
        return $this->addRoute($methods, $uri, $action);
    }

    /**
     * RESTful routes for a controller, like Rails' "resources :posts":
     *
     *   GET    /posts        index      POST   /posts        create
     *   GET    /posts/{id}   show       PATCH  /posts/{id}   update (PUT too)
     *   DELETE /posts/{id}   destroy
     *
     * Dotted names nest: resource('products.images', ...) → /products/{product_id}/images/{id}.
     * Route names are "<name>.<action>" (plus the group's "as" prefix).
     *
     * @param list<string> $only
     * @param list<string> $except
     * @param string|list<string> $middleware
     * @return list<Route>
     */
    public function resource(string $name, string $controller, array $only = [], array $except = [], string|array $middleware = []): array
    {
        $segments = explode('.', $name);
        $last = array_pop($segments);
        $base = '';

        foreach ($segments as $parent) {
            $base .= '/' . $parent . '/{' . Str::snake(Str::singular($parent)) . '_id:\d+}';
        }

        $base .= '/' . $last;
        $actions = [
            'index' => [['GET', 'HEAD'], $base],
            'create' => [['POST'], $base],
            'show' => [['GET', 'HEAD'], $base . '/{id:\d+}'],
            'update' => [['PATCH', 'PUT'], $base . '/{id:\d+}'],
            'destroy' => [['DELETE'], $base . '/{id:\d+}'],
        ];

        $routes = [];

        foreach ($actions as $action => [$methods, $uri]) {
            if (($only !== [] && !in_array($action, $only, true)) || in_array($action, $except, true)) {
                continue;
            }

            $routes[] = $this->addRoute($methods, $uri, [$controller, $action])->name($name . '.' . $action)->middleware((array) $middleware);
        }

        return $routes;
    }

    /**
     * Group routes under a shared prefix, middleware and/or name prefix.
     *
     * @param array{prefix?: string, middleware?: string|list<string>, as?: string} $attributes
     * @param Closure(Router): void $routes
     */
    public function group(array $attributes, Closure $routes): void
    {
        $parent = end($this->groupStack) ?: ['prefix' => '', 'middleware' => [], 'as' => ''];

        $this->groupStack[] = [
            'prefix' => self::joinPaths($parent['prefix'], (string) ($attributes['prefix'] ?? '')),
            'middleware' => [...$parent['middleware'], ...(array) ($attributes['middleware'] ?? [])],
            'as' => $parent['as'] . ($attributes['as'] ?? ''),
        ];

        try {
            $routes($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Register routes declared with #[Get], #[Post], ... attributes on controller classes.
     *
     * @param list<class-string> $controllers
     */
    public function controllers(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $class = new ReflectionClass($controller);
            $prefix = ($class->getAttributes(Prefix::class)[0] ?? null)?->newInstance();
            $classMiddleware = self::middlewareFromAttributes($class->getAttributes(MiddlewareAttribute::class));

            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

                foreach ($attributes as $attribute) {
                    /** @var RouteAttribute $definition */
                    $definition = $attribute->newInstance();
                    $methods = $definition->methods === ['GET'] ? ['GET', 'HEAD'] : $definition->methods;

                    $route = $this->addRoute(
                        $methods,
                        self::joinPaths($prefix->path ?? '', $definition->path),
                        [$controller, $method->getName()],
                    );

                    $route->middleware(
                        $classMiddleware,
                        self::middlewareFromAttributes($method->getAttributes(MiddlewareAttribute::class)),
                        $definition->middleware,
                    );

                    if ($definition->name !== null) {
                        $route->name(($prefix->name ?? '') . $definition->name);
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $methods
     */
    public function addRoute(array $methods, string $uri, Closure|array|string $action): Route
    {
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));

        foreach ($methods as $method) {
            if (!in_array($method, self::VERBS, true)) {
                throw new InvalidArgumentException("Unsupported HTTP method [{$method}].");
            }
        }

        $group = end($this->groupStack) ?: ['prefix' => '', 'middleware' => [], 'as' => ''];
        $route = new Route($methods, self::joinPaths($group['prefix'], $uri), $action);
        $route->middleware($group['middleware']);

        $route->prefixName($group['as']);

        $this->routes[] = $route;
        $this->staticRoutes = [];

        return $route;
    }

    public function aliasMiddleware(string $name, string|Closure $middleware): void
    {
        $this->middlewareAliases[$name] = $middleware;
    }

    /**
     * @param list<string> $middleware
     */
    public function middlewareGroup(string $name, array $middleware): void
    {
        $this->middlewareGroups[$name] = $middleware;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    // ---------------------------------------------------------------------
    // Matching & dispatching
    // ---------------------------------------------------------------------

    /**
     * Find the route for a request.
     *
     * @return array{0: Route, 1: array<string, string>}
     * @throws NotFoundHttpException|MethodNotAllowedHttpException
     */
    public function find(Request $request): array
    {
        $method = $request->method();
        $path = $request->path();
        $candidates = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];

        if ($this->staticRoutes === []) {
            $this->indexStaticRoutes();
        }

        foreach ($candidates as $candidate) {
            if (isset($this->staticRoutes[$candidate][$path])) {
                return [$this->staticRoutes[$candidate][$path], []];
            }
        }

        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->matches($path);

            if ($parameters === null) {
                continue;
            }

            if (array_intersect($candidates, $route->methods()) !== []) {
                return [$route, $parameters];
            }

            array_push($allowed, ...$route->methods());
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new NotFoundHttpException("No route matches [{$method} {$path}].");
    }

    /**
     * Route the request, run route middleware and call the action.
     */
    public function dispatch(Request $request): Response
    {
        [$route, $parameters] = $this->find($request);

        $request->setRoute($route, $parameters);
        $this->container->instance(Request::class, $request);

        return (new Pipeline($this->container))
            ->through($this->resolveMiddleware($route->getMiddleware()))
            ->run($request, fn (Request $request): Response => $this->runAction($route, $request));
    }

    /**
     * Expand aliases, groups and "name:param1,param2" notation.
     *
     * @param list<string> $names
     * @return list<array{0: class-string|Closure, 1: list<string>}>
     */
    public function resolveMiddleware(array $names, array $seenGroups = []): array
    {
        $resolved = [];

        foreach ($names as $name) {
            [$alias, $arguments] = array_pad(explode(':', $name, 2), 2, null);

            if (isset($this->middlewareGroups[$alias])) {
                if (in_array($alias, $seenGroups, true)) {
                    throw new LogicException("Middleware group [{$alias}] references itself.");
                }

                array_push($resolved, ...$this->resolveMiddleware($this->middlewareGroups[$alias], [...$seenGroups, $alias]));
                continue;
            }

            $middleware = $this->middlewareAliases[$alias] ?? $alias;

            if (is_string($middleware) && !class_exists($middleware)) {
                throw new InvalidArgumentException("Unknown middleware [{$alias}].");
            }

            $resolved[] = [$middleware, $arguments === null || $arguments === '' ? [] : explode(',', $arguments)];
        }

        return $resolved;
    }

    protected function runAction(Route $route, Request $request): Response
    {
        $action = $route->action();

        if (is_string($action) && str_contains($action, '@')) {
            $action = explode('@', $action, 2);
        }

        [$callable, $reflection] = $this->container->resolveCallable($action);

        // Rails-style controllers run their before_action filters themselves.
        if (is_array($callable) && $callable[0] instanceof Controller) {
            return $callable[0]->callAction($callable[1], $request, $this->container, $request->routeParameters());
        }

        try {
            $arguments = $this->container->resolveArguments($reflection, $request->routeParameters());
        } catch (ArgumentTypeException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }

        return self::toResponse($callable(...$arguments));
    }

    /**
     * Convert a controller return value into a Response.
     */
    public static function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => Response::noContent(),
            default => new JsonResponse($result instanceof JsonSerializable ? $result->jsonSerialize() : $result),
        };
    }

    // ---------------------------------------------------------------------
    // Reverse routing & caching
    // ---------------------------------------------------------------------

    public function hasNamed(string $name): bool
    {
        return $this->findNamed($name) !== null;
    }

    /**
     * @param array<string, scalar> $parameters
     */
    public function path(string $name, array $parameters = []): string
    {
        $route = $this->findNamed($name) ?? throw new InvalidArgumentException("Route [{$name}] is not defined.");

        return $route->buildPath($parameters);
    }

    /**
     * Export all routes as plain arrays for `route:cache`.
     *
     * @return list<array<string, mixed>>
     */
    public function toCache(): array
    {
        $routes = [];

        foreach ($this->routes as $route) {
            if (!$route->isCacheable()) {
                throw new LogicException("Route [{$route->uri()}] uses a closure and cannot be cached. Use a controller.");
            }

            $routes[] = $route->toArray();
        }

        return $routes;
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    public function loadCache(array $routes): void
    {
        $this->routes = array_map(Route::fromArray(...), $routes);
        $this->staticRoutes = [];
    }

    protected function findNamed(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    protected function indexStaticRoutes(): void
    {
        foreach ($this->routes as $route) {
            if (!$route->isStatic()) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $this->staticRoutes[$method][$route->uri()] ??= $route;
            }
        }

        // Keep the index non-empty so we do not rebuild it on every request.
        $this->staticRoutes[''] ??= [];
    }

    protected static function joinPaths(string $prefix, string $uri): string
    {
        return '/' . trim(trim($prefix, '/') . '/' . trim($uri, '/'), '/');
    }

    /**
     * @param list<ReflectionAttribute<MiddlewareAttribute>> $attributes
     * @return list<string>
     */
    private static function middlewareFromAttributes(array $attributes): array
    {
        $middleware = [];

        foreach ($attributes as $attribute) {
            array_push($middleware, ...$attribute->newInstance()->middleware);
        }

        return $middleware;
    }
}
