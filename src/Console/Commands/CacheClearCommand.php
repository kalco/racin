<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Cache\Cache;
use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;

final class CacheClearCommand extends Command
{
    protected string $name = 'cache:clear';

    protected string $description = 'Flush the application cache';

    public function __construct(private readonly Cache $cache)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $this->cache->flush();
        $output->info('Application cache cleared.');

        return self::SUCCESS;
    }
}
