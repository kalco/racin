<?php

declare(strict_types=1);

namespace Racin\Support;

final class Str
{
    /**
     * "UserProfile" -> "user_profile"
     */
    public static function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z][a-z])/', '_$1$2', $value);

        return strtolower(str_replace(['-', ' '], '_', $value));
    }

    /**
     * "user_profile" -> "UserProfile"
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /**
     * "user_profile" -> "userProfile"
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * URL slug: "Ćevapi & Kafe 2" -> "cevapi-kafe-2". Non-Latin scripts are transliterated when ext-intl is available.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        if (class_exists(\Transliterator::class)) {
            $value = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value) ?: $value;
        }

        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', $separator, $value);

        return trim($value, $separator);
    }

    private const array IRREGULAR = [
        'person' => 'people', 'child' => 'children', 'man' => 'men', 'woman' => 'women',
        'foot' => 'feet', 'tooth' => 'teeth', 'mouse' => 'mice', 'goose' => 'geese', 'ox' => 'oxen',
    ];

    private const array UNCOUNTABLE = ['data', 'equipment', 'fish', 'information', 'media', 'metadata', 'money', 'news', 'series', 'sheep', 'species'];

    /**
     * Naive English pluraliser used for default table names ("category" -> "categories", "person" -> "people").
     */
    public static function plural(string $word): string
    {
        $lower = strtolower($word);
        [$head, $last] = self::splitLastWord($word);

        if (in_array(strtolower($last), self::UNCOUNTABLE, true)) {
            return $word;
        }

        if (isset(self::IRREGULAR[strtolower($last)])) {
            return $head . self::matchCase(self::IRREGULAR[strtolower($last)], $last);
        }

        if (preg_match('/[^aeiou]y$/', $lower)) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $lower)) {
            return $word . 'es';
        }

        return $word . 's';
    }

    /**
     * Naive English singular ("categories" -> "category", "boxes" -> "box", "users" -> "user").
     */
    public static function singular(string $word): string
    {
        $lower = strtolower($word);
        [$head, $last] = self::splitLastWord($word);

        if (in_array(strtolower($last), self::UNCOUNTABLE, true)) {
            return $word;
        }

        $singular = array_search(strtolower($last), self::IRREGULAR, true);

        if (is_string($singular)) {
            return $head . self::matchCase($singular, $last);
        }

        return match (true) {
            str_ends_with($lower, 'ies') && strlen($word) > 3 => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(s|x|z|ch|sh)es$/', $lower) => substr($word, 0, -2),
            str_ends_with($lower, 's') && !str_ends_with($lower, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /**
     * "blog_person" → ["blog_", "person"], "BlogPerson" → ["Blog", "Person"].
     *
     * @return array{0: string, 1: string}
     */
    private static function splitLastWord(string $word): array
    {
        return preg_match('/([A-Z]?[a-z]+|[A-Z]+)$/', $word, $m, PREG_OFFSET_CAPTURE) === 1
            ? [substr($word, 0, $m[1][1]), $m[1][0]]
            : ['', $word];
    }

    private static function matchCase(string $word, string $like): string
    {
        return match (true) {
            $like === strtoupper($like) => strtoupper($word),
            ctype_upper($like[0]) => ucfirst($word),
            default => $word,
        };
    }

    /**
     * Cryptographically secure random string of [A-Za-z0-9].
     */
    public static function random(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    public static function classBasename(string|object $class): string
    {
        $class = is_object($class) ? $class::class : $class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
