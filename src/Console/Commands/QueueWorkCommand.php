<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Queue\Worker;

final class QueueWorkCommand extends Command
{
    protected string $name = 'queue:work';

    protected string $description = 'Process queued jobs (keep it running with a process manager / Task Scheduler)';

    protected string $usage = 'queue:work [--connection=database] [--queue=default] [--sleep=3] [--once] [--stop-when-empty] [--max-jobs=0] [--max-time=0] [--memory=128]';

    public function __construct(private readonly Worker $worker)
    {
    }

    public function handle(Input $input, Output $output): int
    {
        $connection = $input->option('connection');
        $queue = $input->option('queue');

        $this->worker->onOutput($output->line(...));

        if (!$input->hasOption('once') && !$input->hasOption('stop-when-empty')) {
            $output->info('Processing jobs from "' . (is_string($queue) ? $queue : 'default') . '". Press Ctrl+C to stop.');
        }

        $processed = $this->worker->work(is_string($connection) ? $connection : null, is_string($queue) ? $queue : 'default', [
            'sleep' => (int) $input->option('sleep', '3'),
            'once' => $input->hasOption('once'),
            'stop_when_empty' => $input->hasOption('stop-when-empty'),
            'max_jobs' => (int) $input->option('max-jobs', '0'),
            'max_time' => (int) $input->option('max-time', '0'),
            'memory' => (int) $input->option('memory', '128'),
        ]);

        if ($input->hasOption('stop-when-empty') || $input->hasOption('once')) {
            $output->info("Processed {$processed} job(s).");
        }

        return self::SUCCESS;
    }
}
