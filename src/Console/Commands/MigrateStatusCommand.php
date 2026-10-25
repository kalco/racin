<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\Migrations\Migrator;

final class MigrateStatusCommand extends Command
{
    protected string $name = 'migrate:status';

    protected string $description = 'Show which migrations have run';

    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $rows = array_map(
            static fn (array $row): array => [$row['migration'], $row['ran'] ? 'Ran' : 'Pending', $row['batch'] === null ? '' : (string) $row['batch']],
            $this->migrator->status(),
        );

        $rows === [] ? $output->comment('No migrations found.') : $output->table(['Migration', 'Status', 'Batch'], $rows);

        return self::SUCCESS;
    }
}
