<?php

declare(strict_types=1);

namespace Racin\Console;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $decorated;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, ?bool $decorated = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->decorated = $decorated ?? self::supportsColor($this->stdout);
    }

    public function line(string $message = ''): void
    {
        fwrite($this->stdout, $message . PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->line($this->color($message, '32'));
    }

    public function comment(string $message): void
    {
        $this->line($this->color($message, '33'));
    }

    public function error(string $message): void
    {
        fwrite($this->stderr, $this->color($message, '31') . PHP_EOL);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $separator = '+' . implode('+', array_map(static fn (int $w): string => str_repeat('-', $w + 2), $widths)) . '+';
        $format = static function (array $cells) use ($widths): string {
            $out = [];

            foreach ($widths as $i => $width) {
                $cell = (string) ($cells[$i] ?? '');
                $out[] = ' ' . $cell . str_repeat(' ', $width - mb_strlen($cell)) . ' ';
            }

            return '|' . implode('|', $out) . '|';
        };

        $this->line($separator);
        $this->line($format($headers));
        $this->line($separator);

        foreach ($rows as $row) {
            $this->line($format(array_values($row)));
        }

        $this->line($separator);
    }

    public function color(string $message, string $code): string
    {
        return $this->decorated ? "\033[{$code}m{$message}\033[0m" : $message;
    }

    /**
     * @param resource $stream
     */
    private static function supportsColor($stream): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support($stream);
        }

        return function_exists('stream_isatty') && @stream_isatty($stream);
    }
}
