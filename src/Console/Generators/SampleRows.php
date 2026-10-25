<?php

declare(strict_types=1);

namespace Racin\Console\Generators;

use Closure;

/**
 * Example rows that pass a model's validation rules, for generated seed files and Postman bodies.
 *
 *     SampleRows::make(['title' => 'required|string|max:80', 'category_id' => 'required|exists:categories,id'], 3, $ids)
 *     → [['title' => 'Title 1', 'category_id' => 4], ...]
 *
 * Optional columns ("nullable" without "required") are left out.
 */
final class SampleRows
{
    /**
     * @param array<string, string|list<mixed>> $rules column => rule string
     * @param (Closure(string $table, string $column): list<int|string>)|null $existing ids for "exists" rules
     * @param int $start number of the first row (used in generated values, e.g. "Title 1")
     * @return list<array<string, mixed>>
     */
    public static function make(array $rules, int $count, ?Closure $existing = null, int $start = 1): array
    {
        $rows = [];
        $references = [];

        for ($i = $start; $i < $start + $count; $i++) {
            $row = [];

            foreach ($rules as $column => $rule) {
                $parts = is_string($rule) ? explode('|', $rule) : array_values(array_filter($rule, 'is_string'));

                if (in_array('nullable', $parts, true) && !in_array('required', $parts, true)) {
                    continue;
                }

                $row[$column] = self::value((string) $column, $parts, $i, $existing, $references);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $parts
     * @param array<string, list<int|string>> $references
     */
    private static function value(string $column, array $parts, int $i, ?Closure $existing, array &$references): mixed
    {
        $rules = [];

        foreach ($parts as $part) {
            [$name, $argument] = array_pad(explode(':', $part, 2), 2, '');
            $rules[$name] = $argument;
        }

        if (isset($rules['exists'])) {
            [$table, $key] = array_pad(explode(',', $rules['exists']), 2, 'id');
            $references[$rules['exists']] ??= $existing !== null ? $existing($table, $key) : [];
            $ids = $references[$rules['exists']];

            return $ids === [] ? 1 : $ids[($i - 1) % count($ids)];
        }

        if (isset($rules['in'])) {
            $options = explode(',', $rules['in']);

            return $options[($i - 1) % count($options)];
        }

        $max = isset($rules['max']) ? (int) $rules['max'] : null;
        $min = isset($rules['min']) ? (int) $rules['min'] : null;
        $label = ucfirst(str_replace('_', ' ', $column));

        return match (true) {
            isset($rules['boolean']) => $i % 2 === 1,
            isset($rules['integer']) => self::clamp($i, $min, $max),
            isset($rules['numeric']) => (float) self::clamp(round(9.99 * $i, 2), $min, $max),
            isset($rules['email']) => "{$column}{$i}@example.com",
            isset($rules['url']) => "https://example.com/{$column}/{$i}",
            isset($rules['date']) => date('Y-m-d', (int) strtotime("2026-01-01 +{$i} days")),
            isset($rules['uuid']) => sprintf('00000000-0000-4000-8000-%012d', $i),
            isset($rules['array']) => [],
            isset($rules['alpha_dash']) => self::cut(str_replace('_', '-', $column) . "-{$i}", $max),
            default => self::cut("{$label} {$i}", $max),
        };
    }

    private static function clamp(int|float $value, ?int $min, ?int $max): int|float
    {
        if ($min !== null && $value < $min) {
            $value = $min;
        }

        return $max !== null && $value > $max ? $max : $value;
    }

    private static function cut(string $value, ?int $max): string
    {
        return $max !== null && $max > 0 ? mb_substr($value, 0, $max) : $value;
    }
}
