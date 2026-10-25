<?php

declare(strict_types=1);

namespace Racin\Container;

/**
 * Thrown when a supplied scalar argument cannot be converted to the declared
 * parameter type (e.g. the route segment "abc" for an `int $id` parameter).
 */
class ArgumentTypeException extends ContainerException
{
}
