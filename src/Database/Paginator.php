<?php

declare(strict_types=1);

namespace Racin\Database;

use Closure;
use JsonSerializable;

/**
 * A page of results. Serialises to {"data": [...], "meta": {...}}.
 */
final class Paginator implements JsonSerializable
{
    public readonly int $lastPage;

    /**
     * @param list<mixed> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }

    /**
     * Transform each item (e.g. into an API resource array).
     *
     * @param Closure(mixed): mixed $callback
     */
    public function map(Closure $callback): self
    {
        return new self(array_map($callback, $this->items), $this->total, $this->perPage, $this->currentPage);
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * @return array{data: list<mixed>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => array_map(
                static fn (mixed $item): mixed => $item instanceof JsonSerializable ? $item->jsonSerialize() : $item,
                $this->items,
            ),
            'meta' => [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage,
            ],
        ];
    }
}
