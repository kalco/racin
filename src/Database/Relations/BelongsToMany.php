<?php

declare(strict_types=1);

namespace Racin\Database\Relations;

use Racin\Database\Model;
use Racin\Database\QueryBuilder;

/**
 * $user->roles through a pivot table (role_user: user_id, role_id).
 */
final class BelongsToMany extends Relation
{
    private const string PIVOT_KEY = 'racin_pivot_parent';

    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey = 'id',
        private readonly string $relatedKey = 'id',
    ) {
        parent::__construct($parent, $related);
    }

    /**
     * @return list<Model>
     */
    public function getResults(): mixed
    {
        return $this->parent->getAttribute($this->parentKey) === null ? [] : $this->query()->get();
    }

    public function eagerLoad(array $models, string $name, array $nested): void
    {
        $keys = self::keys($models, $this->parentKey);
        $grouped = [];

        if ($keys !== []) {
            $results = $this->joinedQuery()
                ->addSelect($this->pivotTable . '.' . $this->foreignPivotKey . ' as ' . self::PIVOT_KEY)
                ->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, $keys)
                ->with(...$nested)
                ->get();

            foreach ($results as $related) {
                $parentKey = (string) $related->getAttribute(self::PIVOT_KEY);
                unset($related->{self::PIVOT_KEY});
                $grouped[$parentKey][] = $related;
            }
        }

        foreach ($models as $model) {
            $model->setRelation($name, $grouped[(string) $model->getAttribute($this->parentKey)] ?? []);
        }
    }

    /**
     * Add pivot rows (ignores ids that are already attached).
     *
     * @param int|string|list<int|string> $ids
     */
    public function attach(int|string|array $ids): void
    {
        $new = array_diff(array_map('strval', (array) $ids), $this->attachedIds());

        if ($new === []) {
            return;
        }

        $parentId = $this->parent->getAttribute($this->parentKey);
        $this->pivot()->insert(array_values(array_map(
            fn (string $id): array => [$this->foreignPivotKey => $parentId, $this->relatedPivotKey => ctype_digit($id) ? (int) $id : $id],
            $new,
        )));
    }

    /**
     * Remove pivot rows; all of them when $ids is null. Returns the number removed.
     *
     * @param int|string|list<int|string>|null $ids
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $query = $this->pivot()->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

        if ($ids !== null) {
            $query->whereIn($this->relatedPivotKey, (array) $ids);
        }

        return $query->delete();
    }

    /**
     * Make the attached ids exactly $ids.
     *
     * @param list<int|string> $ids
     * @return array{attached: list<string>, detached: list<string>}
     */
    public function sync(array $ids): array
    {
        $current = $this->attachedIds();
        $wanted = array_values(array_unique(array_map('strval', $ids)));
        $detach = array_values(array_diff($current, $wanted));
        $attach = array_values(array_diff($wanted, $current));

        $this->parent->getConnection()->transaction(function () use ($detach, $attach): void {
            if ($detach !== []) {
                $this->detach($detach);
            }

            if ($attach !== []) {
                $this->attach($attach);
            }
        });

        return ['attached' => $attach, 'detached' => $detach];
    }

    protected function constrainedQuery(): QueryBuilder
    {
        return $this->joinedQuery()->where($this->pivotTable . '.' . $this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));
    }

    private function joinedQuery(): QueryBuilder
    {
        $table = $this->newRelated()->getTable();

        return $this->relatedQuery()
            ->select($table . '.*')
            ->join($this->pivotTable, $this->pivotTable . '.' . $this->relatedPivotKey, '=', $table . '.' . $this->relatedKey);
    }

    private function pivot(): QueryBuilder
    {
        return $this->parent->getConnection()->table($this->pivotTable);
    }

    /**
     * @return list<string>
     */
    private function attachedIds(): array
    {
        return array_map('strval', $this->pivot()->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))->pluck($this->relatedPivotKey));
    }
}
