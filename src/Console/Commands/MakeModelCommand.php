<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

final class MakeModelCommand extends GeneratorCommand
{
    protected string $name = 'make:model';

    protected string $description = 'Create a new database model class';

    protected string $directory = 'Models';

    protected function stub(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Racin\Database\Model;

            final class {$class} extends Model
            {
                /** @var list<string> */
                protected array \$fillable = [];

                /** @var list<string> */
                protected array \$hidden = [];

                /** @var array<string, string> */
                protected array \$casts = [];
            }

            PHP;
    }
}
