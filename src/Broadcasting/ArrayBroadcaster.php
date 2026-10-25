<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

/**
 * Records broadcasts in memory (tests).
 */
final class ArrayBroadcaster implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, data: array<string, mixed>}> */
    private array $events = [];

    public function broadcast(array $channels, string $event, array $data = []): void
    {
        $this->events[] = ['channels' => array_values($channels), 'event' => $event, 'data' => $data];
    }

    /**
     * @return list<array{channels: list<string>, event: string, data: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
