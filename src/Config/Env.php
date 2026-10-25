<?php

declare(strict_types=1);

namespace Racin\Config;

use RuntimeException;

/**
 * Minimal .env loader.
 *
 * Supports KEY=value, `export KEY=value`, # comments, single quotes (literal),
 * double quotes (escapes + ${VAR} interpolation) and unquoted values with
 * trailing comments. Real environment variables always win over the file.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Unable to read environment file [{$file}].");
        }

        foreach (self::parse($contents) as $key => $value) {
            if (self::lookup($key) !== null) {
                continue;
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $result = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $match)) {
                throw new RuntimeException(sprintf('Invalid .env syntax on line %d.', $number + 1));
            }

            $result[$match[1]] = self::parseValue($match[2], $result);
        }

        return $result;
    }

    /**
     * Read a variable: real environment first, then values loaded from .env.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::lookup($key) ?? self::$values[$key] ?? null;

        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /**
     * Forget loaded values (used by tests).
     */
    public static function reset(): void
    {
        foreach (array_keys(self::$values) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }

        self::$values = [];
    }

    private static function lookup(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && !array_key_exists($key, self::$values)) {
            return is_scalar($_ENV[$key]) ? (string) $_ENV[$key] : null;
        }

        if (array_key_exists($key, $_SERVER) && !array_key_exists($key, self::$values)) {
            return is_scalar($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, string> $known variables defined earlier in the same file
     */
    private static function parseValue(string $raw, array $known): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === '"' || $quote === "'") {
            $end = self::closingQuote($raw, $quote);

            if ($end === null) {
                throw new RuntimeException("Unterminated quoted value in .env: {$raw}");
            }

            $value = substr($raw, 1, $end - 1);

            if ($quote === "'") {
                return $value;
            }

            $value = strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);

            return self::interpolate($value, $known);
        }

        $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));

        return self::interpolate($value, $known);
    }

    private static function closingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            if ($raw[$i] === '\\' && $quote === '"') {
                $i++;
                continue;
            }

            if ($raw[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $known
     */
    private static function interpolate(string $value, array $known): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_.]*)\}/',
            static fn (array $m): string => $known[$m[1]] ?? self::lookup($m[1]) ?? self::$values[$m[1]] ?? '',
            $value,
        );
    }
}
