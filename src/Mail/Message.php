<?php

declare(strict_types=1);

namespace Racin\Mail;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * An email message.
 *
 *     $message = (new Message())
 *         ->to('ana@example.com', 'Ana')
 *         ->subject('Your order')
 *         ->html(Template::render($path, ['order' => $order]))
 *         ->attach($pdfPath);
 */
final class Message
{
    private ?Address $from = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    private ?Address $replyTo = null;

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var list<array{name: string, mime: string, content: string}> */
    private array $attachments = [];

    public function from(string $email, ?string $name = null): self
    {
        $this->from = new Address($email, $name);

        return $this;
    }

    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = new Address($email, $name);

        return $this;
    }

    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = new Address($email, $name);

        return $this;
    }

    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = new Address($email, $name);

        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = new Address($email, $name);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Attachment [{$path}] does not exist.");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Unable to read attachment [{$path}].");
        }

        $mime ??= (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return $this->attachData($content, $name ?? basename($path), $mime);
    }

    public function attachData(string $content, string $name, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = [
            'name' => (string) preg_replace('/[\r\n"\\\\\/]+/', '_', $name),
            'mime' => preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) === 1 ? $mime : 'application/octet-stream',
            'content' => $content,
        ];

        return $this;
    }

    public function getFrom(): ?Address
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    /**
     * @return list<Address>
     */
    public function getTo(): array
    {
        return $this->to;
    }

    /**
     * Every envelope recipient (to, cc and bcc).
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_unique(array_map(static fn (Address $a): string => $a->email, [...$this->to, ...$this->cc, ...$this->bcc])));
    }

    /**
     * The full RFC 5322 message (headers + MIME body) with CRLF line endings. Bcc is not included.
     */
    public function toMime(string $domain = 'localhost'): string
    {
        if ($this->from === null) {
            throw new LogicException('The message has no sender.');
        }

        if ($this->recipients() === []) {
            throw new LogicException('The message has no recipients.');
        }

        if ($this->text === null && $this->html === null) {
            throw new LogicException('The message has no body.');
        }

        $domain = preg_match('/^[A-Za-z0-9.-]+$/', $domain) === 1 ? $domain : 'localhost';
        $headers = [
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'From' => $this->from->toHeader(),
        ];

        if ($this->to !== []) {
            $headers['To'] = implode(', ', array_map(static fn (Address $a): string => $a->toHeader(), $this->to));
        }

        if ($this->cc !== []) {
            $headers['Cc'] = implode(', ', array_map(static fn (Address $a): string => $a->toHeader(), $this->cc));
        }

        if ($this->replyTo !== null) {
            $headers['Reply-To'] = $this->replyTo->toHeader();
        }

        $headers['Subject'] = self::encodeHeader($this->subject);
        $headers['MIME-Version'] = '1.0';

        [$contentHeaders, $body] = $this->body();

        $lines = [];

        foreach ($headers + $contentHeaders as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toArray(),
            'to' => array_map(static fn (Address $a): array => $a->toArray(), $this->to),
            'cc' => array_map(static fn (Address $a): array => $a->toArray(), $this->cc),
            'bcc' => array_map(static fn (Address $a): array => $a->toArray(), $this->bcc),
            'reply_to' => $this->replyTo?->toArray(),
            'subject' => $this->subject,
            'text' => $this->text,
            'html' => $this->html,
            'attachments' => array_map(
                static fn (array $a): array => ['name' => $a['name'], 'mime' => $a['mime'], 'content' => base64_encode($a['content'])],
                $this->attachments,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data from toArray()
     */
    public static function fromArray(array $data): self
    {
        $message = new self();

        if (isset($data['from']['email'])) {
            $message->from($data['from']['email'], $data['from']['name'] ?? null);
        }

        foreach (['to', 'cc', 'bcc'] as $field) {
            foreach ($data[$field] ?? [] as $address) {
                $message->{$field}($address['email'], $address['name'] ?? null);
            }
        }

        if (isset($data['reply_to']['email'])) {
            $message->replyTo($data['reply_to']['email'], $data['reply_to']['name'] ?? null);
        }

        $message->subject((string) ($data['subject'] ?? ''));

        if (isset($data['text'])) {
            $message->text((string) $data['text']);
        }

        if (isset($data['html'])) {
            $message->html((string) $data['html']);
        }

        foreach ($data['attachments'] ?? [] as $attachment) {
            $message->attachData((string) base64_decode((string) $attachment['content'], true), (string) $attachment['name'], (string) $attachment['mime']);
        }

        return $message;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function body(): array
    {
        $text = $this->text ?? self::htmlToText((string) $this->html);
        $textPart = self::part('text/plain; charset=UTF-8', $text);

        if ($this->html === null) {
            $content = $textPart;
        } else {
            $boundary = self::boundary();
            $content = [
                ['Content-Type' => "multipart/alternative; boundary=\"{$boundary}\""],
                self::multipart($boundary, [$textPart, self::part('text/html; charset=UTF-8', $this->html)]),
            ];
        }

        if ($this->attachments === []) {
            return $content;
        }

        $boundary = self::boundary();
        $parts = [$content];

        foreach ($this->attachments as $attachment) {
            $parts[] = [
                [
                    'Content-Type' => $attachment['mime'] . '; name="' . $attachment['name'] . '"',
                    'Content-Transfer-Encoding' => 'base64',
                    'Content-Disposition' => 'attachment; filename="' . $attachment['name'] . '"',
                ],
                rtrim(chunk_split(base64_encode($attachment['content']), 76, "\r\n")),
            ];
        }

        return [['Content-Type' => "multipart/mixed; boundary=\"{$boundary}\""], self::multipart($boundary, $parts)];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private static function part(string $type, string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return [
            ['Content-Type' => $type, 'Content-Transfer-Encoding' => 'quoted-printable'],
            // quoted_printable_encode keeps the CRLF line breaks and adds "=\r\n" soft breaks itself.
            quoted_printable_encode(str_replace("\n", "\r\n", $content)),
        ];
    }

    /**
     * @param list<array{0: array<string, string>, 1: string}> $parts
     */
    private static function multipart(string $boundary, array $parts): string
    {
        $out = '';

        foreach ($parts as [$headers, $body]) {
            $out .= "--{$boundary}\r\n";

            foreach ($headers as $name => $value) {
                $out .= "{$name}: {$value}\r\n";
            }

            $out .= "\r\n{$body}\r\n";
        }

        return $out . "--{$boundary}--";
    }

    private static function boundary(): string
    {
        return '=_racin_' . bin2hex(random_bytes(12));
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        // RFC 2047 encoded-words, folded so no line exceeds the limits.
        $words = [];

        foreach (mb_str_split($value, 30) as $chunk) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }

    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace(['#<(br|/p|/div|/h[1-6]|/tr|/li)[^>]*>#i', '#<li[^>]*>#i'], ["\n", '- '], $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }
}
