<?php

declare(strict_types=1);

namespace Racin\Routing\Attributes;

use Attribute;

/**
 * URI prefix (and optional route-name prefix) for every route in a controller.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Prefix
{
    public function __construct(public readonly string $path, public readonly string $name = '')
    {
    }
}
