<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

use Psr\Log\LoggerInterface;
use Racin\Auth\Jwt;
use Racin\Auth\TokenBlacklist;
use Racin\Auth\UserProvider;
use Racin\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * RFC 6455 WebSocket server in plain PHP (stream_select, no extensions).
 *
 * Client protocol (JSON text frames):
 *   → {"event": "authenticate", "token": "<jwt>"}      ← {"event": "authenticated", "data": {"user_id": 5}}
 *   → {"event": "subscribe", "channel": "user.5"}       ← {"event": "subscribed", "channel": "user.5"}
 *   → {"event": "unsubscribe", "channel": "user.5"}     ← {"event": "unsubscribed", "channel": "user.5"}
 *   → {"event": "ping"}                                 ← {"event": "pong"}
 *   ← {"event": "order.status-changed", "channel": "user.5", "data": {...}}   (broadcasts)
 *   ← {"event": "error", "message": "...", "channel": "..."}
 *
 * The application pushes broadcasts to a second, internal port (localhost only),
 * each line signed with HMAC-SHA256 of the app key. Fragmented and binary frames are not supported.
 */
final class WebSocketServer
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private const int MAX_SUBSCRIPTIONS = 100;

    /** @var resource|null */
    private $server = null;

    /** @var resource|null */
    private $internalServer = null;

    /** @var array<int, array{stream: resource, buffer: string, out: string, handshake: bool, closing: bool, user: mixed, userId: int|string|null, channels: array<string, true>, peer: string}> */
    private array $clients = [];

    /** @var array<int, array{stream: resource, buffer: string}> */
    private array $internal = [];

    private bool $running = false;

    /** @var (callable(string): void)|null */
    private $output = null;

    /**
     * @param list<string> $allowedOrigins exact origins, wildcards like https://*.example.com, or "*"
     */
    public function __construct(
        private readonly Jwt $jwt,
        private readonly TokenBlacklist $blacklist,
        private readonly UserProvider $users,
        private readonly ChannelRegistry $channels,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly array $allowedOrigins = ['*'],
        private readonly int $maxPayload = 65536,
        private readonly int $maxClients = 5000,
        private readonly ?DatabaseManager $db = null,
    ) {
    }

    /**
     * @param callable(string): void $output
     */
    public function onOutput(callable $output): void
    {
        $this->output = $output;
    }

    public function listen(string $host, int $port, string $internalHost = '127.0.0.1', int $internalPort = 6002): void
    {
        $this->server = self::bind($host, $port);
        $this->internalServer = self::bind($internalHost, $internalPort);
    }

    /**
     * Actual ports (useful when listening on port 0).
     *
     * @return array{public: int, internal: int}
     */
    public function ports(): array
    {
        return [
            'public' => (int) substr((string) strrchr((string) stream_socket_get_name($this->server, false), ':'), 1),
            'internal' => (int) substr((string) strrchr((string) stream_socket_get_name($this->internalServer, false), ':'), 1),
        ];
    }

    /**
     * Serve until stop() is called or $maxSeconds pass (0 = forever).
     */
    public function run(int $maxSeconds = 0): void
    {
        if ($this->server === null || $this->internalServer === null) {
            throw new RuntimeException('Call listen() before run().');
        }

        $this->running = true;
        $start = time();

        while ($this->running && ($maxSeconds === 0 || time() - $start < $maxSeconds)) {
            $read = [$this->server, $this->internalServer];
            $write = [];

            foreach ($this->clients as $client) {
                $read[] = $client['stream'];

                if ($client['out'] !== '') {
                    $write[] = $client['stream'];
                }
            }

            foreach ($this->internal as $connection) {
                $read[] = $connection['stream'];
            }

            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                usleep(10000);
                continue;
            }

            foreach ($read as $stream) {
                $id = get_resource_id($stream);

                match (true) {
                    $stream === $this->server => $this->accept(),
                    $stream === $this->internalServer => $this->acceptInternal(),
                    isset($this->clients[$id]) => $this->readClient($id),
                    isset($this->internal[$id]) => $this->readInternal($id),
                    default => null,
                };
            }

            foreach ($write as $stream) {
                $this->flush(get_resource_id($stream));
            }
        }

        $this->shutdown();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @return int number of connected clients
     */
    public function clientCount(): int
    {
        return count($this->clients);
    }

    // ------------------------------------------------------------------ connections

    private function accept(): void
    {
        $stream = @stream_socket_accept($this->server, 0, $peer);

        if ($stream === false) {
            return;
        }

        if (count($this->clients) >= $this->maxClients) {
            fclose($stream);

            return;
        }

        stream_set_blocking($stream, false);
        $this->clients[get_resource_id($stream)] = [
            'stream' => $stream, 'buffer' => '', 'out' => '', 'handshake' => false, 'closing' => false,
            'user' => null, 'userId' => null, 'channels' => [], 'peer' => (string) $peer,
        ];
    }

    private function acceptInternal(): void
    {
        $stream = @stream_socket_accept($this->internalServer, 0);

        if ($stream !== false) {
            stream_set_blocking($stream, false);
            $this->internal[get_resource_id($stream)] = ['stream' => $stream, 'buffer' => ''];
        }
    }

    private function readClient(int $id): void
    {
        $data = @fread($this->clients[$id]['stream'], 65536);

        if ($data === false || $data === '') {
            if ($data === false || feof($this->clients[$id]['stream'])) {
                $this->disconnect($id);
            }

            return;
        }

        $this->clients[$id]['buffer'] .= $data;

        if (!$this->clients[$id]['handshake']) {
            $this->handshake($id);

            return;
        }

        $this->processFrames($id);
    }

    private function readInternal(int $id): void
    {
        $data = @fread($this->internal[$id]['stream'], 65536);

        if ($data === false || ($data === '' && feof($this->internal[$id]['stream']))) {
            fclose($this->internal[$id]['stream']);
            unset($this->internal[$id]);

            return;
        }

        $this->internal[$id]['buffer'] .= $data;

        if (strlen($this->internal[$id]['buffer']) > 1048576) {
            fclose($this->internal[$id]['stream']);
            unset($this->internal[$id]);

            return;
        }

        while (($position = strpos($this->internal[$id]['buffer'], "\n")) !== false) {
            $line = substr($this->internal[$id]['buffer'], 0, $position);
            $this->internal[$id]['buffer'] = substr($this->internal[$id]['buffer'], $position + 1);
            $this->publish($line);
        }
    }

    private function disconnect(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
            $this->emit("Disconnected #{$id}");
        }
    }

    private function flush(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        if ($this->clients[$id]['out'] !== '') {
            $written = @fwrite($this->clients[$id]['stream'], $this->clients[$id]['out']);

            if ($written === false) {
                $this->disconnect($id);

                return;
            }

            $this->clients[$id]['out'] = (string) substr($this->clients[$id]['out'], $written);
        }

        if ($this->clients[$id]['out'] === '' && $this->clients[$id]['closing']) {
            $this->disconnect($id);
        }
    }

    private function shutdown(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->disconnect($id);
        }

        foreach ($this->internal as $connection) {
            @fclose($connection['stream']);
        }

        $this->internal = [];

        foreach ([$this->server, $this->internalServer] as $server) {
            if (is_resource($server)) {
                fclose($server);
            }
        }

        $this->server = $this->internalServer = null;
    }

    // ------------------------------------------------------------------ handshake

    private function handshake(int $id): void
    {
        $buffer = $this->clients[$id]['buffer'];
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            if (strlen($buffer) > 8192) {
                $this->rejectHandshake($id, '431 Request Header Fields Too Large');
            }

            return;
        }

        $lines = explode("\r\n", substr($buffer, 0, $end));
        $this->clients[$id]['buffer'] = (string) substr($buffer, $end + 4);
        $requestLine = array_shift($lines);
        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        $key = $headers['sec-websocket-key'] ?? '';

        if (preg_match('#^GET \S+ HTTP/1\.1$#', (string) $requestLine) !== 1
            || stripos($headers['upgrade'] ?? '', 'websocket') === false
            || stripos($headers['connection'] ?? '', 'upgrade') === false
            || ($headers['sec-websocket-version'] ?? '') !== '13'
            || strlen((string) base64_decode($key, true)) !== 16) {
            $this->rejectHandshake($id, '400 Bad Request');

            return;
        }

        if (isset($headers['origin']) && !$this->originAllowed($headers['origin'])) {
            $this->rejectHandshake($id, '403 Forbidden');

            return;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));
        $this->clients[$id]['out'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
        $this->clients[$id]['handshake'] = true;
        $this->emit("Connected #{$id} from {$this->clients[$id]['peer']}");
        $this->sendJson($id, ['event' => 'connected', 'data' => ['socket_id' => (string) $id]]);

        if ($this->clients[$id]['buffer'] !== '') {
            $this->processFrames($id);
        }
    }

    private function rejectHandshake(int $id, string $status): void
    {
        $this->clients[$id]['out'] .= "HTTP/1.1 {$status}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
        $this->clients[$id]['closing'] = true;
    }

    private function originAllowed(string $origin): bool
    {
        foreach ($this->allowedOrigins as $pattern) {
            if ($pattern === '*' || strcasecmp($pattern, $origin) === 0) {
                return true;
            }

            if (str_contains($pattern, '*') && preg_match('#^' . str_replace('\*', '[^/]*', preg_quote($pattern, '#')) . '$#i', $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ frames

    private function processFrames(int $id): void
    {
        while (isset($this->clients[$id]) && !$this->clients[$id]['closing']) {
            $buffer = $this->clients[$id]['buffer'];
            $length = strlen($buffer);

            if ($length < 2) {
                return;
            }

            $first = ord($buffer[0]);
            $second = ord($buffer[1]);
            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0F;
            $size = $second & 0x7F;
            $offset = 2;

            if (($first & 0x70) !== 0 || ($second & 0x80) === 0) {
                $this->close($id, 1002, 'Protocol error');

                return;
            }

            if ($size === 126) {
                if ($length < 4) {
                    return;
                }

                $size = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($size === 127) {
                if ($length < 10) {
                    return;
                }

                $size = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }

            if ($size > $this->maxPayload) {
                $this->close($id, 1009, 'Message too big');

                return;
            }

            if ($length < $offset + 4 + $size) {
                return;
            }

            $mask = substr($buffer, $offset, 4);
            $payload = substr($buffer, $offset + 4, $size);
            $payload = $payload ^ substr(str_repeat($mask, intdiv($size, 4) + 1), 0, $size);
            $this->clients[$id]['buffer'] = (string) substr($buffer, $offset + 4 + $size);

            if (!$fin || $opcode === 0x0) {
                $this->close($id, 1003, 'Fragmented messages are not supported');

                return;
            }

            match ($opcode) {
                0x1 => mb_check_encoding($payload, 'UTF-8') ? $this->handleMessage($id, $payload) : $this->close($id, 1007, 'Invalid UTF-8'),
                0x2 => $this->close($id, 1003, 'Binary messages are not supported'),
                0x8 => $this->close($id, 1000, ''),
                0x9 => $this->clients[$id]['out'] .= self::frame($payload, 0xA),
                0xA => null,
                default => $this->close($id, 1002, 'Unknown opcode'),
            };
        }
    }

    public static function frame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length < 65536) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }

        return $header . $payload;
    }

    private function close(int $id, int $code, string $reason): void
    {
        if (!isset($this->clients[$id]) || $this->clients[$id]['closing']) {
            return;
        }

        $this->clients[$id]['out'] .= self::frame(pack('n', $code) . substr($reason, 0, 120), 0x8);
        $this->clients[$id]['closing'] = true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJson(int $id, array $data): void
    {
        if (isset($this->clients[$id]) && !$this->clients[$id]['closing']) {
            $this->clients[$id]['out'] .= self::frame((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    // ------------------------------------------------------------------ protocol

    private function handleMessage(int $id, string $text): void
    {
        $message = json_decode($text, true);

        if (!is_array($message) || !is_string($message['event'] ?? null)) {
            $this->sendJson($id, ['event' => 'error', 'message' => 'Messages must be JSON objects with an "event".']);

            return;
        }

        $channel = is_string($message['channel'] ?? null) ? $message['channel'] : null;

        switch ($message['event']) {
            case 'authenticate':
                $this->authenticate($id, is_string($message['token'] ?? null) ? $message['token'] : '');
                break;

            case 'subscribe':
                if ($channel === null || count($this->clients[$id]['channels']) >= self::MAX_SUBSCRIPTIONS
                    || !$this->authorizeChannel($id, $channel)) {
                    $this->sendJson($id, ['event' => 'error', 'channel' => $channel, 'message' => 'Forbidden.']);
                    break;
                }

                $this->clients[$id]['channels'][$channel] = true;
                $this->sendJson($id, ['event' => 'subscribed', 'channel' => $channel]);
                break;

            case 'unsubscribe':
                if ($channel !== null) {
                    unset($this->clients[$id]['channels'][$channel]);
                }

                $this->sendJson($id, ['event' => 'unsubscribed', 'channel' => $channel]);
                break;

            case 'ping':
                $this->sendJson($id, ['event' => 'pong']);
                break;

            default:
                $this->sendJson($id, ['event' => 'error', 'message' => 'Unknown event.']);
        }
    }

    private function authenticate(int $id, string $token): void
    {
        try {
            $claims = $this->jwt->decode($token);
            $subject = $claims['sub'] ?? null;

            if ((!is_int($subject) && !is_string($subject)) || $this->blacklist->isRevoked($claims)) {
                throw new RuntimeException('Token rejected.');
            }

            $user = $this->withDatabaseRetry(fn () => $this->users->retrieveById($subject, $claims));

            if ($user === null) {
                throw new RuntimeException('User not found.');
            }
        } catch (Throwable) {
            $this->sendJson($id, ['event' => 'error', 'message' => 'Invalid token.']);

            return;
        }

        $this->clients[$id]['user'] = $user;
        $this->clients[$id]['userId'] = $subject;
        $this->clients[$id]['channels'] = []; // a new identity starts without subscriptions
        $this->sendJson($id, ['event' => 'authenticated', 'data' => ['user_id' => $subject]]);
    }

    private function authorizeChannel(int $id, string $channel): bool
    {
        try {
            return $this->withDatabaseRetry(fn (): bool => $this->channels->authorize($this->clients[$id]['user'], $channel));
        } catch (Throwable $e) {
            $this->logger->warning("Channel authorization for [{$channel}] failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Long-running process: if the database connection dropped, reconnect once and retry.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withDatabaseRetry(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Racin\Database\DatabaseException $e) {
            if ($this->db === null) {
                throw $e;
            }

            $this->db->purge();

            return $callback();
        }
    }

    private function publish(string $line): void
    {
        $envelope = json_decode($line, true);
        $payload = is_array($envelope) && is_string($envelope['payload'] ?? null) ? $envelope['payload'] : null;
        $signature = is_array($envelope) && is_string($envelope['signature'] ?? null) ? $envelope['signature'] : '';

        if ($payload === null || !hash_equals(WebSocketBroadcaster::sign($payload, $this->secret), $signature)) {
            $this->logger->warning('Rejected an unsigned or tampered broadcast on the internal port.');

            return;
        }

        $message = json_decode($payload, true);

        if (!is_array($message) || !is_string($message['event'] ?? null) || !is_array($message['channels'] ?? null)) {
            return;
        }

        $delivered = 0;

        foreach ($message['channels'] as $channel) {
            if (!is_string($channel)) {
                continue;
            }

            foreach ($this->clients as $id => $client) {
                if (isset($client['channels'][$channel])) {
                    $this->sendJson($id, ['event' => $message['event'], 'channel' => $channel, 'data' => $message['data'] ?? []]);
                    $delivered++;
                }
            }
        }

        $this->emit("Broadcast {$message['event']} → " . implode(', ', array_filter($message['channels'], 'is_string')) . " ({$delivered} delivered)");
    }

    /**
     * @return resource
     */
    private static function bind(string $host, int $port)
    {
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException("Cannot listen on {$host}:{$port}: {$errorMessage} ({$errorCode})");
        }

        stream_set_blocking($server, false);

        return $server;
    }

    private function emit(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)('[' . date('H:i:s') . '] ' . $line);
        }
    }
}
