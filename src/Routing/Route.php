<?php

declare(strict_types=1);

namespace Racin\Routing;

use Closure;
use InvalidArgumentException;

/**
 * A single route definition.
 *
 * URI parameters: {id}, {id:\d+}, optional {slug?} and {slug?:[a-z-]+}.
 */
final class Route
{
    private const string PARAMETER = '/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?(?::((?:[^{}]++|\{[^{}]*\})+))?\}/';

    private ?string $name = null;

    private string $namePrefix = '';

    /** @var list<string> */
    private array $middleware = [];

    /** @var array<string, string> */
    private array $wheres = [];

    private ?string $regex = null;

    /** @var list<string> */
    private array $parameterNames = [];

    /**
     * @param list<string> $methods
     * @param Closure|array{0: class-string, 1: string}|string $action
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly Closure|array|string $action,
    ) {
        if (is_array($action) && (count($action) !== 2 || !is_string($action[0] ?? null) || !is_string($action[1] ?? null))) {
            throw new InvalidArgumentException('Route actions must be [Controller::class, "method"].');
        }
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * @return Closure|array{0: class-string, 1: string}|string
     */
    public function action(): Closure|array|string
    {
        return $this->action;
    }

    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;

        return $this;
    }

    /**
     * Prefix applied to the name given later via name() (route group "as").
     */
    public function prefixName(string $prefix): self
    {
        $this->namePrefix = $prefix;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|list<string> ...$middleware
     */
    public function middleware(string|array ...$middleware): self
    {
        foreach ($middleware as $item) {
            foreach ((array) $item as $name) {
                if (!in_array($name, $this->middleware, true)) {
                    $this->middleware[] = $name;
                }
            }
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Constrain a parameter with a regular expression (without delimiters).
     *
     * @param string|array<string, string> $name
     */
    public function where(string|array $name, ?string $pattern = null): self
    {
        $wheres = is_array($name) ? $name : [$name => (string) $pattern];

        foreach ($wheres as $parameter => $regex) {
            $this->wheres[$parameter] = $regex;
        }

        $this->regex = null;

        return $this;
    }

    public function whereNumber(string ...$names): self
    {
        foreach ($names as $name) {
            $this->where($name, '\d+');
        }

        return $this;
    }

    public function isStatic(): bool
    {
        return !str_contains($this->uri, '{');
    }

    public function isCacheable(): bool
    {
        return !$this->action instanceof Closure;
    }

    /**
     * Match a path. Returns the route parameters, or null when the path does not match.
     *
     * @return array<string, string>|null
     */
    public function matches(string $path): ?array
    {
        if ($this->isStatic()) {
            return $path === $this->uri ? [] : null;
        }

        if (preg_match($this->regex(), $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $parameters[$name] = $matches[$name];
            }
        }

        return $parameters;
    }

    /**
     * @return list<string>
     */
    public function parameterNames(): array
    {
        $this->regex();

        return $this->parameterNames;
    }

    public function regex(): string
    {
        if ($this->regex !== null) {
            return $this->regex;
        }

        $this->parameterNames = [];
        $regex = '';
        $offset = 0;

        preg_match_all(self::PARAMETER, $this->uri, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $position] = $match[0];
            $name = $match[1][0];
            $optional = ($match[2][0] ?? '') === '?';
            $pattern = $this->wheres[$name] ?? (isset($match[3]) && $match[3][0] !== '' ? $match[3][0] : '[^/]+');
            $pattern = str_replace('#', '\#', $pattern);

            if (in_array($name, $this->parameterNames, true)) {
                throw new InvalidArgumentException("Route [{$this->uri}] uses parameter [{$name}] more than once.");
            }

            $this->parameterNames[] = $name;
            $static = substr($this->uri, $offset, $position - $offset);

            if ($optional && str_ends_with($static, '/')) {
                $regex .= preg_quote(substr($static, 0, -1), '#') . '(?:/(?P<' . $name . '>' . $pattern . '))?';
            } else {
                $regex .= preg_quote($static, '#') . '(?P<' . $name . '>' . $pattern . ')' . ($optional ? '?' : '');
            }

            $offset = $position + strlen($whole);
        }

        $regex .= preg_quote(substr($this->uri, $offset), '#');

        return $this->regex = '#^' . $regex . '$#u';
    }

    /**
     * Build the path for this route with the given parameters; unknown parameters become the query string.
     *
     * @param array<string, scalar> $parameters
     */
    public function buildPath(array $parameters = []): string
    {
        $path = preg_replace_callback(self::PARAMETER, function (array $match) use (&$parameters): string {
            $name = $match[1];

            if (array_key_exists($name, $parameters)) {
                $value = rawurlencode((string) $parameters[$name]);
                unset($parameters[$name]);

                return $value;
            }

            if (($match[2] ?? '') === '?') {
                return '';
            }

            throw new InvalidArgumentException("Missing required parameter [{$name}] for route [{$this->uri}].");
        }, $this->uri);

        $path = '/' . trim((string) preg_replace('#/+#', '/', (string) $path), '/');

        return $parameters === [] ? $path : $path . '?' . http_build_query($parameters);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'uri' => $this->uri,
            'action' => $this->action,
            'name' => $this->name,
            'middleware' => $this->middleware,
            'wheres' => $this->wheres,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $route = new self($data['methods'], $data['uri'], $data['action']);
        $route->middleware = $data['middleware'];
        $route->wheres = $data['wheres'];
        $route->name = $data['name'];

        return $route;
    }
}
