<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

final class NullBroadcaster implements Broadcaster
{
    public function broadcast(array $channels, string $event, array $data = []): void
    {
    }
}
