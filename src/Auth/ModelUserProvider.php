<?php

declare(strict_types=1);

namespace Racin\Auth;

use Racin\Database\Model;

/**
 * Loads the user model by the token's "sub".
 *
 * When the token carries a "ver" claim it must equal the user's token_version
 * column: incrementing that column logs the user out everywhere.
 */
final class ModelUserProvider implements UserProvider
{
    /**
     * @param class-string<Model> $model
     */
    public function __construct(private readonly string $model)
    {
    }

    public function retrieveById(int|string $id, array $claims = []): ?Model
    {
        $user = $this->model::find($id);

        if ($user !== null && array_key_exists('ver', $claims) && (int) $user->getAttribute('token_version') !== (int) $claims['ver']) {
            return null;
        }

        return $user;
    }
}
