<?php

declare(strict_types=1);

namespace Racin\Http;

use ArrayAccess;
use JsonSerializable;
use Racin\Http\Exceptions\BadRequestHttpException;
use Racin\Support\Arr;

/**
 * Request parameters with Rails-style strong parameters.
 *
 *     // {"user": {"name": "Ana", "role": "admin", "address": {"city": "Skopje"}, "tags": ["a"]}}
 *     $this->params()->require('user')->permit('name', ['address' => ['city']], ['tags' => []]);
 *     // ['name' => 'Ana', 'address' => ['city' => 'Skopje'], 'tags' => ['a']]   ("role" is dropped)
 *
 * @implements ArrayAccess<string, mixed>
 */
final class Params implements ArrayAccess, JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * The nested params under $key; 400 when missing or empty.
     */
    public function require(string $key): self
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value) || $value === []) {
            throw new BadRequestHttpException("Parameter missing or empty: {$key}.");
        }

        return new self($value);
    }

    /**
     * Only the listed keys. Plain names allow scalar values; ['tags' => []] allows a list
     * of scalars; ['address' => ['city', 'zip']] allows a nested object (or list of objects).
     *
     * @param string|array<string, list<mixed>> ...$keys
     * @return array<string, mixed>
     */
    public function permit(string|array ...$keys): array
    {
        return self::filter($this->data, $keys);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Params are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Params are read-only.');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * @param array<mixed> $data
     * @param list<string|array<string, list<mixed>>> $keys
     * @return array<string, mixed>
     */
    private static function filter(array $data, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (is_string($key)) {
                if (array_key_exists($key, $data) && ($data[$key] === null || is_scalar($data[$key]))) {
                    $result[$key] = $data[$key];
                }

                continue;
            }

            foreach ($key as $name => $nested) {
                $value = $data[$name] ?? null;

                if (!is_array($value)) {
                    continue;
                }

                if ($nested === []) {
                    if (array_is_list($value) && array_filter($value, static fn (mixed $v): bool => !is_scalar($v) && $v !== null) === []) {
                        $result[$name] = $value;
                    }

                    continue;
                }

                $result[$name] = array_is_list($value)
                    ? array_values(array_map(static fn (mixed $item): array => is_array($item) ? self::filter($item, $nested) : [], $value))
                    : self::filter($value, $nested);
            }
        }

        return $result;
    }
}
