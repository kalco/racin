<?php

declare(strict_types=1);

namespace Racin\Database\Relations;

use Racin\Database\Model;
use Racin\Database\QueryBuilder;

/**
 * $user->orders — the related table holds the foreign key (orders.user_id → users.id).
 */
class HasMany extends Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(Model $parent, string $related, protected readonly string $foreignKey, protected readonly string $localKey = 'id')
    {
        parent::__construct($parent, $related);
    }

    /**
     * @return list<Model>
     */
    public function getResults(): mixed
    {
        return $this->parent->getAttribute($this->localKey) === null ? [] : $this->query()->get();
    }

    public function eagerLoad(array $models, string $name, array $nested): void
    {
        $grouped = $this->loadGrouped($models, $nested);

        foreach ($models as $model) {
            $model->setRelation($name, $grouped[(string) $model->getAttribute($this->localKey)] ?? []);
        }
    }

    /**
     * Create a related record with the foreign key set.
     *
     * @param array<string, mixed> $attributes mass-assigned through fill()
     */
    public function create(array $attributes): Model
    {
        $model = $this->newRelated()->fill($attributes);
        $model->forceFill([$this->foreignKey => $this->parent->getAttribute($this->localKey)]);
        $model->save();

        return $model;
    }

    /**
     * @param list<Model> $models
     * @param list<string> $nested
     * @return array<string, list<Model>>
     */
    protected function loadGrouped(array $models, array $nested): array
    {
        $keys = self::keys($models, $this->localKey);
        $grouped = [];

        if ($keys !== []) {
            foreach ($this->relatedQuery()->whereIn($this->foreignKey, $keys)->with(...$nested)->get() as $related) {
                $grouped[(string) $related->getAttribute($this->foreignKey)][] = $related;
            }
        }

        return $grouped;
    }

    protected function constrainedQuery(): QueryBuilder
    {
        return $this->relatedQuery()->where($this->foreignKey, $this->parent->getAttribute($this->localKey));
    }
}
