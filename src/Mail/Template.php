<?php

declare(strict_types=1);

namespace Racin\Mail;

use RuntimeException;
use Throwable;

/**
 * Renders a plain PHP template (for email bodies). Variables are extracted into
 * scope; use $e($value) to HTML-escape.
 *
 *     <p>Hello <?= $e($name) ?></p>
 */
final class Template
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $file, array $data = []): string
    {
        if (!is_file($file)) {
            throw new RuntimeException("Template [{$file}] not found.");
        }

        $data['e'] = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
