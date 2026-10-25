<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Support\Str;

/**
 * Creates a class file under app/ from a stub. "Admin/UserController" becomes
 * App\<Directory>\Admin\UserController in app/<Directory>/Admin/UserController.php.
 */
abstract class GeneratorCommand extends Command
{
    /** Sub-directory of app/ (and sub-namespace of App\) */
    protected string $directory = '';

    public function __construct(protected readonly Application $app)
    {
    }

    /**
     * @param string $namespace e.g. App\Controllers\Admin
     * @param string $class e.g. UserController
     */
    abstract protected function stub(string $namespace, string $class): string;

    public function usage(): string
    {
        return $this->name . ' <Name>';
    }

    public function handle(Input $input, Output $output): int
    {
        $name = $input->argument(0);

        if ($name === null || preg_match('#^[A-Za-z][A-Za-z0-9_]*([/\\\\][A-Za-z][A-Za-z0-9_]*)*$#', $name) !== 1) {
            $output->error('Usage: php racin ' . $this->usage() . ' (letters, digits, "_" and "/" only)');

            return self::FAILURE;
        }

        $segments = array_map(Str::studly(...), preg_split('#[/\\\\]#', $name) ?: []);
        $class = array_pop($segments);
        $namespace = implode('\\', array_filter(['App', $this->directory, ...$segments]));
        $path = $this->app->basePath(implode(DIRECTORY_SEPARATOR, array_filter(['app', $this->directory, ...$segments, $class . '.php'])));

        if (is_file($path)) {
            $output->error("File already exists: {$path}");

            return self::FAILURE;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $this->stub($namespace, $class));
        $output->info("Created {$namespace}\\{$class}");
        $output->line('  ' . $path);

        return self::SUCCESS;
    }
}
