<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Support\Str;

final class MakeMigrationCommand extends Command
{
    protected string $name = 'make:migration';

    protected string $description = 'Create a new migration file';

    protected string $usage = 'make:migration <name> [--create=table | --table=table]';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->argument(0);

        if ($name === null || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            $output->error('Usage: php racin ' . $this->usage);

            return self::FAILURE;
        }

        $create = $input->option('create');
        $table = $input->option('table');
        $tableName = is_string($create) ? $create : (is_string($table) ? $table : null);

        if ($tableName !== null && preg_match('/^[A-Za-z0-9_]+$/', $tableName) !== 1) {
            $output->error('Invalid table name.');

            return self::FAILURE;
        }

        $file = $this->app->basePath('database/migrations/' . date('Y_m_d_His') . '_' . Str::snake($name) . '.php');

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }

        [$up, $down] = match (true) {
            is_string($create) => [
                "        \$schema->create('{$tableName}', function (Blueprint \$table): void {\n            \$table->id();\n            \$table->timestamps();\n        });",
                "        \$schema->dropIfExists('{$tableName}');",
            ],
            is_string($table) => [
                "        \$schema->table('{$tableName}', function (Blueprint \$table): void {\n            //\n        });",
                "        \$schema->table('{$tableName}', function (Blueprint \$table): void {\n            //\n        });",
            ],
            default => ['        //', '        //'],
        };

        file_put_contents($file, <<<PHP
            <?php

            declare(strict_types=1);

            use Racin\Database\Migrations\Migration;
            use Racin\Database\Schema\Blueprint;
            use Racin\Database\Schema\Schema;

            return new class extends Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP);

        $output->info('Created ' . $file);

        return self::SUCCESS;
    }
}
