<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

final class MakeMiddlewareCommand extends GeneratorCommand
{
    protected string $name = 'make:middleware';

    protected string $description = 'Create a new HTTP middleware class';

    protected string $directory = 'Middleware';

    protected function stub(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Closure;
            use Racin\Http\Middleware;
            use Racin\Http\Request;
            use Racin\Http\Response;

            final class {$class} implements Middleware
            {
                public function handle(Request \$request, Closure \$next): Response
                {
                    // Before the controller...

                    \$response = \$next(\$request);

                    // ...after the controller.

                    return \$response;
                }
            }

            PHP;
    }
}
