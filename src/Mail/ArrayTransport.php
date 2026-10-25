<?php

declare(strict_types=1);

namespace Racin\Mail;

/**
 * Keeps sent messages in memory (tests).
 */
final class ArrayTransport implements Transport
{
    /** @var list<Message> */
    private array $messages = [];

    public function send(Message $message): void
    {
        $message->toMime(); // validate like a real transport would
        $this->messages[] = $message;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
