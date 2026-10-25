<?php

declare(strict_types=1);

namespace Racin\Mail;

use Closure;

/**
 * Minimal SMTP client: EHLO, STARTTLS ("tls") or implicit TLS ("ssl"), AUTH LOGIN,
 * MAIL/RCPT/DATA with dot-stuffing. One connection per message.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $stream = null;

    /**
     * @param 'tls'|'ssl'|null $encryption
     * @param (Closure(): resource)|null $connector custom stream factory (tests)
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $encryption = 'tls',
        private readonly ?string $username = null,
        #[\SensitiveParameter] private readonly ?string $password = null,
        private readonly float $timeout = 10.0,
        private readonly string $localDomain = 'localhost',
        private readonly ?Closure $connector = null,
    ) {
    }

    public function send(Message $message): void
    {
        $from = $message->getFrom() ?? throw new TransportException('The message has no sender.');

        $this->connect();

        try {
            $this->expect(220);
            $this->ehlo();

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);

                if (!stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new TransportException('STARTTLS negotiation failed.');
                }

                $this->ehlo();
            }

            if ($this->username !== null && $this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode((string) $this->password), 235, 'AUTH (password hidden)');
            }

            $this->command('MAIL FROM:<' . $from->email . '>', 250);

            foreach ($message->recipients() as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', 354);

            // Dot-stuffing: a line starting with "." gets another ".".
            $data = (string) preg_replace('/^\./m', '..', $message->toMime($this->localDomain));
            $this->write($data . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        if ($this->connector !== null) {
            $this->stream = ($this->connector)();

            return;
        }

        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $stream = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['peer_name' => $this->host, 'SNI_enabled' => true]]),
        );

        if ($stream === false) {
            throw new TransportException("Could not connect to SMTP server {$this->host}:{$this->port}: {$errorMessage} ({$errorCode})");
        }

        stream_set_timeout($stream, (int) ceil($this->timeout));
        $this->stream = $stream;
    }

    private function ehlo(): void
    {
        try {
            $this->command('EHLO ' . $this->localDomain, 250);
        } catch (TransportException) {
            $this->command('HELO ' . $this->localDomain, 250);
        }
    }

    /**
     * @param int|list<int> $expected
     */
    private function command(string $command, int|array $expected, ?string $logAs = null): string
    {
        if (preg_match('/[\r\n]/', $command) === 1) {
            throw new TransportException('SMTP commands may not contain line breaks.');
        }

        $this->write($command . "\r\n");

        return $this->expect($expected, $logAs ?? $command);
    }

    /**
     * Read a (possibly multi-line) reply and check its code.
     *
     * @param int|list<int> $expected
     */
    private function expect(int|array $expected, string $after = 'connect'): string
    {
        $reply = '';

        while (($line = fgets($this->stream, 1024)) !== false) {
            $reply .= $line;

            // "250-..." continues, "250 ..." ends the reply.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($reply === '') {
            throw new TransportException("No reply from SMTP server after [{$after}].");
        }

        $code = (int) substr($reply, 0, 3);

        if (!in_array($code, (array) $expected, true)) {
            throw new TransportException("SMTP error after [{$after}]: " . trim($reply));
        }

        return $reply;
    }

    private function write(string $data): void
    {
        $length = strlen($data);

        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = fwrite($this->stream, substr($data, $written));

            if ($bytes === false || $bytes === 0) {
                throw new TransportException('Unable to write to the SMTP server.');
            }
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }
}
