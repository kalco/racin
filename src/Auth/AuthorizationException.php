<?php

declare(strict_types=1);

namespace Racin\Auth;

use Racin\Http\Exceptions\HttpException;

class AuthorizationException extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(403, $message);
    }
}
