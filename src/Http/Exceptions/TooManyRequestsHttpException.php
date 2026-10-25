<?php

declare(strict_types=1);

namespace Racin\Http\Exceptions;

class TooManyRequestsHttpException extends HttpException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $retryAfter, array $headers = [], string $message = '')
    {
        parent::__construct(429, $message, ['Retry-After' => (string) $retryAfter] + $headers);
    }
}
