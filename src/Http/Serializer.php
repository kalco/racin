<?php

declare(strict_types=1);

namespace Racin\Http;

use DateTimeInterface;
use JsonSerializable;
use Racin\Database\Model;
use Racin\Database\Paginator;
use Racin\Support\Str;

/**
 * Turns models into API output, like Rails' ActiveModel::Serializer.
 *
 *     final class OrderSerializer extends Serializer
 *     {
 *         protected array $attributes = ['id', 'number', 'status', 'total', 'item_count', 'created_at'];
 *         protected array $relations = ['user' => UserSerializer::class, 'items' => OrderItemSerializer::class];
 *
 *         // computed attribute "item_count" — a public method named after it (camelCase)
 *         public function itemCount(Order $order): int
 *         {
 *             return count($order->items);
 *         }
 *     }
 *
 *     OrderSerializer::one($order);        // array
 *     OrderSerializer::many($orders);      // list — relations are eager loaded for all orders at once
 *
 * $this->context holds extra data passed by the caller (e.g. the current user) for conditional output.
 */
abstract class Serializer
{
    /** @var list<string> attributes to output, in order */
    protected array $attributes = [];

    /** @var array<string, class-string<Serializer>> relation name => serializer */
    protected array $relations = [];

    /**
     * @param array<string, mixed> $context
     */
    final public function __construct(protected array $context = [])
    {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function one(mixed $object, array $context = []): ?array
    {
        if ($object === null) {
            return null;
        }

        if ($object instanceof Model) {
            static::preload([$object]);
        }

        return (new static($context))->serialize($object);
    }

    /**
     * @param iterable<mixed> $objects
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $objects, array $context = []): array
    {
        $objects = is_array($objects) ? array_values($objects) : iterator_to_array($objects, false);
        static::preload(array_values(array_filter($objects, static fn (mixed $o): bool => $o instanceof Model)));
        $serializer = new static($context);

        return array_map(fn (mixed $object): array => $serializer->serialize($object), $objects);
    }

    /**
     * A paginator with serialized items: {"data": [...], "meta": {...}} when JSON encoded.
     *
     * @param array<string, mixed> $context
     */
    public static function page(Paginator $paginator, array $context = []): Paginator
    {
        return new Paginator(static::many($paginator->items, $context), $paginator->total, $paginator->perPage, $paginator->currentPage);
    }

    /**
     * Relation paths this serializer (and its nested serializers) outputs, e.g. ["items", "items.product"].
     *
     * @return list<string>
     */
    public static function relationPaths(int $depth = 4): array
    {
        if ($depth === 0) {
            return [];
        }

        $paths = [];

        foreach ((new static())->relations as $name => $serializer) {
            $paths[] = $name;

            foreach ($serializer::relationPaths($depth - 1) as $nested) {
                $paths[] = $name . '.' . $nested;
            }
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(mixed $object): array
    {
        $result = [];

        foreach ($this->attributes as $attribute) {
            $method = Str::camel($attribute);
            $value = $this->isAttributeMethod($method) ? $this->{$method}($object) : self::read($object, $attribute);

            $result[$attribute] = self::normalize($value);
        }

        foreach ($this->relations as $name => $serializer) {
            $related = self::read($object, $name);

            $result[$name] = match (true) {
                $related === null => null,
                is_array($related) => array_map(fn (mixed $item): array => (new $serializer($this->context))->serialize($item), $related),
                default => (new $serializer($this->context))->serialize($related),
            };
        }

        return $result;
    }

    /**
     * Eager load the relations this serializer needs that are not loaded yet.
     *
     * @param list<Model> $models
     */
    protected static function preload(array $models): void
    {
        if ($models === []) {
            return;
        }

        $missing = array_values(array_filter(
            static::relationPaths(),
            static function (string $path) use ($models): bool {
                $top = explode('.', $path, 2)[0];

                return $models[0]->isRelation($top) && !$models[0]->relationLoaded($top);
            },
        ));

        $models[0]::eagerLoadRelations($models, $missing);
    }

    /**
     * A public method declared by the concrete serializer (not by this base class).
     */
    private function isAttributeMethod(string $method): bool
    {
        if (!method_exists($this, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($this, $method);

        return $reflection->isPublic() && !$reflection->isStatic() && $reflection->getDeclaringClass()->getName() !== self::class;
    }

    private static function read(mixed $object, string $key): mixed
    {
        return match (true) {
            $object instanceof Model => $object->{$key},
            is_array($object) => $object[$key] ?? null,
            is_object($object) => $object->{$key} ?? null,
            default => null,
        };
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Model => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }
}
