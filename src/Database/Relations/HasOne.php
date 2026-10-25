<?php

declare(strict_types=1);

namespace Racin\Database\Relations;

use Racin\Database\Model;

/**
 * $user->profile — like HasMany, but a single record.
 */
final class HasOne extends HasMany
{
    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->localKey) === null) {
            return null;
        }

        /** @var Model|null */
        return $this->query()->first();
    }

    public function eagerLoad(array $models, string $name, array $nested): void
    {
        $grouped = $this->loadGrouped($models, $nested);

        foreach ($models as $model) {
            $model->setRelation($name, $grouped[(string) $model->getAttribute($this->localKey)][0] ?? null);
        }
    }
}
