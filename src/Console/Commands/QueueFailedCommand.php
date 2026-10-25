<?php

declare(strict_types=1);

namespace Racin\Console\Commands;

use Racin\Console\Command;
use Racin\Console\Input;
use Racin\Console\Output;
use Racin\Queue\FailedJobRepository;
use Racin\Queue\Payload;
use Racin\Queue\QueueManager;
use Racin\Queue\Job;
use Racin\Container\Container;

/**
 * queue:failed, queue:retry, queue:forget and queue:flush share this class.
 */
class QueueFailedCommand extends Command
{
    protected string $name = 'queue:failed';

    protected string $description = 'List failed jobs';

    public function __construct(
        private readonly FailedJobRepository $failed,
        private readonly QueueManager $queues,
        private readonly Container $container,
        string $mode = 'failed',
    ) {
        [$this->name, $this->description, $this->usage] = match ($mode) {
            'retry' => ['queue:retry', 'Push failed job(s) back onto their queue', 'queue:retry <id|all>'],
            'forget' => ['queue:forget', 'Delete one failed job', 'queue:forget <id>'],
            'flush' => ['queue:flush', 'Delete all failed jobs', 'queue:flush'],
            default => ['queue:failed', 'List failed jobs', 'queue:failed'],
        };
    }

    public function handle(Input $input, Output $output): int
    {
        return match ($this->name) {
            'queue:retry' => $this->retry($input, $output),
            'queue:forget' => $this->forget($input, $output),
            'queue:flush' => $this->flush($output),
            default => $this->list($output),
        };
    }

    private function list(Output $output): int
    {
        $rows = array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);

            return [
                (string) $row['id'],
                (string) $row['connection'] . '/' . (string) $row['queue'],
                (string) ($payload['job'] ?? '?'),
                (string) $row['failed_at'],
                mb_strimwidth(strtok((string) $row['exception'], "\n") ?: '', 0, 70, '...'),
            ];
        }, $this->failed->all());

        $rows === [] ? $output->info('No failed jobs.') : $output->table(['ID', 'Connection/Queue', 'Job', 'Failed at', 'Error'], $rows);

        return self::SUCCESS;
    }

    private function retry(Input $input, Output $output): int
    {
        $target = $input->argument(0);

        if ($target === null) {
            $output->error('Usage: php racin queue:retry <id|all>');

            return self::FAILURE;
        }

        $rows = $target === 'all' ? $this->failed->all() : array_filter([$this->failed->find((int) $target)]);

        if ($rows === []) {
            $output->error("No failed job [{$target}].");

            return self::FAILURE;
        }

        foreach ($rows as $row) {
            $payload = Payload::decode((string) $row['payload']);
            $job = Job::fromPayload($this->container, $payload['job'], $payload['data']);
            $this->queues->connection((string) $row['connection'])->push($job, 0, (string) $row['queue']);
            $this->failed->forget((int) $row['id']);
            $output->info("Retrying failed job [{$row['id']}] {$payload['job']}");
        }

        return self::SUCCESS;
    }

    private function forget(Input $input, Output $output): int
    {
        $id = (int) $input->argument(0, '0');

        if (!$this->failed->forget($id)) {
            $output->error("No failed job [{$id}].");

            return self::FAILURE;
        }

        $output->info("Deleted failed job [{$id}].");

        return self::SUCCESS;
    }

    private function flush(Output $output): int
    {
        $output->info('Deleted ' . $this->failed->flush() . ' failed job(s).');

        return self::SUCCESS;
    }
}
