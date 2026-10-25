<?php

declare(strict_types=1);

namespace Racin\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that maps directly to an HTTP error response.
 */
class HttpException extends RuntimeException
{
    public const array MESSAGES = [
        400 => 'Bad request.',
        401 => 'Unauthenticated.',
        403 => 'This action is unauthorized.',
        404 => 'Not found.',
        405 => 'Method not allowed.',
        409 => 'Conflict.',
        410 => 'Gone.',
        413 => 'Payload too large.',
        415 => 'Unsupported media type.',
        422 => 'The given data was invalid.',
        429 => 'Too many requests.',
        500 => 'Server error.',
        503 => 'Service unavailable.',
    ];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected int $statusCode,
        string $message = '',
        protected array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : (self::MESSAGES[$statusCode] ?? 'HTTP error.'), 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
