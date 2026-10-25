<?php

declare(strict_types=1);

namespace Racin\Database\Schema;

use InvalidArgumentException;

final class ForeignKeyDefinition
{
    private const array ACTIONS = ['cascade', 'restrict', 'set null', 'no action'];

    public string $referencesColumn = 'id';

    public string $onTable = '';

    public string $onDelete = 'restrict';

    public string $onUpdate = 'no action';

    public function __construct(public readonly string $column, public string $name)
    {
    }

    public function references(string $column): self
    {
        $this->referencesColumn = $column;

        return $this;
    }

    public function on(string $table): self
    {
        $this->onTable = $table;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    private static function action(string $action): string
    {
        $action = strtolower($action);

        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid foreign key action [{$action}].");
        }

        return $action;
    }
}
