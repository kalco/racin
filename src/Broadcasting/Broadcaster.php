<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

interface Broadcaster
{
    /**
     * Send an event to everyone subscribed to the channels. Must never throw:
     * a missing real-time server should not break the request.
     *
     * @param list<string> $channels
     * @param array<string, mixed> $data
     */
    public function broadcast(array $channels, string $event, array $data = []): void;
}
