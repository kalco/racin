<?php

declare(strict_types=1);

namespace Racin\Config;

use ArrayAccess;
use Racin\Support\Arr;
use RuntimeException;

/**
 * Configuration repository with dot-notation access.
 *
 * @implements ArrayAccess<string, mixed>
 */
class Config implements ArrayAccess
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(protected array $items = [])
    {
    }

    /**
     * Load every *.php file in a directory; the file name becomes the top-level key.
     */
    public static function fromDirectory(string $directory): self
    {
        $items = [];

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $values = require $file;

            if (!is_array($values)) {
                throw new RuntimeException("Config file [{$file}] must return an array.");
            }

            $items[basename($file, '.php')] = $values;
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        Arr::forget($this->items, (string) $offset);
    }
}
