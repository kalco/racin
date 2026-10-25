<?php

declare(strict_types=1);

namespace Racin\Auth;

use Racin\Http\Request;
use Throwable;

/**
 * Resolves the user from "Authorization: Bearer <jwt>": signature, expiry,
 * revocation and the user provider. Used by the "auth" middleware and by
 * controller filters (authorizeRequest).
 */
final class Guard
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly UserProvider $users,
        private readonly TokenBlacklist $blacklist,
    ) {
    }

    /**
     * Authenticate the request or throw AuthenticationException (401).
     * Sets $request->user() and the "token" attribute (verified claims).
     */
    public function authenticate(Request $request): mixed
    {
        if ($request->user() !== null) {
            return $request->user();
        }

        $token = $request->bearerToken() ?? throw new AuthenticationException();
        $claims = $this->jwt->decode($token);
        $subject = $claims['sub'] ?? null;

        if (!is_int($subject) && !is_string($subject)) {
            throw new AuthenticationException('Token has no subject.');
        }

        if ($this->blacklist->isRevoked($claims)) {
            throw new AuthenticationException('Token has been revoked.');
        }

        $user = $this->users->retrieveById($subject, $claims) ?? throw new AuthenticationException();

        $request->setUser($user);
        $request->setAttribute('token', $claims);

        return $user;
    }

    /**
     * The user when a valid token is present, otherwise null (never throws).
     */
    public function user(Request $request): mixed
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        try {
            return $this->authenticate($request);
        } catch (Throwable) {
            return null;
        }
    }
}
