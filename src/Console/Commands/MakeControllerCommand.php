<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

/**
 * An empty Rails-style controller. "php racin g controller Name action..." also adds the routes,
 * and "php racin g scaffold" writes a complete resource.
 */
final class MakeControllerCommand extends GeneratorCommand
{
    protected string $name = 'make:controller';

    protected string $description = 'Create a new controller class (see also: g controller, g scaffold)';

    protected string $directory = 'Controllers';

    protected function stub(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use App\Controllers\ApiController;
            use Racin\Http\Response;

            /**
             * Every action needs a bearer token unless the filter is skipped:
             *     #[SkipBeforeAction('authorizeRequest', only: ['index'])]
             */
            final class {$class} extends ApiController
            {
                public function index(): Response
                {
                    return \$this->render(json: []);
                }

                public function show(int \$id): Response
                {
                    return \$this->render(json: ['id' => \$id]);
                }
            }

            PHP;
    }
}
