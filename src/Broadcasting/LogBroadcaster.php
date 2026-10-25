<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

use Psr\Log\LoggerInterface;

final class LogBroadcaster implements Broadcaster
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function broadcast(array $channels, string $event, array $data = []): void
    {
        $this->logger->info("Broadcast [{$event}] to " . implode(', ', $channels), ['data' => $data]);
    }
}
