<?php

declare(strict_types=1);

namespace Racin\Console\Commands\Concerns;

use Racin\Application;
use Racin\Console\Input;
use Racin\Console\Output;

/**
 * Database-changing commands refuse to run in production unless --force is given.
 */
trait ConfirmsProduction
{
    protected function confirmedForProduction(Application $app, Input $input, Output $output): bool
    {
        if ($app->environment() !== 'production' || $input->hasOption('force')) {
            return true;
        }

        $output->error('APP_ENV is "production". Re-run with --force if you really mean it.');

        return false;
    }
}
