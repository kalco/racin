<?php

declare(strict_types=1);

namespace Racin\Database\Relations;

use Racin\Database\Model;
use Racin\Database\QueryBuilder;

/**
 * $order->user — the parent holds the foreign key (orders.user_id → users.id).
 */
final class BelongsTo extends Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, private readonly string $foreignKey, private readonly string $ownerKey = 'id')
    {
        parent::__construct($parent, $related);
    }

    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        /** @var Model|null */
        return $this->query()->first();
    }

    public function eagerLoad(array $models, string $name, array $nested): void
    {
        $keys = self::keys($models, $this->foreignKey);
        $byKey = [];

        if ($keys !== []) {
            foreach ($this->relatedQuery()->whereIn($this->ownerKey, $keys)->with(...$nested)->get() as $related) {
                $byKey[(string) $related->getAttribute($this->ownerKey)] = $related;
            }
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);
            $model->setRelation($name, $key === null ? null : ($byKey[(string) $key] ?? null));
        }
    }

    /**
     * Point the parent at another record (does not save).
     */
    public function associate(?Model $model): Model
    {
        $this->parent->setAttribute($this->foreignKey, $model?->getAttribute($this->ownerKey));

        return $this->parent;
    }

    protected function constrainedQuery(): QueryBuilder
    {
        return $this->relatedQuery()->where($this->ownerKey, $this->parent->getAttribute($this->foreignKey));
    }
}
