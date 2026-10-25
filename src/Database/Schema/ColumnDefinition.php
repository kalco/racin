<?php

declare(strict_types=1);

namespace Racin\Database\Schema;

use Racin\Database\Expression;
use Racin\Support\Str;

/**
 * One column in a Blueprint. Modifiers are chainable:
 *
 *     $table->string('email')->unique();
 *     $table->decimal('price', 10, 2)->default(0);
 *     $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 */
final class ColumnDefinition
{
    public bool $nullable = false;

    public bool $unsigned = false;

    public bool $autoIncrement = false;

    public bool $hasDefault = false;

    public mixed $default = null;

    /**
     * @param array<string, mixed> $parameters type parameters (length, precision, values...)
     */
    public function __construct(
        private readonly Blueprint $blueprint,
        public readonly string $name,
        public readonly string $type,
        public readonly array $parameters = [],
    ) {
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP (date/time columns).
     */
    public function useCurrent(): self
    {
        return $this->default(new Expression('CURRENT_TIMESTAMP'));
    }

    public function unique(?string $name = null): self
    {
        $this->blueprint->unique([$this->name], $name);

        return $this;
    }

    public function index(?string $name = null): self
    {
        $this->blueprint->index([$this->name], $name);

        return $this;
    }

    /**
     * Foreign key to $table.$column; the table defaults to the plural of the column name without "_id".
     */
    public function constrained(?string $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        $table ??= Str::plural((string) preg_replace('/_id$/', '', $this->name));

        return $this->blueprint->foreign($this->name)->references($column)->on($table);
    }
}
