<?php

declare(strict_types=1);

namespace Racin\Mail;

interface Transport
{
    /**
     * Deliver the message. Throws TransportException on failure.
     */
    public function send(Message $message): void;
}
