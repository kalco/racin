<?php

declare(strict_types=1);

namespace Racin\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * A local disk (config/filesystems.php). Paths are relative to the disk root and
 * can never escape it ("..", absolute paths and NUL bytes are rejected).
 *
 *     $disk = $app->make(StorageManager::class)->disk('public');
 *     $disk->put('reports/2026.csv', $csv);
 *     $disk->url('reports/2026.csv');       // https://example.com/uploads/reports/2026.csv
 */
final class Storage
{
    private readonly string $root;

    public function __construct(string $root, private readonly ?string $baseUrl = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function put(string $path, string $contents): string
    {
        $full = $this->path($path);
        $directory = dirname($full);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        $temp = $full . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temp, $contents, LOCK_EX) === false || !rename($temp, $full)) {
            @unlink($temp);

            throw new RuntimeException("Unable to write [{$path}].");
        }

        return self::normalize($path);
    }

    public function get(string $path): string
    {
        $contents = @file_get_contents($this->path($path));

        if ($contents === false) {
            throw new RuntimeException("File [{$path}] not found.");
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function size(string $path): int
    {
        return (int) @filesize($this->path($path));
    }

    /**
     * Delete one or more files; missing files are ignored.
     *
     * @param string|list<string|null> $paths
     */
    public function delete(string|array $paths): void
    {
        foreach ((array) $paths as $path) {
            if ($path !== null && $path !== '' && is_file($full = $this->path($path))) {
                @unlink($full);
            }
        }
    }

    /**
     * Public URL of a file (null for disks without a URL, i.e. private ones).
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '' || $this->baseUrl === null) {
            return null;
        }

        return rtrim($this->baseUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', self::normalize($path))));
    }

    /**
     * Absolute filesystem path for a relative path on this disk.
     */
    public function path(string $path): string
    {
        return $this->root . '/' . self::normalize($path);
    }

    public function root(): string
    {
        return $this->root;
    }

    private static function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1 || preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new InvalidArgumentException("Unsafe storage path [{$path}].");
        }

        return (string) preg_replace('#/+#', '/', $path);
    }
}
