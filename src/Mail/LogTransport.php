<?php

declare(strict_types=1);

namespace Racin\Mail;

use Psr\Log\LoggerInterface;

/**
 * Writes messages to the log instead of sending them (local development).
 */
final class LogTransport implements Transport
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(Message $message): void
    {
        $this->logger->info('Mail to ' . implode(', ', $message->recipients()) . ': ' . $message->getSubject(), [
            'mime' => $message->toMime(),
        ]);
    }
}
