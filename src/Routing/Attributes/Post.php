<?php

declare(strict_types=1);

namespace Racin\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Post extends Route
{
    /**
     * @param string|list<string> $middleware
     */
    public function __construct(string $path, ?string $name = null, string|array $middleware = [])
    {
        parent::__construct('POST', $path, $name, $middleware);
    }
}
