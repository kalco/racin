<?php

declare(strict_types=1);

namespace Racin\Mail;

use Racin\Queue\Job;

final class SendQueuedMail extends Job
{
    public int $tries = 5;

    /** @var list<int> */
    public int|array $backoff = [10, 60, 300, 900];

    /**
     * @param array<string, mixed> $message Message::toArray()
     */
    public function __construct(public readonly array $message)
    {
    }

    public function queue(): string
    {
        return 'mail';
    }

    public function handle(Mailer $mailer): void
    {
        $mailer->send(Message::fromArray($this->message));
    }
}
