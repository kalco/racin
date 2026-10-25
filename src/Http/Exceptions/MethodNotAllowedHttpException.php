<?php

declare(strict_types=1);

namespace Racin\Http\Exceptions;

class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(protected array $allowed, string $message = '')
    {
        parent::__construct(405, $message, ['Allow' => implode(', ', $allowed)]);
    }

    /**
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowed;
    }
}
