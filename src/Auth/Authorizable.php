<?php

declare(strict_types=1);

namespace Racin\Auth;

/**
 * Implemented by the user model to work with the "role:" and "can:" middleware.
 */
interface Authorizable
{
    /**
     * True when the user has at least one of the given roles.
     */
    public function hasRole(string ...$roles): bool;

    /**
     * True when one of the user's roles grants the permission.
     */
    public function hasPermission(string $permission): bool;
}
