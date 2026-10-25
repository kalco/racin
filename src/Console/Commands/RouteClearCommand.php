<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class RouteClearCommand extends Command
{
    protected string $name = 'route:clear';

    protected string $description = 'Remove the route cache file';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $file = $this->app->cachePath('routes.php');

        if (is_file($file)) {
            unlink($file);
        }

        $output->info('Route cache cleared.');

        return self::SUCCESS;
    }
}
