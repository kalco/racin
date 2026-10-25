<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Closure;
use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class RouteListCommand extends Command
{
    protected string $name = 'route:list';

    protected string $description = 'List all registered routes';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $rows = [];

        foreach ($this->app->loadRoutes()->routes() as $route) {
            $action = $route->action();

            $rows[] = [
                implode('|', $route->methods()),
                $route->uri(),
                (string) $route->getName(),
                match (true) {
                    $action instanceof Closure => 'Closure',
                    is_array($action) => $action[0] . '@' . $action[1],
                    default => $action,
                },
                implode(', ', $route->getMiddleware()),
            ];
        }

        if ($rows === []) {
            $output->comment('No routes registered.');

            return self::SUCCESS;
        }

        $output->table(['Method', 'URI', 'Name', 'Action', 'Middleware'], $rows);

        return self::SUCCESS;
    }
}
