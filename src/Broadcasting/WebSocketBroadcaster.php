<?php

declare(strict_types=1);

namespace Racin\Broadcasting;

use JsonException;
use Psr\Log\LoggerInterface;
use Racin\Cache\Cache;

/**
 * Hands events to the WebSocket server ("php racin websocket:serve") over its
 * internal port. Each message is signed with HMAC-SHA256 of the app key.
 */
final class WebSocketBroadcaster implements Broadcaster
{
    /** While this cache key exists the server is assumed down and broadcasts are skipped without connecting. */
    private const string DOWN_KEY = 'racin:broadcast:websocket-down';

    /**
     * @param int $retryAfter seconds to stop trying after a failed connection (0 = always try)
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly ?LoggerInterface $logger = null,
        private readonly float $timeout = 0.3,
        private readonly ?Cache $cache = null,
        private readonly int $retryAfter = 15,
    ) {
    }

    public function broadcast(array $channels, string $event, array $data = []): void
    {
        // Connecting to a closed port can take the whole timeout (notably on Windows):
        // after a failure, skip for a while instead of slowing every request down.
        if ($this->cache?->get(self::DOWN_KEY) === true) {
            return;
        }

        try {
            $payload = json_encode(['channels' => array_values($channels), 'event' => $event, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            $this->logger?->warning("Broadcast [{$event}] could not be encoded: {$e->getMessage()}");

            return;
        }

        $line = json_encode(['payload' => $payload, 'signature' => self::sign($payload, $this->secret)], JSON_UNESCAPED_SLASHES) . "\n";
        $stream = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errorCode, $errorMessage, $this->timeout);

        if ($stream === false) {
            $this->logger?->warning("Broadcast [{$event}] skipped: WebSocket server not reachable at {$this->host}:{$this->port} ({$errorMessage}). Is \"php racin websocket:serve\" running?");

            if ($this->retryAfter > 0) {
                $this->cache?->put(self::DOWN_KEY, true, $this->retryAfter);
            }

            return;
        }

        stream_set_timeout($stream, 1);
        @fwrite($stream, $line);
        fclose($stream);
    }

    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
