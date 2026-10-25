<?php

declare(strict_types=1);

namespace Racin\Log;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * PSR-3 logger writing one line per entry to a daily (or single) file.
 *
 *     [2026-10-19T12:00:00.123+02:00] production.ERROR: Message {"context":"json"}
 */
final class Logger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private readonly int $threshold;

    /**
     * @param string $path directory for log files
     * @param bool $daily one file per day (racin-YYYY-MM-DD.log) instead of racin.log
     */
    public function __construct(
        private readonly string $path,
        string $level = LogLevel::DEBUG,
        private readonly string $channel = 'app',
        private readonly bool $daily = true,
    ) {
        $this->threshold = self::LEVELS[strtolower($level)] ?? throw new InvalidArgumentException("Invalid log level [{$level}].");
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        $severity = self::LEVELS[$level] ?? throw new InvalidArgumentException("Invalid log level [{$level}].");

        if ($severity < $this->threshold) {
            return;
        }

        $message = $this->interpolate((string) $message, $context);
        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            $this->channel,
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->encode($context),
        );

        $this->write($line);
    }

    public function file(): string
    {
        $name = $this->daily ? 'racin-' . date('Y-m-d') . '.log' : 'racin.log';

        return rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    private function write(string $line): void
    {
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0775, true);
        }

        if (@file_put_contents($this->file(), $line, FILE_APPEND | LOCK_EX) === false) {
            // Last resort so log lines are never silently lost.
            error_log(rtrim($line));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];

        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encode(array $context): string
    {
        array_walk_recursive($context, function (mixed &$value): void {
            $value = match (true) {
                $value instanceof Throwable => [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                    'trace' => array_slice(explode("\n", $value->getTraceAsString()), 0, 15),
                ],
                $value instanceof JsonSerializable => $value->jsonSerialize(),
                $value instanceof Stringable => (string) $value,
                is_object($value) => '[object ' . $value::class . ']',
                is_resource($value) => '[resource]',
                default => $value,
            };
        });

        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
