<?php

declare(strict_types=1);

namespace Racin\Http\Attributes;

use Attribute;

/**
 * Run a controller method before actions (Rails' before_action).
 *
 *     #[BeforeAction('authorizeRequest')]
 *     #[BeforeAction('setUser', only: ['show', 'update', 'destroy'])]
 *
 * The method may take the Request, route parameters and services as arguments.
 * Returning a Response (or throwing) stops the request before the action runs.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BeforeAction
{
    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param string|list<string> $methods
     * @param list<string> $only actions to run for (default: all)
     * @param list<string> $except actions to skip
     */
    public function __construct(string|array $methods, public readonly array $only = [], public readonly array $except = [])
    {
        $this->methods = array_values((array) $methods);
    }
}
