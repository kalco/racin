<?php

declare(strict_types=1);

namespace Racin\Cache;

use RuntimeException;

/**
 * Stores each entry as a file: "<expiry timestamp>\n<serialized value>".
 * Writes are atomic (temp file + rename); increment() is guarded by flock.
 */
final class FileStore implements Store
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): mixed
    {
        return $this->read($this->path($key))['value'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl !== null && $ttl <= 0) {
            return $this->forget($key);
        }

        return $this->write($this->path($key), $value, $ttl === null ? 0 : time() + $ttl);
    }

    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->locked($key, function (string $path) use ($value, $ttl): bool {
            if ($this->read($path)['value'] !== null) {
                return false;
            }

            return $this->write($path, $value, $ttl === null ? 0 : time() + $ttl);
        });
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        return $this->locked($key, function (string $path) use ($by, $ttl): int {
            $item = $this->read($path);

            if ($item['value'] === null) {
                $this->write($path, $by, $ttl === null ? 0 : time() + $ttl);

                return $by;
            }

            $value = (int) $item['value'] + $by;
            $this->write($path, $value, $item['expires']);

            return $value;
        });
    }

    public function forget(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    public function flush(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->getFilename() === '.gitignore') {
                continue;
            }

            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return true;
    }

    private function path(string $key): string
    {
        $hash = sha1($key);

        return $this->directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2, 2) . DIRECTORY_SEPARATOR . $hash;
    }

    /**
     * @return array{value: mixed, expires: int}
     */
    private function read(string $path): array
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false || !str_contains($contents, "\n")) {
            return ['value' => null, 'expires' => 0];
        }

        [$expires, $payload] = explode("\n", $contents, 2);
        $expires = (int) $expires;

        if ($expires !== 0 && $expires <= time()) {
            @unlink($path);

            return ['value' => null, 'expires' => 0];
        }

        $value = @unserialize($payload);

        if ($value === false && $payload !== serialize(false)) {
            return ['value' => null, 'expires' => 0];
        }

        return ['value' => $value, 'expires' => $expires];
    }

    private function write(string $path, mixed $value, int $expires): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create cache directory [{$directory}].");
        }

        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temp, $expires . "\n" . serialize($value)) === false) {
            return false;
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * @template T
     * @param \Closure(string): T $callback
     * @return T
     */
    private function locked(string $key, \Closure $callback): mixed
    {
        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create cache directory [{$directory}].");
        }

        $handle = fopen($path . '.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open lock file for [{$key}].");
        }

        try {
            flock($handle, LOCK_EX);

            return $callback($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
