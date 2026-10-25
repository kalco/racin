<?php

declare(strict_types=1);

namespace Racin\Translation;

use Racin\Support\Arr;

/**
 * Translations from lang/{locale}/{group}.php files.
 *
 *     lang/mk/messages.php:  return ['welcome' => 'Добредојде, :name!'];
 *     __('messages.welcome', ['name' => 'Ана'])   // "Добредојде, Ана!"
 *
 * Missing keys fall back to the fallback locale, then to the key itself.
 */
final class Translator
{
    /** @var array<string, array<string, array<string, mixed>>> locale => group => lines */
    private array $loaded = [];

    private readonly string $default;

    /**
     * @param list<string> $supported
     */
    public function __construct(
        private readonly string $path,
        private string $locale = 'en',
        private readonly string $fallback = 'en',
        private readonly array $supported = ['en'],
    ) {
        $this->default = $locale;
    }

    /**
     * The configured default locale (what each request starts with).
     */
    public function defaultLocale(): string
    {
        return $this->default;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if ($this->isSupported($locale)) {
            $this->locale = $locale;
        }
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported, true);
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return $this->supported;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return is_string($this->line($key, $locale ?? $this->locale));
    }

    /**
     * @param array<string, scalar|null> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = $this->line($key, $locale ?? $this->locale) ?? $this->line($key, $this->fallback) ?? $key;

        return self::replace(is_string($line) ? $line : $key, $replace);
    }

    /**
     * Pick a singular/plural form: "one|many" or "{0} none|{1} one|[2,*] many".
     *
     * @param array<string, scalar|null> $replace
     */
    public function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        $forms = explode('|', $this->get($key, [], $locale));
        $replace += ['count' => $count];
        $chosen = null;

        foreach ($forms as $form) {
            if (preg_match('/^\{(\d+)\}\s*(.*)$/s', $form, $m) === 1 && (int) $m[1] === $count) {
                $chosen = $m[2];
                break;
            }

            if (preg_match('/^\[(\d+),(\d+|\*)\]\s*(.*)$/s', $form, $m) === 1 && $count >= (int) $m[1] && ($m[2] === '*' || $count <= (int) $m[2])) {
                $chosen = $m[3];
                break;
            }
        }

        if ($chosen === null) {
            $plain = array_values(array_filter($forms, static fn (string $f): bool => preg_match('/^(\{|\[)/', $f) !== 1));
            $chosen = $plain === [] ? $forms[0] : ($count === 1 ? $plain[0] : ($plain[1] ?? $plain[0]));
        }

        return self::replace(trim($chosen), $replace);
    }

    private function line(string $key, string $locale): mixed
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, null);

        if ($item === null || preg_match('/^[A-Za-z0-9_-]+$/', $group) !== 1 || preg_match('/^[A-Za-z0-9_-]+$/', $locale) !== 1) {
            return null;
        }

        if (!isset($this->loaded[$locale][$group])) {
            $file = $this->path . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $group . '.php';
            $lines = is_file($file) ? require $file : [];
            $this->loaded[$locale][$group] = is_array($lines) ? $lines : [];
        }

        $line = Arr::get($this->loaded[$locale][$group], $item);

        return is_string($line) ? $line : null;
    }

    /**
     * @param array<string, scalar|null> $replace
     */
    private static function replace(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        // Longest keys first so ":name" does not clobber ":names".
        uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs[':' . $key] = (string) $value;
        }

        return strtr($line, $pairs);
    }
}
