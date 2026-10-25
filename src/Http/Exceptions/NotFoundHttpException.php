<?php

declare(strict_types=1);

namespace Racin\Http\Exceptions;

use Throwable;

class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
