<?php

declare(strict_types=1);

namespace Racin\Queue;

use Redis;

/**
 * Redis queue: a list of ready jobs plus sorted sets for delayed and reserved
 * jobs. Reserved jobs not finished within $retryAfter seconds are retried.
 */
final class RedisQueue implements Queue
{
    /** Move due members of a sorted set (KEYS[1]) onto the ready list (KEYS[2]). */
    private const string MIGRATE = <<<'LUA'
        local due = redis.call('zrangebyscore', KEYS[1], '-inf', ARGV[1])
        for i = 1, #due do
            redis.call('zrem', KEYS[1], due[i])
            redis.call('rpush', KEYS[2], due[i])
        end
        return #due
        LUA;

    /** Pop a job from KEYS[1], bump attempts and store it in the reserved set KEYS[2] until ARGV[1]. */
    private const string POP = <<<'LUA'
        local job = redis.call('lpop', KEYS[1])
        if not job then
            return false
        end
        local reserved = cjson.decode(job)
        reserved['attempts'] = (reserved['attempts'] or 0) + 1
        reserved = cjson.encode(reserved)
        redis.call('zadd', KEYS[2], ARGV[1], reserved)
        return reserved
        LUA;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'racin:queue:',
        private readonly int $retryAfter = 90,
    ) {
    }

    public function push(Job $job, int $delay = 0, ?string $queue = null): string
    {
        $queue ??= $job->queue();
        $payload = Payload::create($job) + ['attempts' => 0];
        $encoded = Payload::encode($payload);

        if ($delay > 0) {
            $this->redis->zAdd($this->key($queue, 'delayed'), time() + $delay, $encoded);
        } else {
            $this->redis->rPush($this->key($queue), $encoded);
        }

        return $payload['uuid'];
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now = time();
        $this->redis->eval(self::MIGRATE, [$this->key($queue, 'delayed'), $this->key($queue), $now], 2);
        $this->redis->eval(self::MIGRATE, [$this->key($queue, 'reserved'), $this->key($queue), $now], 2);

        $reserved = $this->redis->eval(self::POP, [$this->key($queue), $this->key($queue, 'reserved'), $now + $this->retryAfter], 2);

        if (!is_string($reserved)) {
            return null;
        }

        $payload = Payload::decode($reserved);

        return new QueuedJob($payload['uuid'], $queue, $payload, (int) ($payload['attempts'] ?? 1), $reserved);
    }

    public function delete(QueuedJob $job): void
    {
        $this->redis->zRem($this->key($job->queue, 'reserved'), $job->raw);
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $this->redis->zRem($this->key($job->queue, 'reserved'), $job->raw);
        $this->redis->zAdd($this->key($job->queue, 'delayed'), time() + max(0, $delay), $job->raw);
    }

    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->lLen($this->key($queue))
            + (int) $this->redis->zCard($this->key($queue, 'delayed'))
            + (int) $this->redis->zCard($this->key($queue, 'reserved'));
    }

    private function key(string $queue, string $suffix = ''): string
    {
        return $this->prefix . $queue . ($suffix === '' ? '' : ':' . $suffix);
    }
}
