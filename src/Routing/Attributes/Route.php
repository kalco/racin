<?php

declare(strict_types=1);

namespace Racin\Routing\Attributes;

use Attribute;

/**
 * Declare a route on a controller method.
 *
 *     #[Route(['GET', 'HEAD'], '/users/{id}', name: 'users.show')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    /** @var list<string> */
    public readonly array $methods;

    /** @var list<string> */
    public readonly array $middleware;

    /**
     * @param string|list<string> $methods
     * @param string|list<string> $middleware
     */
    public function __construct(
        string|array $methods,
        public readonly string $path,
        public readonly ?string $name = null,
        string|array $middleware = [],
    ) {
        $this->methods = array_map('strtoupper', (array) $methods);
        $this->middleware = (array) $middleware;
    }
}
