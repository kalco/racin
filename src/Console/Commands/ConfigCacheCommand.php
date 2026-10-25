<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Application;
use Racin\Config\Config;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class ConfigCacheCommand extends Command
{
    protected string $name = 'config:cache';

    protected string $description = 'Cache all configuration into one file (production)';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $file = $this->app->cachePath('config.php');

        if (is_file($file)) {
            unlink($file);
        }

        // Re-read from disk so a stale cache is never re-cached.
        $config = Config::fromDirectory($this->app->configPath())->all();

        CacheWriter::write($file, $config);
        $output->info('Configuration cached. Changes to .env or config/ need another "config:cache".');

        return self::SUCCESS;
    }
}
