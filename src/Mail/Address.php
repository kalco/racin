<?php

declare(strict_types=1);

namespace Racin\Mail;

use InvalidArgumentException;

final class Address
{
    public readonly string $email;

    public readonly ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n<>]/', $email) === 1) {
            throw new InvalidArgumentException("Invalid email address [{$email}].");
        }

        $this->email = $email;
        $this->name = $name === null || trim($name) === '' ? null : trim((string) preg_replace('/[\r\n]+/', ' ', $name));
    }

    /**
     * RFC 5322 header form: "Name" <email>, with the name encoded when not ASCII.
     */
    public function toHeader(): string
    {
        if ($this->name === null) {
            return $this->email;
        }

        $name = preg_match('/^[\x20-\x7E]*$/', $this->name) === 1
            ? '"' . addcslashes($this->name, '"\\') . '"'
            : '=?UTF-8?B?' . base64_encode($this->name) . '?=';

        return $name . ' <' . $this->email . '>';
    }

    /**
     * @return array{email: string, name: string|null}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name];
    }
}
