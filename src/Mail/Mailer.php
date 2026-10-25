<?php

declare(strict_types=1);

namespace Racin\Mail;

use Racin\Queue\QueueManager;

/**
 * Sends messages now, or queues them (SendQueuedMail).
 */
final class Mailer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?Address $defaultFrom = null,
        private readonly ?QueueManager $queues = null,
    ) {
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function send(Message $message): void
    {
        $this->transport->send($this->withSender($message));
    }

    /**
     * Send from a queue worker. Falls back to sending now when no queue is configured.
     */
    public function queue(Message $message, ?string $queue = null): void
    {
        $message = $this->withSender($message);
        $message->toMime(); // fail now rather than in the worker

        if ($this->queues === null) {
            $this->send($message);

            return;
        }

        $this->queues->push(new SendQueuedMail($message->toArray()), 0, $queue);
    }

    private function withSender(Message $message): Message
    {
        if ($message->getFrom() === null && $this->defaultFrom !== null) {
            $message->from($this->defaultFrom->email, $this->defaultFrom->name);
        }

        return $message;
    }
}
