<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Database\DatabaseManager;
use Racin\Database\QueryException;
use Racin\Database\SqlSplitter;

final class DbImportCommand extends Command
{
    protected string $name = 'db:import';

    protected string $description = 'Run a .sql file against a database connection';

    protected string $usage = 'db:import <file.sql> [--connection=name]';

    public function __construct(private readonly Application $app, private readonly DatabaseManager $db)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $file = $input->argument(0);

        if ($file === null) {
            $output->error('Usage: php racin ' . $this->usage);

            return self::FAILURE;
        }

        $path = is_file($file) ? $file : $this->app->basePath($file);

        if (!is_file($path)) {
            $output->error("File [{$file}] not found.");

            return self::FAILURE;
        }

        $name = $input->option('connection');
        $connection = $this->db->connection(is_string($name) ? $name : null);
        $statements = SqlSplitter::split((string) file_get_contents($path));

        foreach ($statements as $index => $statement) {
            try {
                $connection->unprepared($statement);
            } catch (QueryException $e) {
                $output->error(sprintf('Statement %d of %d failed: %s', $index + 1, count($statements), $e->getPrevious()?->getMessage()));
                $output->error(mb_strimwidth($statement, 0, 300, '...'));

                return self::FAILURE;
            }
        }

        $output->info(sprintf('Imported [%s]: %d statement(s).', $file, count($statements)));

        return self::SUCCESS;
    }
}
