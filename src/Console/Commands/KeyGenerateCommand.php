<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class KeyGenerateCommand extends Command
{
    protected string $name = 'key:generate';

    protected string $description = 'Generate APP_KEY in .env (signs auth tokens)';

    protected string $usage = 'key:generate [--show] [--force]';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        if ($input->hasOption('show')) {
            $output->line($key);

            return self::SUCCESS;
        }

        $env = $this->app->basePath('.env');

        if (!is_file($env)) {
            $example = $this->app->basePath('.env.example');

            if (!is_file($example) || !copy($example, $env)) {
                $output->error('No .env file found (and no .env.example to copy).');

                return self::FAILURE;
            }
        }

        $contents = (string) file_get_contents($env);

        if (preg_match('/^APP_KEY=(.+)$/m', $contents, $match) === 1 && trim($match[1]) !== '' && !$input->hasOption('force')) {
            $output->error('APP_KEY is already set. Use --force to replace it (all issued tokens become invalid).');

            return self::FAILURE;
        }

        $contents = preg_match('/^APP_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents)
            : rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;

        file_put_contents($env, $contents, LOCK_EX);
        $output->info('Application key set.');

        return self::SUCCESS;
    }
}
