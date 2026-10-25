<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Auth\Jwt;
use Racin\Broadcasting\WebSocketBroadcaster;

final class WebSocketServerTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var array{public: int, internal: int} */
    private array $ports;

    protected function setUp(): void
    {
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/websocket-server.php', '20'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $line = fgets($pipes[1]);
        $ports = json_decode((string) $line, true);

        if (!is_array($ports)) {
            self::fail('WebSocket server did not start: ' . $line . stream_get_contents($pipes[2]));
        }

        $this->ports = $ports;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * @return resource
     */
    private function connect(string $origin = 'http://allowed.test', ?string &$response = null)
    {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->ports['public']}", $errno, $error, 3);
        self::assertNotFalse($socket, $error);
        stream_set_timeout($socket, 3);

        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET /?x=1 HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\n\r\n");

        $response = '';

        while (!str_contains($response, "\r\n\r\n") && ($line = fgets($socket)) !== false) {
            $response .= $line;
        }

        if (str_starts_with($response, 'HTTP/1.1 101')) {
            self::assertStringContainsString('Sec-WebSocket-Accept: ' . base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), $response);
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function send($socket, array $message): void
    {
        $payload = (string) json_encode($message);
        $mask = random_bytes(4);
        $length = strlen($payload);
        $header = chr(0x81) . ($length < 126 ? chr(0x80 | $length) : chr(0x80 | 126) . pack('n', $length));

        fwrite($socket, $header . $mask . ($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length)));
    }

    /**
     * @param resource $socket
     * @return array<string, mixed>|null null on timeout
     */
    private function receive($socket, float $timeout = 3.0): ?array
    {
        stream_set_timeout($socket, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1e6));
        $head = fread($socket, 2);

        if ($head === false || strlen($head) < 2) {
            return null;
        }

        $length = ord($head[1]) & 0x7F;

        if ($length === 126) {
            $length = unpack('n', fread($socket, 2))[1];
        }

        $payload = '';

        while (strlen($payload) < $length && ($chunk = fread($socket, $length - strlen($payload))) !== false && $chunk !== '') {
            $payload .= $chunk;
        }

        return (ord($head[0]) & 0x0F) === 0x8 ? ['event' => 'close'] : json_decode($payload, true);
    }

    public function testFullFlow(): void
    {
        $socket = $this->connect();
        self::assertSame('connected', $this->receive($socket)['event']);

        // Guests may not join private channels.
        $this->send($socket, ['event' => 'subscribe', 'channel' => 'user.7']);
        self::assertSame(['event' => 'error', 'channel' => 'user.7', 'message' => 'Forbidden.'], $this->receive($socket));

        $this->send($socket, ['event' => 'authenticate', 'token' => 'garbage']);
        self::assertSame('Invalid token.', $this->receive($socket)['message']);

        $token = (new Jwt(str_repeat('s', 32)))->encode(['sub' => 7]);
        $this->send($socket, ['event' => 'authenticate', 'token' => $token]);
        self::assertSame(['event' => 'authenticated', 'data' => ['user_id' => 7]], $this->receive($socket));

        $this->send($socket, ['event' => 'subscribe', 'channel' => 'user.8']);
        self::assertSame('error', $this->receive($socket)['event'], "another user's channel");

        $this->send($socket, ['event' => 'subscribe', 'channel' => 'user.7']);
        self::assertSame(['event' => 'subscribed', 'channel' => 'user.7'], $this->receive($socket));

        $this->send($socket, ['event' => 'subscribe', 'channel' => 'public.catalog']);
        self::assertSame('subscribed', $this->receive($socket)['event']);

        (new WebSocketBroadcaster('127.0.0.1', $this->ports['internal'], 'broadcast-secret'))
            ->broadcast(['user.7', 'user.8'], 'order.placed', ['id' => 42, 'note' => 'Нарачка']);

        self::assertSame(
            ['event' => 'order.placed', 'channel' => 'user.7', 'data' => ['id' => 42, 'note' => 'Нарачка']],
            $this->receive($socket),
        );

        // A broadcast signed with the wrong key is dropped.
        (new WebSocketBroadcaster('127.0.0.1', $this->ports['internal'], 'wrong-secret'))->broadcast(['user.7'], 'forged', []);

        $this->send($socket, ['event' => 'ping']);
        self::assertSame(['event' => 'pong'], $this->receive($socket), 'the forged event never arrived');

        fclose($socket);
    }

    public function testRejectsForeignOrigins(): void
    {
        $socket = $this->connect('http://evil.test', $response);

        self::assertStringStartsWith('HTTP/1.1 403', (string) $response);
        fclose($socket);
    }
}
