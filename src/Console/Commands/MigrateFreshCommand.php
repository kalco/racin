<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Commands\Concerns\ConfirmsProduction;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\Migrations\Migrator;

final class MigrateFreshCommand extends Command
{
    use ConfirmsProduction;

    protected string $name = 'migrate:fresh';

    protected string $description = 'Drop ALL tables and run every migration again';

    protected string $usage = 'migrate:fresh [--seed] [--force]';

    public function __construct(private readonly Application $app, private readonly Migrator $migrator)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        if (!$this->confirmedForProduction($this->app, $input, $output)) {
            return self::FAILURE;
        }

        $this->migrator->onOutput($output->line(...));
        $this->migrator->fresh();

        return $input->hasOption('seed') ? $this->app->runConsole(['racin', 'db:seed', ...($input->hasOption('force') ? ['--force'] : [])]) : self::SUCCESS;
    }
}
