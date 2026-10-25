<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class ServeCommand extends Command
{
    protected string $name = 'serve';

    protected string $description = 'Start the PHP development server';

    protected string $usage = 'serve [--host=127.0.0.1] [--port=8000]';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $host = (string) $input->option('host', '127.0.0.1');
        $port = (string) $input->option('port', '8000');

        if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1 || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $output->error('Invalid --host or --port.');

            return self::FAILURE;
        }

        $public = $this->app->basePath('public');
        $output->info("Racin development server started: http://{$host}:{$port}");
        $output->comment('Press Ctrl+C to stop.');

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $public, $public . DIRECTORY_SEPARATOR . 'index.php'],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $this->app->basePath(),
        );

        if (!is_resource($process)) {
            $output->error('Unable to start the PHP built-in server.');

            return self::FAILURE;
        }

        return proc_close($process);
    }
}
