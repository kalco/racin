<?php

declare(strict_types=1);

namespace Racin\Support;

/**
 * Password hashing with bcrypt at a fixed cost (identical on PHP 8.3 and 8.4+,
 * whose PASSWORD_DEFAULT cost differs).
 */
final class Hash
{
    public const int COST = 12;

    /** A valid bcrypt hash of a random string, for constant-time "user not found" checks. */
    public const string DUMMY = '$2y$12$Fq7gXyWJhshscKikY9ScmutDE0a.BCJS80WrJst4F/hdVDZe2DU8.';

    public static function make(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public static function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }
}
