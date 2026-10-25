<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use RuntimeException;

/**
 * Writes `<?php return [...];` cache files atomically.
 */
final class CacheWriter
{
    /**
     * @param array<mixed> $data must contain only scalars, null and arrays
     */
    public static function write(string $file, array $data): void
    {
        self::assertExportable($data, '');

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temp, '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($data, true) . ';' . PHP_EOL);

        if (!rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("Unable to write cache file [{$file}].");
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private static function assertExportable(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertExportable($item, $path === '' ? (string) $key : $path . '.' . $key);
            }

            return;
        }

        if ($value !== null && !is_scalar($value)) {
            throw new RuntimeException("Value at [{$path}] is a " . get_debug_type($value) . ' and cannot be cached.');
        }
    }
}
