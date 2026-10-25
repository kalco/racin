<?php

declare(strict_types=1);

namespace Racin\Validation;

use Racin\Http\Exceptions\HttpException;

class ValidationException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(protected array $errors, string $message = '')
    {
        parent::__construct(422, $message);
    }

    /**
     * @param array<string, string|list<string>> $messages
     */
    public static function withMessages(array $messages): self
    {
        return new self(array_map(static fn ($m): array => (array) $m, $messages));
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
