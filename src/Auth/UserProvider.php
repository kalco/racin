<?php

declare(strict_types=1);

namespace Racin\Auth;

/**
 * Loads the authenticated user from the token's "sub" claim.
 */
interface UserProvider
{
    /**
     * @param array<string, mixed> $claims the verified token payload
     */
    public function retrieveById(int|string $id, array $claims = []): mixed;
}
