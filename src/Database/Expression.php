<?php

declare(strict_types=1);

namespace Racin\Database;

use Stringable;

/**
 * Raw SQL fragment that is inserted into a query without quoting or binding.
 * Never build an Expression from user input.
 */
final class Expression implements Stringable
{
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
