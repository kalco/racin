<?php

declare(strict_types=1);

namespace Racin\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Dependency injection container with reflection based autowiring.
 */
class Container implements ContainerInterface
{
    /** @var array<string, array{concrete: Closure|string, shared: bool}> */
    protected array $bindings = [];

    /** @var array<string, mixed> */
    protected array $instances = [];

    /** @var array<string, string> alias => abstract */
    protected array $aliases = [];

    /** @var array<string, true> classes currently being built (circular dependency guard) */
    private array $building = [];

    /**
     * Register a binding. A null concrete binds the abstract to itself.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);

        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'shared' => $shared];
    }

    /**
     * Register a binding that is resolved once and then reused.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing value as a shared instance.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        unset($this->aliases[$abstract]);

        $this->instances[$abstract] = $instance;

        return $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        if ($alias === $abstract) {
            throw new ContainerException("[{$abstract}] cannot be aliased to itself.");
        }

        $this->aliases[$alias] = $abstract;
    }

    /**
     * Whether the abstract was explicitly registered (binding, instance or alias).
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || array_key_exists($abstract, $this->instances)
            || isset($this->aliases[$abstract]);
    }

    public function has(string $id): bool
    {
        return $this->bound($id) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Resolve an abstract from the container.
     *
     * @param array<int|string, mixed> $parameters values for constructor parameters, by name or position
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        if ($parameters === [] && array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } elseif ($concrete === $abstract) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }

        if ($parameters === [] && ($this->bindings[$abstract]['shared'] ?? false)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Instantiate a concrete class, resolving its constructor dependencies.
     *
     * @param array<int|string, mixed> $parameters
     */
    public function build(string $concrete, array $parameters = []): object
    {
        if (!class_exists($concrete)) {
            if (interface_exists($concrete)) {
                throw new ContainerException("Interface [{$concrete}] is not bound to an implementation.");
            }

            throw new NotFoundException("Class [{$concrete}] does not exist and is not bound in the container.");
        }

        if (isset($this->building[$concrete])) {
            $chain = implode(' -> ', array_keys($this->building));

            throw new ContainerException("Circular dependency detected: {$chain} -> {$concrete}");
        }

        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class [{$concrete}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $this->building[$concrete] = true;

        try {
            return $reflector->newInstanceArgs($this->resolveArguments($constructor, $parameters));
        } finally {
            unset($this->building[$concrete]);
        }
    }

    /**
     * Call a callable, injecting its dependencies.
     *
     * Supported callables: closures, [object|class, 'method'], 'Class@method',
     * 'Class::method', invokable class names/objects and function names.
     *
     * @param array<int|string, mixed> $parameters
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed
    {
        [$callable, $reflection] = $this->resolveCallable($callback);

        return $callable(...$this->resolveArguments($reflection, $parameters));
    }

    /**
     * Turn any supported callback into [callable, reflection].
     *
     * @return array{0: callable, 1: ReflectionFunctionAbstract}
     */
    public function resolveCallable(callable|array|string $callback): array
    {
        if ($callback instanceof Closure) {
            return [$callback, new ReflectionFunction($callback)];
        }

        if (is_string($callback)) {
            if (str_contains($callback, '@')) {
                $callback = explode('@', $callback, 2);
            } elseif (str_contains($callback, '::')) {
                $callback = explode('::', $callback, 2);
            } elseif (class_exists($callback)) {
                $callback = [$callback, '__invoke'];
            } elseif (function_exists($callback)) {
                return [$callback, new ReflectionFunction($callback)];
            } else {
                throw new ContainerException("Unable to resolve callable [{$callback}].");
            }
        }

        if (is_object($callback)) {
            $callback = [$callback, '__invoke'];
        }

        if (!is_array($callback) || count($callback) !== 2) {
            throw new ContainerException('Unsupported callable given to the container.');
        }

        [$target, $method] = array_values($callback);

        try {
            $reflection = new ReflectionMethod($target, $method);
        } catch (ReflectionException) {
            $class = is_object($target) ? $target::class : (string) $target;

            throw new ContainerException("Method [{$class}::{$method}] does not exist.");
        }

        if (is_string($target) && !$reflection->isStatic()) {
            $target = $this->make($target);
        }

        return [[$target, $method], $reflection];
    }

    /**
     * Build the ordered argument list for a function or method.
     *
     * Named values in $parameters are matched by parameter name, integer keyed
     * values are used in order for parameters that are not resolved otherwise.
     *
     * @param array<int|string, mixed> $parameters
     * @return list<mixed>
     */
    public function resolveArguments(ReflectionFunctionAbstract $function, array $parameters = []): array
    {
        $positional = [];
        $named = [];

        foreach ($parameters as $key => $value) {
            is_int($key) ? $positional[] = $value : $named[$key] = $value;
        }

        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                array_push($arguments, ...$positional);
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $named, $positional);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $named
     * @param list<mixed> $positional
     */
    protected function resolveParameter(ReflectionParameter $parameter, array $named, array &$positional): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if (array_key_exists($name, $named)) {
            return $this->coerce($parameter, $named[$name]);
        }

        if ($class !== null) {
            if ($class === 'self' || $class === 'static') {
                $class = $parameter->getDeclaringClass()?->getName() ?? $class;
            }

            if ($positional !== [] && $positional[0] instanceof $class) {
                return array_shift($positional);
            }

            try {
                return $this->make($class);
            } catch (ContainerException $e) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }

                if ($parameter->allowsNull()) {
                    return null;
                }

                throw $e;
            }
        }

        if ($positional !== []) {
            return $this->coerce($parameter, array_shift($positional));
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $parameter->allowsNull()) {
            return null;
        }

        $owner = $parameter->getDeclaringClass()?->getName();
        $where = ($owner !== null ? $owner . '::' : '') . $parameter->getDeclaringFunction()->getName();

        throw new ContainerException("Unresolvable parameter [\${$name}] of [{$where}()].");
    }

    /**
     * Convert string input (route segments, CLI arguments) to the declared scalar type.
     */
    protected function coerce(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin() || !is_string($value)) {
            return $value;
        }

        $name = $parameter->getName();

        return match ($type->getName()) {
            'int' => preg_match('/^-?\d+$/', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : throw new ArgumentTypeException("Parameter [\${$name}] expects an integer, got [{$value}]."),
            'float' => is_numeric($value)
                ? (float) $value
                : throw new ArgumentTypeException("Parameter [\${$name}] expects a number, got [{$value}]."),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? throw new ArgumentTypeException("Parameter [\${$name}] expects a boolean, got [{$value}]."),
            default => $value,
        };
    }

    protected function getAlias(string $abstract): string
    {
        $seen = [];

        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new ContainerException("Alias loop detected for [{$abstract}].");
            }

            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
