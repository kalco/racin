<?php

declare(strict_types=1);

namespace Racin\Database\Relations;

use Racin\Database\Model;
use Racin\Database\QueryBuilder;

/**
 * Base class for model relations. Calling query-builder methods on a relation
 * constrains them to the parent: $user->orders()->where('status', 'paid')->get().
 *
 * @mixin QueryBuilder
 */
abstract class Relation
{
    private ?QueryBuilder $query = null;

    /** @var list<array{0: string, 1: list<mixed>}> builder calls chained on the relation (orderBy, where...), replayed when eager loading */
    private array $calls = [];

    /**
     * @param class-string<Model> $related
     */
    public function __construct(protected readonly Model $parent, protected readonly string $related)
    {
    }

    /**
     * The related records for this parent (a model, null, or a list of models).
     */
    abstract public function getResults(): mixed;

    /**
     * Load the relation for many parents with one query and attach it to each of them.
     *
     * @param list<Model> $models
     * @param list<string> $nested relations to eager load on the related models
     */
    abstract public function eagerLoad(array $models, string $name, array $nested): void;

    /**
     * Query for the related records, constrained to this parent.
     */
    abstract protected function constrainedQuery(): QueryBuilder;

    public function query(): QueryBuilder
    {
        return $this->query ??= $this->constrainedQuery();
    }

    /**
     * @return class-string<Model>
     */
    public function relatedClass(): string
    {
        return $this->related;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->query()->{$method}(...$arguments);

        if ($result instanceof QueryBuilder) {
            $this->calls[] = [$method, $arguments];

            return $this;
        }

        return $result;
    }

    protected function newRelated(): Model
    {
        return new ($this->related)();
    }

    /**
     * Query on the related model without the parent constraint, but with the
     * extra conditions chained in the relation definition (e.g. ->orderBy('name')).
     */
    protected function relatedQuery(): QueryBuilder
    {
        $query = $this->related::query();

        foreach ($this->calls as [$method, $arguments]) {
            $query->{$method}(...$arguments);
        }

        return $query;
    }

    /**
     * @param list<Model> $models
     * @return list<int|string>
     */
    protected static function keys(array $models, string $attribute): array
    {
        $keys = [];

        foreach ($models as $model) {
            $value = $model->getAttribute($attribute);

            if ($value !== null) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }
}
