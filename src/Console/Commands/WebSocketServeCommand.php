<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Psr\Log\LoggerInterface;
use Racin\Application;
use Racin\Auth\Jwt;
use Racin\Auth\TokenBlacklist;
use Racin\Auth\UserProvider;
use Racin\Broadcasting\ChannelRegistry;
use Racin\Broadcasting\WebSocketServer;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\DatabaseManager;

final class WebSocketServeCommand extends Command
{
    protected string $name = 'websocket:serve';

    protected string $description = 'Start the real-time WebSocket server';

    protected string $usage = 'websocket:serve [--host=0.0.0.0] [--port=6001] [--max-time=0]';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $config = $this->app->config();
        $host = (string) $input->option('host', (string) $config->get('broadcasting.websocket.host', '0.0.0.0'));
        $port = (int) $input->option('port', (string) $config->get('broadcasting.websocket.port', 6001));
        $internalHost = (string) $config->get('broadcasting.websocket.internal_host', '127.0.0.1');
        $internalPort = (int) $config->get('broadcasting.websocket.internal_port', 6002);

        $server = new WebSocketServer(
            $this->app->make(Jwt::class),
            $this->app->make(TokenBlacklist::class),
            $this->app->make(UserProvider::class),
            $this->app->make(ChannelRegistry::class),
            $this->app->make(LoggerInterface::class),
            $this->app->appKey(),
            (array) $config->get('broadcasting.websocket.allowed_origins', ['*']),
            (int) $config->get('broadcasting.websocket.max_payload', 65536),
            (int) $config->get('broadcasting.websocket.max_clients', 5000),
            $this->app->make(DatabaseManager::class),
        );

        $server->onOutput($output->line(...));
        $server->listen($host, $port, $internalHost, $internalPort);
        $ports = $server->ports();

        $output->info("WebSocket server listening on ws://{$host}:{$ports['public']} (broadcasts accepted on {$internalHost}:{$ports['internal']}).");
        $output->comment('Press Ctrl+C to stop.');

        $server->run(max(0, (int) $input->option('max-time', '0')));

        return self::SUCCESS;
    }
}
