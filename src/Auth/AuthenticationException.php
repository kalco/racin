<?php

declare(strict_types=1);

namespace Racin\Auth;

use Racin\Http\Exceptions\HttpException;
use Throwable;

class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Unauthenticated.', ?Throwable $previous = null)
    {
        parent::__construct(401, $message, ['WWW-Authenticate' => 'Bearer'], $previous);
    }
}
