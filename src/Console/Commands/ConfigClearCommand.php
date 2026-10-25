<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class ConfigClearCommand extends Command
{
    protected string $name = 'config:clear';

    protected string $description = 'Remove the configuration cache file';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $file = $this->app->cachePath('config.php');

        if (is_file($file)) {
            unlink($file);
        }

        $output->info('Configuration cache cleared.');

        return self::SUCCESS;
    }
}
