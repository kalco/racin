<?php

declare(strict_types=1);

namespace Racin\Http;

use Closure;
use Racin\Container\ArgumentTypeException;
use Racin\Container\Container;
use Racin\Database\Paginator;
use Racin\Http\Attributes\BeforeAction;
use Racin\Http\Attributes\SkipBeforeAction;
use Racin\Http\Exceptions\NotFoundHttpException;
use Racin\Routing\Router;
use ReflectionClass;
use ReflectionMethod;

/**
 * Base controller in the style of Rails' ActionController.
 *
 *     #[BeforeAction('authorizeRequest')]
 *     #[BeforeAction('setUser', only: ['show', 'update', 'destroy'])]
 *     final class UsersController extends ApiController
 *     {
 *         private User $user;
 *
 *         public function index(): Response
 *         {
 *             $this->checkPermission('users', 'read');
 *             $users = User::byTenant($this->currentUser()->tenant_id)->get();
 *
 *             return $this->render(json: $users, eachSerializer: UserSerializer::class, status: 'ok');
 *         }
 *
 *         protected function setUser(int $id): void
 *         {
 *             $this->user = User::findOrFail($id);
 *         }
 *     }
 *
 * Filters can also be registered in the constructor, including closures:
 *     $this->beforeAction(fn () => $this->checkTenantAccess($this->user), only: ['show']);
 *
 * Action and filter methods get route parameters by name and the Request/services by type.
 * Filters run in declaration order (parent classes first); a filter that returns a Response
 * (or throws) stops the request. Filter methods may be protected or private.
 */
abstract class Controller
{
    protected Request $request;

    protected Container $container;

    /** @var list<array{filter: string|Closure, only: list<string>, except: list<string>}> */
    private array $registeredFilters = [];

    /** @var list<array{filter: string, only: list<string>, except: list<string>}> */
    private array $registeredSkips = [];

    // ------------------------------------------------------------------ filters

    /**
     * @param list<string> $only
     * @param list<string> $except
     */
    protected function beforeAction(string|Closure $filter, array $only = [], array $except = []): void
    {
        $this->registeredFilters[] = ['filter' => $filter, 'only' => $only, 'except' => $except];
    }

    /**
     * @param list<string> $only
     * @param list<string> $except
     */
    protected function skipBeforeAction(string $filter, array $only = [], array $except = []): void
    {
        $this->registeredSkips[] = ['filter' => $filter, 'only' => $only, 'except' => $except];
    }

    /**
     * Run filters, then the action. Called by the router.
     *
     * @internal
     * @param array<string, mixed> $parameters route parameters
     */
    public function callAction(string $action, Request $request, Container $container, array $parameters = []): Response
    {
        $this->request = $request;
        $this->container = $container;

        $method = new ReflectionMethod($this, $action);

        if (!$method->isPublic() || $method->isStatic() || $method->getDeclaringClass()->getName() === self::class) {
            throw new NotFoundHttpException();
        }

        $arguments = $this->resolve($method, $parameters);

        foreach ($this->filtersFor($action) as $filter) {
            $result = $filter instanceof Closure
                ? $container->call($filter, $parameters)
                : (new ReflectionMethod($this, $filter))->invokeArgs($this, $this->resolve(new ReflectionMethod($this, $filter), $parameters));

            if ($result instanceof Response) {
                return $result;
            }
        }

        return Router::toResponse($method->invokeArgs($this, $arguments));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Render JSON, Rails style:
     *
     *     return $this->render(json: $user, serializer: UserSerializer::class, status: 'created');
     *     return $this->render(json: $users, eachSerializer: UserSerializer::class);   // list or Paginator
     *
     * The body is {"data": ...} (plus "meta" for paginators and $meta); pass root: false for the bare value.
     *
     * @param class-string<Serializer>|null $serializer
     * @param class-string<Serializer>|null $eachSerializer
     * @param array<string, string> $headers
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $context passed to serializers (default: ['user' => current user])
     */
    protected function render(
        mixed $json = null,
        ?string $serializer = null,
        ?string $eachSerializer = null,
        int|string $status = 'ok',
        array $headers = [],
        array $meta = [],
        bool $root = true,
        array $context = [],
    ): Response {
        $code = Status::code($status);

        if ($code === 204) {
            return Response::noContent(204, $headers);
        }

        $context += ['user' => $this->currentUser()];
        $listSerializer = $eachSerializer ?? $serializer;

        if ($json instanceof Paginator) {
            $page = $listSerializer !== null ? $listSerializer::page($json, $context)->jsonSerialize() : $json->jsonSerialize();
            $data = $page['data'];
            $meta = $page['meta'] + $meta;
        } elseif ($eachSerializer !== null || ($serializer !== null && is_array($json) && array_is_list($json))) {
            $data = $listSerializer::many($json ?? [], $context);
        } elseif ($serializer !== null) {
            $data = $serializer::one($json, $context);
        } else {
            $data = $json;
        }

        $body = $root ? ['data' => $data] + ($meta === [] ? [] : ['meta' => $meta]) : $data;

        return new JsonResponse($body, $code, $headers);
    }

    /**
     * A response without a body: head('no_content'), head('accepted').
     *
     * @param array<string, string> $headers
     */
    protected function head(int|string $status, array $headers = []): Response
    {
        return new Response('', Status::code($status), $headers);
    }

    /**
     * Request parameters (query + body) with require()/permit().
     */
    protected function params(): Params
    {
        return new Params($this->request->all());
    }

    /**
     * Validate request input; 422 with field errors on failure.
     *
     * @param array<string, string|list<mixed>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    protected function validate(array $rules, array $messages = []): array
    {
        return $this->request->validate($rules, $messages);
    }

    /**
     * The authenticated user, or null (set by the auth middleware or an authentication filter).
     */
    protected function currentUser(): mixed
    {
        return isset($this->request) ? $this->request->user() : null;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return list<string|Closure>
     */
    private function filtersFor(string $action): array
    {
        $filters = [];
        $skips = $this->registeredSkips;
        $classes = [];

        for ($class = new ReflectionClass($this); $class !== false; $class = $class->getParentClass()) {
            array_unshift($classes, $class);
        }

        foreach ($classes as $class) {
            foreach ($class->getAttributes(BeforeAction::class) as $attribute) {
                $definition = $attribute->newInstance();

                foreach ($definition->methods as $method) {
                    $filters[] = ['filter' => $method, 'only' => $definition->only, 'except' => $definition->except];
                }
            }

            foreach ($class->getAttributes(SkipBeforeAction::class) as $attribute) {
                $definition = $attribute->newInstance();

                foreach ($definition->methods as $method) {
                    $skips[] = ['filter' => $method, 'only' => $definition->only, 'except' => $definition->except];
                }
            }
        }

        array_push($filters, ...$this->registeredFilters);
        $run = [];

        foreach ($filters as $filter) {
            if (!self::applies($filter, $action)) {
                continue;
            }

            foreach ($skips as $skip) {
                if ($skip['filter'] === $filter['filter'] && self::applies($skip, $action)) {
                    continue 2;
                }
            }

            $run[] = $filter['filter'];
        }

        return $run;
    }

    /**
     * @param array{only: list<string>, except: list<string>} $rule
     */
    private static function applies(array $rule, string $action): bool
    {
        return ($rule['only'] === [] || in_array($action, $rule['only'], true)) && !in_array($action, $rule['except'], true);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<mixed>
     */
    private function resolve(ReflectionMethod $method, array $parameters): array
    {
        try {
            return $this->container->resolveArguments($method, $parameters);
        } catch (ArgumentTypeException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        }
    }
}
