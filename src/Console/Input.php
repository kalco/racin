<?php

declare(strict_types=1);

namespace Racin\Console;

/**
 * Parsed command line: positional arguments and --options.
 */
final class Input
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|bool> $options
     */
    public function __construct(public readonly array $arguments = [], public readonly array $options = [])
    {
    }

    /**
     * Parse tokens after the command name: `foo --force --port=8080 -v`.
     *
     * @param list<string> $tokens
     */
    public static function parse(array $tokens): self
    {
        $arguments = [];
        $options = [];
        $onlyArguments = false;

        foreach ($tokens as $token) {
            if ($onlyArguments) {
                $arguments[] = $token;
            } elseif ($token === '--') {
                $onlyArguments = true;
            } elseif (str_starts_with($token, '--')) {
                [$name, $value] = array_pad(explode('=', substr($token, 2), 2), 2, true);
                $options[$name] = $value;
            } elseif (str_starts_with($token, '-') && strlen($token) > 1) {
                foreach (str_split(substr($token, 1)) as $flag) {
                    $options[$flag] = true;
                }
            } else {
                $arguments[] = $token;
            }
        }

        return new self($arguments, $options);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }
}
