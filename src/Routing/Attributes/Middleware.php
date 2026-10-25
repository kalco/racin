<?php

declare(strict_types=1);

namespace Racin\Routing\Attributes;

use Attribute;

/**
 * Attach middleware to a controller (all its routes) or to a single method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var list<string> */
    public readonly array $middleware;

    public function __construct(string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}
