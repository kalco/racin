<?php

declare(strict_types=1);

namespace Racin\Database;

use Closure;
use InvalidArgumentException;
use LogicException;
use Racin\Support\Str;

/**
 * Fluent SQL builder. Every value is bound as a parameter; identifiers are
 * validated and quoted. Use Expression / *Raw() methods for SQL fragments.
 */
class QueryBuilder
{
    /** "ilike" is a case-insensitive LIKE on every driver (ILIKE on PostgreSQL, LIKE elsewhere). */
    protected const array OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like', 'ilike', 'not ilike'];

    /** @var list<string|Expression> */
    protected array $columns = ['*'];

    /** @var list<mixed> */
    protected array $selectBindings = [];

    protected bool $distinct = false;

    protected string|Expression $from = '';

    /** @var list<array{type: string, table: string|Expression, first: string, operator: string, second: string}> */
    protected array $joins = [];

    /** @var list<array<string, mixed>> */
    protected array $wheres = [];

    /** @var list<string|Expression> */
    protected array $groups = [];

    /** @var list<array<string, mixed>> */
    protected array $havings = [];

    /** @var list<array{column: string|Expression, direction: string}> */
    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected ?string $lock = null;

    protected bool $skipLocked = false;

    /** @var class-string<Model>|null */
    protected ?string $model = null;

    /** @var list<string> relations to eager load with model results */
    protected array $with = [];

    public function __construct(protected Connection $connection)
    {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Return model instances instead of arrays.
     *
     * @param class-string<Model> $model
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function newQuery(): static
    {
        return new static($this->connection);
    }

    /**
     * Eager load model relations with the results: with('user', 'items.product').
     */
    public function with(string ...$relations): static
    {
        array_push($this->with, ...$relations);

        return $this;
    }

    /**
     * Model scopes: User::active()->byTenant(5) calls User::scopeActive($query) and
     * User::scopeByTenant($query, 5). A scope may modify the query or return a new one.
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $scope = 'scope' . ucfirst($method);

        if ($this->model !== null && method_exists($this->model, $scope)) {
            $result = (new $this->model())->{$scope}($this, ...$arguments);

            return $result ?? $this;
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', $this->model ?? static::class, $method));
    }

    // ---------------------------------------------------------------------
    // SELECT / FROM / JOIN
    // ---------------------------------------------------------------------

    public function from(string|Expression $table): static
    {
        $this->from = $table;

        return $this;
    }

    /**
     * @param string|Expression|list<string|Expression> ...$columns
     */
    public function select(string|Expression|array ...$columns): static
    {
        $this->columns = [];
        $this->selectBindings = [];

        return $this->addSelect(...$columns);
    }

    /**
     * @param string|Expression|list<string|Expression> ...$columns
     */
    public function addSelect(string|Expression|array ...$columns): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        foreach ($columns as $column) {
            foreach (is_array($column) ? $column : [$column] as $item) {
                $this->columns[] = $item;
            }
        }

        if ($this->columns === []) {
            $this->columns = ['*'];
        }

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $sql, array $bindings = []): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = new Expression($sql);
        array_push($this->selectBindings, ...$bindings);

        return $this;
    }

    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function join(string|Expression $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        $type = strtoupper($type);

        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new InvalidArgumentException("Invalid join type [{$type}].");
        }

        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $this->operator($operator),
            'second' => $second,
        ];

        return $this;
    }

    public function leftJoin(string|Expression $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string|Expression $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    // ---------------------------------------------------------------------
    // WHERE
    // ---------------------------------------------------------------------

    /**
     * where('id', 5) / where('age', '>=', 18) / where(['status' => 1, 'type' => 2])
     * / where(fn (QueryBuilder $q) => $q->where(...)->orWhere(...))
     */
    public function where(string|array|Closure|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }

        if ($column instanceof Closure) {
            $nested = $this->newQuery()->from($this->from);
            $column($nested);

            if ($nested->wheres !== []) {
                $this->wheres[] = ['type' => 'nested', 'query' => $nested, 'boolean' => $boolean];
            }

            return $this;
        }

        if (is_array($column)) {
            return $this->where(function (QueryBuilder $query) use ($column): void {
                foreach ($column as $key => $item) {
                    if (is_int($key) && is_array($item)) {
                        $query->where(...array_values($item));
                    } else {
                        $query->where((string) $key, '=', $item);
                    }
                }
            }, null, null, $boolean);
        }

        $operator = $this->operator((string) $operator);

        if ($value === null && in_array($operator, ['=', '<>', '!='], true)) {
            return $this->addNullWhere($column, $operator !== '=', $boolean);
        }

        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];

        return $this;
    }

    public function orWhere(string|array|Closure|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * @param iterable<mixed> $values
     */
    public function whereIn(string|Expression $column, iterable $values, string $boolean = 'AND', bool $not = false): static
    {
        $values = is_array($values) ? array_values($values) : iterator_to_array($values, false);
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'not' => $not, 'boolean' => $boolean];

        return $this;
    }

    /**
     * @param iterable<mixed> $values
     */
    public function whereNotIn(string|Expression $column, iterable $values): static
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    /**
     * @param iterable<mixed> $values
     */
    public function orWhereIn(string|Expression $column, iterable $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereNull(string|Expression $column): static
    {
        return $this->addNullWhere($column, false, 'AND');
    }

    public function whereNotNull(string|Expression $column): static
    {
        return $this->addNullWhere($column, true, 'AND');
    }

    public function orWhereNull(string|Expression $column): static
    {
        return $this->addNullWhere($column, false, 'OR');
    }

    /**
     * @param array{0: mixed, 1: mixed} $values
     */
    public function whereBetween(string|Expression $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        if (count($values) !== 2) {
            throw new InvalidArgumentException('whereBetween() expects exactly two values.');
        }

        $this->wheres[] = ['type' => 'between', 'column' => $column, 'values' => array_values($values), 'not' => $not, 'boolean' => $boolean];

        return $this;
    }

    /**
     * @param array{0: mixed, 1: mixed} $values
     */
    public function whereNotBetween(string|Expression $column, array $values): static
    {
        return $this->whereBetween($column, $values, 'AND', true);
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->wheres[] = ['type' => 'column', 'first' => $first, 'operator' => $this->operator($operator), 'second' => $second, 'boolean' => $boolean];

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $boolean];

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * Apply the callback only when $condition is truthy (handy for optional filters).
     *
     * @param Closure(static, mixed): mixed $callback
     */
    public function when(mixed $condition, Closure $callback): static
    {
        if ($condition) {
            $callback($this, $condition);
        }

        return $this;
    }

    // ---------------------------------------------------------------------
    // GROUP / HAVING / ORDER / LIMIT / LOCK
    // ---------------------------------------------------------------------

    public function groupBy(string|Expression ...$columns): static
    {
        array_push($this->groups, ...$columns);

        return $this;
    }

    public function having(string|Expression $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $this->havings[] = ['type' => 'basic', 'column' => $column, 'operator' => $this->operator($operator), 'value' => $value, 'boolean' => $boolean];

        return $this;
    }

    /**
     * @param list<mixed> $bindings
     */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->havings[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $boolean];

        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        $this->orders[] = ['column' => $column, 'direction' => strtoupper($direction)];

        return $this;
    }

    public function orderByDesc(string|Expression $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $sql): static
    {
        $this->orders[] = ['column' => new Expression($sql), 'direction' => ''];

        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(?int $limit): static
    {
        $this->limit = $limit === null ? null : max(0, $limit);

        return $this;
    }

    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    public function offset(?int $offset): static
    {
        $this->offset = $offset === null ? null : max(0, $offset);

        return $this;
    }

    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    public function forPage(int $page, int $perPage): static
    {
        $page = max(1, $page);

        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    public function lockForUpdate(): static
    {
        $this->lock = 'update';

        return $this;
    }

    /**
     * With lockForUpdate(): skip rows other transactions hold (MySQL 8+, MariaDB 10.6+, PostgreSQL).
     */
    public function skipLocked(bool $skip = true): static
    {
        $this->skipLocked = $skip;

        return $this;
    }

    public function sharedLock(): static
    {
        $this->lock = 'shared';

        return $this;
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>|list<Model>
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        $rows = $this->connection->select($sql, $bindings);

        if ($this->model === null) {
            return $rows;
        }

        $models = array_map($this->model::newFromRow(...), $rows);

        if ($this->with !== []) {
            $this->model::eagerLoadRelations($models, $this->with);
        }

        return $models;
    }

    /**
     * @return array<string, mixed>|Model|null
     */
    public function first(): array|Model|null
    {
        return (clone $this)->limit(1)->get()[0] ?? null;
    }

    /**
     * First result or a 404 (ModelNotFoundException for model queries).
     *
     * @return array<string, mixed>|Model
     */
    public function firstOrFail(): array|Model
    {
        return $this->first() ?? throw ($this->model !== null
            ? new ModelNotFoundException(Str::classBasename($this->model) . ' not found.')
            : new ModelNotFoundException('Record not found.'));
    }

    /**
     * @return array<string, mixed>|Model|null
     */
    public function find(int|string $id, string $key = 'id'): array|Model|null
    {
        return (clone $this)->where($key, '=', $id)->first();
    }

    public function value(string|Expression $column): mixed
    {
        $query = (clone $this)->select($column)->limit(1);
        $query->model = null;
        [$sql, $bindings] = $query->compileSelect();

        return $this->connection->scalar($sql, $bindings);
    }

    /**
     * Values of a single column, optionally keyed by another column.
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $query = (clone $this)->select($key === null ? [$column] : [$column, $key]);
        $query->model = null;
        $rows = $query->get();

        $valueKey = self::resultKey($column);

        return $key === null
            ? array_column($rows, $valueKey)
            : array_column($rows, $valueKey, self::resultKey($key));
    }

    public function exists(): bool
    {
        $query = (clone $this)->select(new Expression('1'))->limit(1);
        $query->model = null;
        $query->orders = [];
        [$sql, $bindings] = $query->compileSelect();

        return $this->connection->selectOne($sql, $bindings) !== null;
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): int|float
    {
        return $this->numeric($this->aggregate('SUM', $column) ?? 0);
    }

    public function avg(string $column): int|float|null
    {
        $value = $this->aggregate('AVG', $column);

        return $value === null ? null : $this->numeric($value);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = $this->count();
        $items = $total > 0 ? (clone $this)->forPage($page, $perPage)->get() : [];

        return new Paginator($items, $total, $perPage, $page);
    }

    /**
     * Process the result in chunks to keep memory low.
     *
     * @param Closure(list<array<string, mixed>>|list<Model>, int): (bool|void) $callback return false to stop
     */
    public function chunk(int $size, Closure $callback): void
    {
        $page = 1;

        do {
            $results = (clone $this)->forPage($page, $size)->get();

            if ($results === [] || $callback($results, $page) === false) {
                return;
            }

            $page++;
        } while (count($results) === $size);
    }

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * Insert one row (assoc array) or many rows (list of assoc arrays with the same keys).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $rows = array_is_list($values) ? $values : [$values];
        $columns = array_keys($rows[0]);
        $bindings = [];

        foreach ($rows as $index => $row) {
            if (array_keys($row) !== $columns) {
                throw new InvalidArgumentException("Row {$index} does not have the same columns (in the same order) as the first row.");
            }

            array_push($bindings, ...array_values($row));
        }

        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->connection->wrapTable($this->from),
            implode(', ', array_map($this->connection->wrap(...), $columns)),
            implode(', ', array_fill(0, count($rows), $placeholders)),
        );

        return $this->connection->insert($sql, $bindings);
    }

    /**
     * Insert a row and return its auto-increment id.
     *
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $key = 'id'): int|string
    {
        if ($this->connection->driver() === 'pgsql') {
            $columns = array_keys($values);
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                $this->connection->wrapTable($this->from),
                implode(', ', array_map($this->connection->wrap(...), $columns)),
                implode(', ', array_fill(0, count($columns), '?')),
                $this->connection->wrap($key),
            );
            $id = (string) $this->connection->scalar($sql, array_values($values));
        } else {
            $this->insert($values);
            $id = $this->connection->lastInsertId();
        }

        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /**
     * @param array<string, mixed> $values column => value (Expression for raw SQL)
     * @return int affected rows
     */
    public function update(array $values): int
    {
        $this->guardAgainstMissingWhere('update');

        if ($values === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = $this->connection->wrap($column) . ' = ' . $value->value;
            } else {
                $sets[] = $this->connection->wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }

        $where = $this->compileWheres($this->wheres, $bindings);
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->connection->wrapTable($this->from), implode(', ', $sets), $where);

        return $this->connection->update($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $extra other columns to set at the same time
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $wrapped = $this->connection->wrap($column);
        $operator = $amount < 0 ? '-' : '+';

        return $this->update([$column => new Expression("{$wrapped} {$operator} " . abs($amount))] + $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * @return int affected rows
     */
    public function delete(): int
    {
        $this->guardAgainstMissingWhere('delete');

        $bindings = [];
        $where = $this->compileWheres($this->wheres, $bindings);
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->connection->wrapTable($this->from), $where);

        return $this->connection->delete($sql, $bindings);
    }

    // ---------------------------------------------------------------------
    // Compilation
    // ---------------------------------------------------------------------

    public function toSql(): string
    {
        return $this->compileSelect()[0];
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->compileSelect()[1];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    protected function compileSelect(): array
    {
        if ($this->from === '') {
            throw new LogicException('No table selected; call from() or table() first.');
        }

        $bindings = $this->selectBindings;
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', array_map($this->connection->wrap(...), $this->columns))
            . ' FROM ' . $this->connection->wrapTable($this->from);

        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $this->connection->wrapTable($join['table']),
                $this->connection->wrap($join['first']),
                $join['operator'],
                $this->connection->wrap($join['second']),
            );
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres($this->wheres, $bindings);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map($this->connection->wrap(...), $this->groups));
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . $this->compileWheres($this->havings, $bindings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (array $order): string => trim($this->connection->wrap($order['column']) . ' ' . $order['direction']),
                $this->orders,
            ));
        }

        $driver = $this->connection->driver();

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset !== null && $driver !== 'pgsql') {
            $sql .= $driver === 'sqlite' ? ' LIMIT -1' : ' LIMIT 18446744073709551615';
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        if ($this->lock !== null && $driver !== 'sqlite') {
            $sql .= match (true) {
                $this->lock === 'update' => ' FOR UPDATE',
                $driver === 'pgsql' => ' FOR SHARE',
                default => ' LOCK IN SHARE MODE',
            };

            if ($this->skipLocked && $this->lock === 'update') {
                $sql .= ' SKIP LOCKED';
            }
        }

        return [$sql, $bindings];
    }

    /**
     * @param list<array<string, mixed>> $wheres
     * @param list<mixed> $bindings
     */
    protected function compileWheres(array $wheres, array &$bindings): string
    {
        $sql = '';

        foreach ($wheres as $index => $where) {
            $clause = match ($where['type']) {
                'basic' => $this->compileBasic($where, $bindings),
                'in' => $this->compileIn($where, $bindings),
                'null' => $this->connection->wrap($where['column']) . ($where['not'] ? ' IS NOT NULL' : ' IS NULL'),
                'between' => $this->compileBetween($where, $bindings),
                'column' => $this->connection->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->connection->wrap($where['second']),
                'raw' => $this->compileRaw($where, $bindings),
                'nested' => '(' . $this->compileWheres($where['query']->wheres, $bindings) . ')',
            };

            $sql .= ($index === 0 ? '' : ' ' . $where['boolean'] . ' ') . $clause;
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $where
     * @param list<mixed> $bindings
     */
    private function compileBasic(array $where, array &$bindings): string
    {
        $column = $this->connection->wrap($where['column']);

        if ($where['value'] instanceof Expression) {
            return "{$column} " . $this->sqlOperator($where['operator']) . " {$where['value']->value}";
        }

        $bindings[] = $where['value'];

        return "{$column} " . $this->sqlOperator($where['operator']) . ' ?';
    }

    private function sqlOperator(string $operator): string
    {
        if (str_contains($operator, 'ilike') && $this->connection->driver() !== 'pgsql') {
            $operator = str_replace('ilike', 'like', $operator);
        }

        return strtoupper($operator);
    }

    /**
     * @param array<string, mixed> $where
     * @param list<mixed> $bindings
     */
    private function compileIn(array $where, array &$bindings): string
    {
        if ($where['values'] === []) {
            return $where['not'] ? '1 = 1' : '0 = 1';
        }

        array_push($bindings, ...$where['values']);

        return $this->connection->wrap($where['column'])
            . ($where['not'] ? ' NOT IN (' : ' IN (')
            . implode(', ', array_fill(0, count($where['values']), '?')) . ')';
    }

    /**
     * @param array<string, mixed> $where
     * @param list<mixed> $bindings
     */
    private function compileBetween(array $where, array &$bindings): string
    {
        array_push($bindings, ...$where['values']);

        return $this->connection->wrap($where['column']) . ($where['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?');
    }

    /**
     * @param array<string, mixed> $where
     * @param list<mixed> $bindings
     */
    private function compileRaw(array $where, array &$bindings): string
    {
        array_push($bindings, ...$where['bindings']);

        return $where['sql'];
    }

    protected function aggregate(string $function, string $column): mixed
    {
        $expression = $function . '(' . ($column === '*' ? '*' : $this->connection->wrap($column)) . ')';
        $query = clone $this;
        $query->model = null;
        $query->orders = [];

        if ($this->groups !== [] || $this->distinct || $this->limit !== null || $this->offset !== null) {
            [$innerSql, $bindings] = $query->compileSelect();
            $sql = "SELECT {$function}(" . ($column === '*' ? '*' : $this->connection->wrap(self::resultKey($column))) . ") AS aggregate FROM ({$innerSql}) AS sub";

            return $this->connection->scalar($sql, $bindings);
        }

        $query->columns = [new Expression($expression . ' AS aggregate')];
        $query->selectBindings = [];
        [$sql, $bindings] = $query->compileSelect();

        return $this->connection->scalar($sql, $bindings);
    }

    protected function operator(string $operator): string
    {
        $operator = strtolower(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid SQL operator [{$operator}].");
        }

        return $operator;
    }

    private function addNullWhere(string|Expression $column, bool $not, string $boolean): static
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'not' => $not, 'boolean' => $boolean];

        return $this;
    }

    private function guardAgainstMissingWhere(string $operation): void
    {
        if ($this->wheres === []) {
            throw new LogicException("Refusing to {$operation} every row of [{$this->from}] without a where clause. Use whereRaw('1 = 1') if you really mean it.");
        }
    }

    private function numeric(mixed $value): int|float
    {
        return is_numeric($value) ? $value + 0 : 0;
    }

    /**
     * "users.name as n" -> "n", "users.name" -> "name"
     */
    private static function resultKey(string $column): string
    {
        if (preg_match('/\s+as\s+(\S+)$/i', $column, $match) === 1) {
            return $match[1];
        }

        $position = strrpos($column, '.');

        return $position === false ? $column : substr($column, $position + 1);
    }
}
