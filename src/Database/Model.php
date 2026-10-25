<?php

declare(strict_types=1);

namespace Racin\Database;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use Racin\Database\Relations\BelongsTo;
use Racin\Database\Relations\BelongsToMany;
use Racin\Database\Relations\HasMany;
use Racin\Database\Relations\HasOne;
use Racin\Database\Relations\Relation;
use Racin\Support\Str;
use Racin\Validation\Validator;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Active-record model, in the spirit of Rails' ActiveRecord.
 *
 *     final class Post extends Model
 *     {
 *         protected array $fillable = ['title', 'body', 'user_id'];
 *         protected array $casts = ['published' => 'bool', 'meta' => 'array'];
 *         protected array $rules = ['title' => 'required|string|max:200', 'slug' => 'unique:posts,slug,{id}'];
 *
 *         public function user(): BelongsTo { return $this->belongsTo(User::class); }
 *         public function comments(): HasMany { return $this->hasMany(Comment::class); }
 *
 *         public function scopePublished(QueryBuilder $query): void { $query->where('published', 1); }
 *
 *         protected function beforeSave(): void { $this->slug ??= Str::slug($this->title); }
 *     }
 *
 *     $posts = Post::published()->with('user', 'comments.author')->latest()->paginate(20);
 *     $post->user->name;                       // lazy-loads the relation once
 *     $post->comments()->create(['body' => 'Nice']);
 *
 * Static calls not defined here (where, orderBy, paginate, scopes...) go to a
 * QueryBuilder that returns model instances.
 *
 * @mixin QueryBuilder
 */
abstract class Model implements JsonSerializable
{
    public const ?string CREATED_AT = 'created_at';

    public const ?string UPDATED_AT = 'updated_at';

    protected static ?DatabaseManager $resolver = null;

    /** @var array<class-string, array<string, bool>> which methods return a Relation, per class */
    private static array $relationMethods = [];

    /** Connection name from config/database.php; null = default */
    protected ?string $connection = null;

    /** Table name; defaults to the plural snake_case class name (UserProfile -> user_profiles) */
    protected string $table = '';

    protected string $primaryKey = 'id';

    protected bool $incrementing = true;

    protected bool $timestamps = true;

    /** @var list<string> attributes that may be mass assigned via fill()/create() */
    protected array $fillable = [];

    /** @var list<string> attributes (and relations) left out of toArray()/JSON */
    protected array $hidden = [];

    /** @var array<string, string> int|float|bool|string|array|json|datetime */
    protected array $casts = [];

    /**
     * Validation rules checked on every save (see Validation\Validator). "{id}" is replaced
     * with the record's key, e.g. 'email' => 'required|email|unique:users,email,{id}'.
     *
     * @var array<string, string|list<mixed>>
     */
    protected array $rules = [];

    /** @var array<string, string> custom validation messages */
    protected array $messages = [];

    /** @var array<string, mixed> raw database values */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    /** @var array<string, Model|list<Model>|null> loaded relations */
    protected array $relations = [];

    public bool $exists = false;

    /**
     * @param array<string, mixed> $attributes mass-assigned through fill()
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function setConnectionResolver(?DatabaseManager $resolver): void
    {
        static::$resolver = $resolver;
    }

    public function getConnection(): Connection
    {
        $resolver = static::$resolver ?? throw new RuntimeException('No database manager set for models. Is the application booted?');

        return $resolver->connection($this->connection);
    }

    public function getTable(): string
    {
        return $this->table !== '' ? $this->table : Str::plural(Str::snake(Str::classBasename($this)));
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    // ---------------------------------------------------------------------
    // Querying
    // ---------------------------------------------------------------------

    public static function query(): QueryBuilder
    {
        return (new static())->newQuery();
    }

    public function newQuery(): QueryBuilder
    {
        return $this->getConnection()->table($this->getTable())->setModel(static::class);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        /** @var list<static> */
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        $model = new static();

        /** @var static|null */
        return $model->newQuery()->find($id, $model->getKeyName());
    }

    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw ModelNotFoundException::for(static::class, $id);
    }

    /**
     * First record matching the conditions: findBy('email', $email) or findBy(['email' => $email, 'active' => 1]).
     *
     * @param string|array<string, mixed> $column
     */
    public static function findBy(string|array $column, mixed $value = null): ?static
    {
        /** @var static|null */
        return static::query()->where(is_array($column) ? $column : [$column => $value])->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Find by $attributes or create with $attributes + $values.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        return static::findBy($attributes) ?? static::create($attributes + $values);
    }

    /**
     * Find by $attributes and update with $values, or create.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::findBy($attributes);

        if ($model === null) {
            return static::create($attributes + $values);
        }

        $model->update($values);

        return $model;
    }

    /**
     * Delete records by primary key (callbacks are not run); returns the number deleted.
     */
    public static function destroy(int|string ...$ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $model = new static();

        return $model->newQuery()->whereIn($model->getKeyName(), $ids)->delete();
    }

    /**
     * Build an existing model from a database row (used by the query builder).
     *
     * @param array<string, mixed> $row
     */
    public static function newFromRow(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    /**
     * @param list<mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return static::query()->{$method}(...$arguments);
    }

    // ---------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------

    /**
     * Validate (unless $validate is false), run callbacks and insert or update.
     * Returns false when a before* callback returned false.
     */
    public function save(bool $validate = true): bool
    {
        $creating = !$this->exists;

        if ($validate) {
            $this->callHook('beforeValidation');
            $this->validate();
        }

        if ($this->callHook('beforeSave') === false || $this->callHook($creating ? 'beforeCreate' : 'beforeUpdate') === false) {
            return false;
        }

        $now = $this->timestamps ? $this->freshTimestamp() : null;

        if (!$creating) {
            $dirty = $this->getDirty();

            if ($dirty === []) {
                return true;
            }

            if ($now !== null && static::UPDATED_AT !== null && !array_key_exists(static::UPDATED_AT, $dirty)) {
                $this->attributes[static::UPDATED_AT] = $dirty[static::UPDATED_AT] = $now;
            }

            $this->newQuery()->where($this->primaryKey, '=', $this->getKeyOrFail())->update($dirty);
        } else {
            if ($now !== null) {
                foreach ([static::CREATED_AT, static::UPDATED_AT] as $column) {
                    if ($column !== null && !isset($this->attributes[$column])) {
                        $this->attributes[$column] = $now;
                    }
                }
            }

            $query = $this->newQuery();

            if ($this->incrementing && $this->getKey() === null) {
                $this->attributes[$this->primaryKey] = $query->insertGetId($this->attributes, $this->primaryKey);
            } else {
                $query->insert($this->attributes);
            }

            $this->exists = true;
        }

        $this->original = $this->attributes;
        $this->callHook($creating ? 'afterCreate' : 'afterUpdate');
        $this->callHook('afterSave');

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (!$this->exists || $this->callHook('beforeDelete') === false) {
            return false;
        }

        $deleted = $this->newQuery()->where($this->primaryKey, '=', $this->getKeyOrFail())->delete() > 0;
        $this->exists = false;
        $this->callHook('afterDelete');

        return $deleted;
    }

    /**
     * Reload attributes from the database (loaded relations are dropped).
     */
    public function refresh(): static
    {
        $fresh = $this->newQuery()->where($this->primaryKey, '=', $this->getKeyOrFail())->first()
            ?? throw ModelNotFoundException::for(static::class, (string) $this->getKey());

        $this->attributes = $fresh->attributes;
        $this->original = $fresh->original;
        $this->relations = [];

        return $this;
    }

    /**
     * Check $rules against the current attributes; throws ValidationException (422).
     * New records are checked against every rule, existing ones only for attributes they have.
     */
    public function validate(): void
    {
        if ($this->rules === []) {
            return;
        }

        $id = $this->getKey();
        $rules = $this->exists ? array_intersect_key($this->rules, $this->attributes) : $this->rules;
        $rules = array_map(
            static fn (string|array $rule): string|array => is_string($rule) ? str_replace('{id}', $id === null ? 'NULL' : (string) $id, $rule) : $rule,
            $rules,
        );

        // Validate what was assigned, not the cast value: (int) "abc" would pass as 0.
        // JSON columns are decoded so "array" rules see the array.
        $data = [];

        foreach ($this->attributes as $key => $value) {
            $data[$key] = in_array($this->casts[$key] ?? null, ['array', 'json'], true) && is_string($value)
                ? json_decode($value, true)
                : $value;
        }

        Validator::make($data, $rules, $this->messages)->validate();
    }

    // ---------------------------------------------------------------------
    // Relations
    // ---------------------------------------------------------------------

    /**
     * @param class-string<Model> $related
     * @param string|null $foreignKey on this model, default "<related>_id"
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, string $ownerKey = 'id'): BelongsTo
    {
        return new BelongsTo($this, $related, $foreignKey ?? Str::snake(Str::classBasename($related)) . '_id', $ownerKey);
    }

    /**
     * @param class-string<Model> $related
     * @param string|null $foreignKey on the related model, default "<this model>_id"
     */
    protected function hasMany(string $related, ?string $foreignKey = null, string $localKey = 'id'): HasMany
    {
        return new HasMany($this, $related, $foreignKey ?? Str::snake(Str::classBasename($this)) . '_id', $localKey);
    }

    /**
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, string $localKey = 'id'): HasOne
    {
        return new HasOne($this, $related, $foreignKey ?? Str::snake(Str::classBasename($this)) . '_id', $localKey);
    }

    /**
     * @param class-string<Model> $related
     * @param string|null $pivotTable default: both singular names in alphabetical order ("role_user")
     */
    protected function belongsToMany(string $related, ?string $pivotTable = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null): BelongsToMany
    {
        $mine = Str::snake(Str::classBasename($this));
        $theirs = Str::snake(Str::classBasename($related));
        $pair = [$mine, $theirs];
        sort($pair);

        return new BelongsToMany($this, $related, $pivotTable ?? implode('_', $pair), $foreignPivotKey ?? $mine . '_id', $relatedPivotKey ?? $theirs . '_id');
    }

    /**
     * Whether $name is a method declared to return a Relation.
     */
    public function isRelation(string $name): bool
    {
        $class = static::class;

        if (!isset(self::$relationMethods[$class][$name])) {
            $isRelation = false;

            if (method_exists($this, $name)) {
                $method = new ReflectionMethod($this, $name);
                $type = $method->getReturnType();
                $isRelation = $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0
                    && $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true);
            }

            self::$relationMethods[$class][$name] = $isRelation;
        }

        return self::$relationMethods[$class][$name];
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    public function getRelation(string $name): mixed
    {
        if (!$this->relationLoaded($name)) {
            if (!$this->isRelation($name)) {
                throw new LogicException(static::class . "::{$name}() is not a relation.");
            }

            $this->relations[$name] = $this->{$name}()->getResults();
        }

        return $this->relations[$name];
    }

    /**
     * @param Model|list<Model>|null $value
     */
    public function setRelation(string $name, Model|array|null $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /**
     * @return array<string, Model|list<Model>|null>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Eager load relations on this model: $post->load('comments.author').
     */
    public function load(string ...$relations): static
    {
        static::eagerLoadRelations([$this], $relations);

        return $this;
    }

    /**
     * Load relations ("comments", "comments.author") for many models with one query per relation.
     *
     * @param list<Model> $models
     * @param list<string> $paths
     */
    public static function eagerLoadRelations(array $models, array $paths): void
    {
        if ($models === [] || $paths === []) {
            return;
        }

        $tree = [];

        foreach ($paths as $path) {
            [$name, $rest] = array_pad(explode('.', $path, 2), 2, null);
            $tree[$name] ??= [];

            if ($rest !== null) {
                $tree[$name][] = $rest;
            }
        }

        foreach ($tree as $name => $nested) {
            $first = $models[0];

            if (!$first->isRelation($name)) {
                throw new LogicException($first::class . "::{$name}() is not a relation (eager loading \"{$name}\").");
            }

            $first->{$name}()->eagerLoad($models, $name, array_values(array_unique($nested)));
        }
    }

    // ---------------------------------------------------------------------
    // Attributes
    // ---------------------------------------------------------------------

    /**
     * Mass assign; keys not listed in $fillable are ignored.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable, true)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Assign without the $fillable check (only for trusted data).
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        return $this->castGet($key, $this->attributes[$key]);
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->castSet($key, $value);

        return $this;
    }

    /**
     * @return array<string, mixed> attributes changed since load/save
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();

        return $key === null ? $dirty !== [] : array_key_exists($key, $dirty);
    }

    public function getOriginal(?string $key = null): mixed
    {
        return $key === null ? $this->original : ($this->original[$key] ?? null);
    }

    /**
     * Cast attributes plus loaded relations, minus $hidden.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $value = $this->castGet($key, $value);
                $result[$key] = $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
            }
        }

        foreach ($this->relations as $name => $related) {
            if (!in_array($name, $this->hidden, true)) {
                $result[$name] = match (true) {
                    $related instanceof self => $related->toArray(),
                    is_array($related) => array_map(static fn (Model $model): array => $model->toArray(), $related),
                    default => null,
                };
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Attributes first, then relations (loaded on first access).
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castGet($key, $this->attributes[$key]);
        }

        if ($this->relationLoaded($key) || $this->isRelation($key)) {
            return $this->getRelation($key);
        }

        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->getAttribute($key) !== null;
        }

        return ($this->relationLoaded($key) || $this->isRelation($key)) && $this->getRelation($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    protected function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Call an optional lifecycle method (beforeSave, afterCreate, ...).
     */
    private function callHook(string $hook): mixed
    {
        return method_exists($this, $hook) ? $this->{$hook}() : null;
    }

    private function getKeyOrFail(): mixed
    {
        return $this->getKey() ?? throw new LogicException('Model has no primary key value.');
    }

    private function castGet(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'decimal' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            'datetime' => $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value),
            default => throw new LogicException("Unknown cast [{$this->casts[$key]}] on " . static::class . "::\${$key}."),
        };
    }

    private function castSet(string $key, mixed $value): mixed
    {
        if ($value === null || !isset($this->casts[$key])) {
            return $value;
        }

        return match ($this->casts[$key]) {
            // "false", "0", "off", "no" must not become 1 just because they are non-empty strings
            'bool', 'boolean' => (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value) ? 1 : 0,
            'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            default => $value,
        };
    }
}
