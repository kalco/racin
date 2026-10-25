<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Commands\Concerns\ConfirmsProduction;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\Migrations\Migrator;

final class MigrateRollbackCommand extends Command
{
    use ConfirmsProduction;

    protected string $name = 'migrate:rollback';

    protected string $description = 'Roll back the last batch of migrations';

    protected string $usage = 'migrate:rollback [--step=1] [--force]';

    public function __construct(private readonly Application $app, private readonly Migrator $migrator)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        if (!$this->confirmedForProduction($this->app, $input, $output)) {
            return self::FAILURE;
        }

        $this->migrator->onOutput($output->line(...));
        $this->migrator->rollback(max(1, (int) $input->option('step', '1')));

        return self::SUCCESS;
    }
}
