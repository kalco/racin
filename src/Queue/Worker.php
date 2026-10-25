<?php

declare(strict_types=1);

namespace Racin\Queue;

use Psr\Log\LoggerInterface;
use Racin\Container\Container;
use Throwable;

/**
 * Takes jobs from a queue and runs them, retrying with backoff and moving jobs
 * that used up their tries to failed_jobs.
 */
final class Worker
{
    /** @var (callable(string): void)|null */
    private $output = null;

    public function __construct(
        private readonly Container $container,
        private readonly QueueManager $queues,
        private readonly FailedJobRepository $failed,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string): void $output
     */
    public function onOutput(callable $output): void
    {
        $this->output = $output;
    }

    /**
     * Process jobs until a stop condition is met. Returns the number of jobs processed.
     *
     * @param array{sleep?: int, max_jobs?: int, max_time?: int, memory?: int, stop_when_empty?: bool, once?: bool} $options
     */
    public function work(?string $connection = null, string $queue = 'default', array $options = []): int
    {
        $connection ??= $this->queues->getDefaultConnection();
        $sleep = max(0, (int) ($options['sleep'] ?? 3));
        $maxJobs = (int) ($options['max_jobs'] ?? 0);
        $maxTime = (int) ($options['max_time'] ?? 0);
        $memory = (int) ($options['memory'] ?? 128);
        $start = time();
        $processed = 0;

        while (true) {
            $ran = $this->runNext($connection, $queue);
            $processed += $ran ? 1 : 0;

            if (($options['once'] ?? false)
                || (!$ran && ($options['stop_when_empty'] ?? false))
                || ($maxJobs > 0 && $processed >= $maxJobs)
                || ($maxTime > 0 && time() - $start >= $maxTime)) {
                return $processed;
            }

            if (memory_get_usage(true) / 1048576 >= $memory) {
                $this->emit("Memory limit of {$memory} MB reached, stopping.");

                return $processed;
            }

            if (!$ran) {
                sleep($sleep);
            }
        }
    }

    /**
     * Run the next job, if any. Returns whether a job was taken.
     */
    public function runNext(string $connection, string $queue = 'default'): bool
    {
        $driver = $this->queues->connection($connection);
        $job = $driver->pop($queue);

        if ($job === null) {
            return false;
        }

        $this->process($driver, $job, $connection);

        return true;
    }

    public function process(Queue $driver, QueuedJob $job, string $connection): void
    {
        $start = hrtime(true);

        try {
            self::execute($this->container, $job->payload);
            $driver->delete($job);
            $this->emit(sprintf('Processed  %s [%s] (%.1f ms)', $job->name(), $job->id, (hrtime(true) - $start) / 1e6));
        } catch (Throwable $e) {
            $tries = max(1, (int) $job->payload['tries']);

            if ($job->attempts >= $tries) {
                $driver->delete($job);
                $this->failed->log($connection, $job->queue, $job->payload, $e);
                $this->logger->error("Queued job {$job->name()} failed after {$job->attempts} attempt(s): {$e->getMessage()}", ['exception' => $e]);
                $this->notifyFailed($job, $e);
                $this->emit("Failed     {$job->name()} [{$job->id}]: {$e->getMessage()}");

                return;
            }

            $delay = self::backoff($job->payload['backoff'], $job->attempts);
            $driver->release($job, $delay);
            $this->logger->warning("Queued job {$job->name()} attempt {$job->attempts} failed, retrying in {$delay}s: {$e->getMessage()}");
            $this->emit("Retrying   {$job->name()} [{$job->id}] in {$delay}s: {$e->getMessage()}");
        }
    }

    /**
     * Build the job from its payload and call handle() with injected dependencies.
     *
     * @param array{job: string, data: array<string, mixed>} $payload
     */
    public static function execute(Container $container, array $payload): void
    {
        $job = Job::fromPayload($container, $payload['job'], $payload['data']);
        $container->call([$job, 'handle']);
    }

    /**
     * @param int|list<int> $backoff
     */
    private static function backoff(int|array $backoff, int $attempt): int
    {
        if (is_int($backoff)) {
            return max(0, $backoff);
        }

        return $backoff === [] ? 0 : max(0, (int) ($backoff[min($attempt, count($backoff)) - 1]));
    }

    private function notifyFailed(QueuedJob $job, Throwable $e): void
    {
        try {
            Job::fromPayload($this->container, $job->payload['job'], $job->payload['data'])->failed($e);
        } catch (Throwable $inner) {
            $this->logger->error("failed() handler of {$job->name()} threw: {$inner->getMessage()}", ['exception' => $inner]);
        }
    }

    private function emit(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)('[' . date('Y-m-d H:i:s') . '] ' . $line);
        }
    }
}
