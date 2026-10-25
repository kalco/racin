<?php

declare(strict_types=1);

/**
 * Starts a WebSocket server on free ports for WebSocketServerTest.
 * Prints {"public": port, "internal": port} and serves for argv[1] seconds.
 */

use Psr\Log\NullLogger;
use Racin\Auth\Jwt;
use Racin\Auth\TokenBlacklist;
use Racin\Auth\UserProvider;
use Racin\Broadcasting\ChannelRegistry;
use Racin\Broadcasting\WebSocketServer;
use Racin\Cache\ArrayStore;
use Racin\Cache\Cache;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$users = new class implements UserProvider {
    public function retrieveById(int|string $id, array $claims = []): ?array
    {
        return (int) $id === 7 ? ['id' => 7] : null;
    }
};

$channels = new ChannelRegistry();
$channels->channel('user.{id}', fn (array $user, string $id): bool => $user['id'] === (int) $id);

$server = new WebSocketServer(
    new Jwt(str_repeat('s', 32)),
    new TokenBlacklist(new Cache(new ArrayStore())),
    $users,
    $channels,
    new NullLogger(),
    'broadcast-secret',
    ['http://allowed.test'],
);

$server->listen('127.0.0.1', 0, '127.0.0.1', 0);
echo json_encode($server->ports()), "\n";

$server->run((int) ($argv[1] ?? 10));
