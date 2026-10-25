<?php

declare(strict_types=1);

namespace Racin\Validation;

/**
 * A custom validation rule object.
 *
 *     'code' => ['required', new CouponCode()]
 */
interface Rule
{
    /**
     * @param array<string, mixed> $data all input under validation
     */
    public function passes(string $attribute, mixed $value, array $data): bool;

    /**
     * Error message; ":attribute" is replaced with the field's display name.
     */
    public function message(): string;
}
