<?php

declare(strict_types=1);

namespace Racin\Support;

use ArrayAccess;

final class Arr
{
    /**
     * Get a value using dot notation ("database.connections.mysql.host").
     */
    public static function get(mixed $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (self::accessible($array) && self::exists($array, $key)) {
            return $array[$key];
        }

        foreach (explode('.', (string) $key) as $segment) {
            if (self::accessible($array) && self::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return $default instanceof \Closure ? $default() : $default;
            }
        }

        return $array;
    }

    public static function has(array $array, string $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return false;
            }

            $array = $array[$segment];
        }

        return true;
    }

    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$array;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current[array_shift($segments)] = $value;
    }

    public static function forget(array &$array, string $key): void
    {
        if (array_key_exists($key, $array)) {
            unset($array[$key]);

            return;
        }

        $segments = explode('.', $key);
        $last = array_pop($segments);
        $current = &$array;

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }

            $current = &$current[$segment];
        }

        unset($current[$last]);
    }

    /**
     * @param list<string> $keys
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * @param list<string> $keys
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Wrap a value in an array unless it already is one.
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    private static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    private static function exists(array|ArrayAccess $array, string|int $key): bool
    {
        return $array instanceof ArrayAccess ? $array->offsetExists($key) : array_key_exists($key, $array);
    }
}
