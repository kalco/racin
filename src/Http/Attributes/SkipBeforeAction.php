<?php

declare(strict_types=1);

namespace Racin\Http\Attributes;

use Attribute;

/**
 * Turn off a filter inherited from a parent controller (Rails' skip_before_action).
 *
 *     #[SkipBeforeAction('authorizeRequest', only: ['index', 'show'])]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class SkipBeforeAction
{
    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param string|list<string> $methods
     * @param list<string> $only
     * @param list<string> $except
     */
    public function __construct(string|array $methods, public readonly array $only = [], public readonly array $except = [])
    {
        $this->methods = array_values((array) $methods);
    }
}
