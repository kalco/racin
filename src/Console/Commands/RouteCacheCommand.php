<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class RouteCacheCommand extends Command
{
    protected string $name = 'route:cache';

    protected string $description = 'Cache the route table for faster boot (production)';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $file = $this->app->cachePath('routes.php');

        if (is_file($file)) {
            unlink($file);
        }

        $routes = $this->app->loadRoutes()->toCache();

        CacheWriter::write($file, $routes);
        $output->info('Routes cached: ' . count($routes) . ' route(s).');

        return self::SUCCESS;
    }
}
