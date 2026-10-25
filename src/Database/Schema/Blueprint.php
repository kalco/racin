<?php

declare(strict_types=1);

namespace Racin\Database\Schema;

/**
 * Describes a table to create, or changes to apply to an existing table.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    public array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: string}> */
    public array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    public array $foreignKeys = [];

    /** @var list<array{name: string, sql: string}> */
    public array $checks = [];

    /** @var list<string> */
    public array $dropColumns = [];

    /** @var list<string> */
    public array $dropIndexes = [];

    public ?string $primaryKey = null;

    public function __construct(public readonly string $table)
    {
    }

    // ------------------------------------------------------------------ columns

    /**
     * Auto-incrementing unsigned BIGINT primary key.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        $column = $this->addColumn('bigInteger', $name)->unsigned();
        $column->autoIncrement = true;
        $this->primaryKey = $name;

        return $column;
    }

    /**
     * Unsigned BIGINT for a foreign key; chain ->constrained().
     */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name)->unsigned();
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('char', $name, ['length' => $length]);
    }

    public function uuid(string $name = 'uuid'): ColumnDefinition
    {
        return $this->char($name, 36);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    /**
     * Stored as 0/1 on every driver (TINYINT(1) / INTEGER / SMALLINT), matching the model "bool" cast.
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('double', $name);
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn('dateTime', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->dateTime($name);
    }

    /**
     * Nullable created_at and updated_at.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    /**
     * @param list<string> $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn('enum', $name, ['values' => $values]);
    }

    // ------------------------------------------------------------- constraints

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): void
    {
        $this->indexes[] = ['type' => 'primary', 'columns' => (array) $columns, 'name' => $this->indexName('primary', (array) $columns)];
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name ?? $this->indexName('unique', (array) $columns)];
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name ?? $this->indexName('index', (array) $columns)];
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $foreign = new ForeignKeyDefinition($column, $this->indexName('foreign', [$column]));
        $this->foreignKeys[] = $foreign;

        return $foreign;
    }

    /**
     * CHECK constraint with raw SQL (never from user input).
     */
    public function check(string $sql, ?string $name = null): void
    {
        $this->checks[] = ['name' => $name ?? $this->table . '_check_' . (count($this->checks) + 1), 'sql' => $sql];
    }

    // -------------------------------------------------------------- alterations

    public function dropColumn(string ...$columns): void
    {
        array_push($this->dropColumns, ...$columns);
    }

    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $name;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        $column = new ColumnDefinition($this, $name, $type, $parameters);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param list<string> $columns
     */
    private function indexName(string $type, array $columns): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '_', $this->table . '_' . implode('_', $columns) . '_' . $type));
    }
}
