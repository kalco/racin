<?php

declare(strict_types=1);

namespace Racin\Database;

use Racin\Http\Exceptions\NotFoundHttpException;
use Racin\Support\Str;

class ModelNotFoundException extends NotFoundHttpException
{
    /**
     * @param class-string $model
     */
    public static function for(string $model, int|string $id): self
    {
        return new self(Str::classBasename($model) . " [{$id}] not found.");
    }
}
