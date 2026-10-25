<?php

declare(strict_types=1);

namespace Racin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Racin\Container\Container;
use Racin\Database\Connection;
use Racin\Database\DatabaseManager;
use Racin\Queue\DatabaseQueue;
use Racin\Queue\FailedJobRepository;
use Racin\Queue\Job;
use Racin\Queue\Queue;
use Racin\Queue\QueueManager;
use Racin\Queue\RedisQueue;
use Racin\Queue\Worker;
use Racin\Redis\RedisFactory;
use RuntimeException;

final class JobLog
{
    /** @var list<string> */
    public array $lines = [];
}

final class RecordJob extends Job
{
    public function __construct(public readonly string $text, public readonly array $tags = [])
    {
    }

    public function handle(JobLog $log): void
    {
        $log->lines[] = $this->text . ':' . implode(',', $this->tags);
    }
}

final class FlakyJob extends Job
{
    public int $tries = 3;

    public int|array $backoff = 0;

    public static int $calls = 0;

    public static int $failedCalls = 0;

    public function __construct(public readonly int $failTimes)
    {
    }

    public function handle(): void
    {
        if (++self::$calls <= $this->failTimes) {
            throw new RuntimeException('flaky #' . self::$calls);
        }
    }

    public function failed(\Throwable $exception): void
    {
        self::$failedCalls++;
    }
}

final class ObjectJob extends Job
{
    public function __construct(public readonly object $thing)
    {
    }

    public function handle(): void
    {
    }
}

final class QueueTest extends TestCase
{
    private Container $container;

    private Connection $db;

    protected function setUp(): void
    {
        FlakyJob::$calls = FlakyJob::$failedCalls = 0;
        $manager = new DatabaseManager(['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]]);
        $this->db = $manager->connection();
        $this->db->unprepared('
            CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER NULL, available_at INTEGER, created_at INTEGER);
            CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, connection TEXT, queue TEXT, payload TEXT, exception TEXT, failed_at TEXT);
        ');

        $this->container = new Container();
        $this->container->instance(DatabaseManager::class, $manager);
        $this->container->singleton(JobLog::class);
    }

    private function worker(array $config): Worker
    {
        $queues = new QueueManager($this->container, $config);

        return new Worker($this->container, $queues, new FailedJobRepository($this->db), new NullLogger());
    }

    public function testSyncQueueRunsImmediately(): void
    {
        $queues = new QueueManager($this->container, ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
        $queues->push(new RecordJob('now', ['a']));

        self::assertSame(['now:a'], $this->container->make(JobLog::class)->lines);
    }

    public function testDatabaseQueueDelaysAndProcessesInOrder(): void
    {
        $config = ['default' => 'database', 'connections' => ['database' => ['driver' => 'database']]];
        $queues = new QueueManager($this->container, $config);
        $queues->push(new RecordJob('first'));
        $queues->push(new RecordJob('later'), 3600);
        $queues->push(new RecordJob('second', ['x', 'y']));

        self::assertSame(3, $queues->connection()->size());

        $processed = $this->worker($config)->work(null, 'default', ['stop_when_empty' => true, 'sleep' => 0]);

        self::assertSame(2, $processed);
        self::assertSame(['first:', 'second:x,y'], $this->container->make(JobLog::class)->lines);
        self::assertSame(1, $queues->connection()->size(), 'the delayed job is still waiting');
    }

    public function testRetriesThenSucceeds(): void
    {
        $config = ['default' => 'database', 'connections' => ['database' => ['driver' => 'database']]];
        (new QueueManager($this->container, $config))->push(new FlakyJob(2));

        $this->worker($config)->work(null, 'default', ['stop_when_empty' => true, 'sleep' => 0]);

        self::assertSame(3, FlakyJob::$calls);
        self::assertSame(0, FlakyJob::$failedCalls);
        self::assertSame(0, $this->db->table('jobs')->count());
        self::assertSame(0, $this->db->table('failed_jobs')->count());
    }

    public function testMovesToFailedJobsAfterTries(): void
    {
        $config = ['default' => 'database', 'connections' => ['database' => ['driver' => 'database']]];
        (new QueueManager($this->container, $config))->push(new FlakyJob(99));

        $this->worker($config)->work(null, 'default', ['stop_when_empty' => true, 'sleep' => 0]);

        self::assertSame(3, FlakyJob::$calls);
        self::assertSame(1, FlakyJob::$failedCalls);
        self::assertSame(0, $this->db->table('jobs')->count());

        $failed = (new FailedJobRepository($this->db))->all();
        self::assertCount(1, $failed);
        self::assertStringContainsString('flaky #3', (string) $failed[0]['exception']);
        self::assertSame(FlakyJob::class, json_decode((string) $failed[0]['payload'], true)['job']);
    }

    public function testCrashedWorkerJobBecomesAvailableAgain(): void
    {
        $queue = new DatabaseQueue($this->db, 'jobs', retryAfter: 60);
        $queue->push(new RecordJob('x'));

        self::assertNotNull($queue->pop());
        self::assertNull($queue->pop(), 'reserved');

        $this->db->table('jobs')->whereRaw('1 = 1')->update(['reserved_at' => time() - 61]);
        self::assertSame(2, $queue->pop()?->attempts);
    }

    public function testOnlySerializableConstructorValuesAreAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ObjectJob(new \stdClass()))->payloadData();
    }

    public function testRedisQueue(): void
    {
        $redis = self::redisOrSkip();
        $prefix = 'racin:test:' . bin2hex(random_bytes(4)) . ':';
        $queue = new RedisQueue($redis, $prefix, retryAfter: 60);

        try {
            $queue->push(new RecordJob('r1'));
            $queue->push(new RecordJob('r2'), 3600);
            self::assertSame(2, $queue->size());

            $job = $queue->pop();
            // Redis' cjson may reorder keys; jobs are rebuilt with named arguments, so order does not matter.
            self::assertEquals(['text' => 'r1', 'tags' => []], $job?->payload['data']);
            self::assertSame(1, $job->attempts);
            self::assertNull($queue->pop());

            $queue->release($job, 0);
            $again = $queue->pop();
            self::assertSame(2, $again?->attempts);

            $queue->delete($again);
            self::assertSame(1, $queue->size(), 'only the delayed job is left');
        } finally {
            foreach ($redis->keys($prefix . '*') ?: [] as $key) {
                $redis->del($key);
            }
        }
    }

    public static function redisOrSkip(): \Redis
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        try {
            return RedisFactory::connect(['host' => getenv('REDIS_HOST') ?: 'Redis', 'port' => 6379, 'timeout' => 1.0]);
        } catch (RuntimeException $e) {
            self::markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }
    }
}
