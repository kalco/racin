<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Support\Str;

final class MakeCommandCommand extends GeneratorCommand
{
    protected string $name = 'make:command';

    protected string $description = 'Create a new console command (register it in config/app.php "commands")';

    protected string $directory = 'Console';

    public function handle(Input $input, Output $output): int
    {
        $result = parent::handle($input, $output);

        if ($result === self::SUCCESS) {
            $output->comment('Remember to add it to the "commands" list in config/app.php.');
        }

        return $result;
    }

    protected function stub(string $namespace, string $class): string
    {
        $name = 'app:' . str_replace('_', '-', Str::snake(preg_replace('/Command$/', '', $class) ?: $class));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Racin\Console\Command;
            use Racin\Console\Input;
            use Racin\Console\Output;

            final class {$class} extends Command
            {
                protected string \$name = '{$name}';

                protected string \$description = 'Describe what this command does';

                public function handle(Input \$input, Output \$output): int
                {
                    \$output->info('Done.');

                    return self::SUCCESS;
                }
            }

            PHP;
    }
}
