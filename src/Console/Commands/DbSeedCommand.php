<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Commands\Concerns\ConfirmsProduction;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\Seeder;

final class DbSeedCommand extends Command
{
    use ConfirmsProduction;

    protected string $name = 'db:seed';

    protected string $description = 'Run database seeders (Database\Seeders\DatabaseSeeder by default)';

    protected string $usage = 'db:seed [--class=DatabaseSeeder] [--force]';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        if (!$this->confirmedForProduction($this->app, $input, $output)) {
            return self::FAILURE;
        }

        $class = $input->option('class');
        $class = is_string($class) ? $class : 'DatabaseSeeder';
        $class = str_contains($class, '\\') ? $class : 'Database\\Seeders\\' . $class;

        if (!class_exists($class) || !is_subclass_of($class, Seeder::class)) {
            $output->error("Seeder [{$class}] not found (it must extend " . Seeder::class . ').');

            return self::FAILURE;
        }

        /** @var Seeder $seeder */
        $seeder = $this->app->make($class);
        $seeder->setContainer($this->app)->setOutput($output->line(...));
        $output->line('Seeding: ' . $class);
        $seeder->run();
        $output->info('Database seeded.');

        return self::SUCCESS;
    }
}
