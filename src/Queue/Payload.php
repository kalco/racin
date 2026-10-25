<?php

declare(strict_types=1);

namespace Racin\Queue;

use JsonException;
use RuntimeException;

/**
 * JSON payload format shared by all queue drivers.
 */
final class Payload
{
    /**
     * @return array{uuid: string, job: class-string<Job>, data: array<string, mixed>, tries: int, backoff: int|list<int>, pushed_at: int}
     */
    public static function create(Job $job): array
    {
        return [
            'uuid' => self::uuid(),
            'job' => $job::class,
            'data' => $job->payloadData(),
            'tries' => max(1, $job->tries),
            'backoff' => is_array($job->backoff) ? array_values(array_map('intval', $job->backoff)) : $job->backoff,
            'pushed_at' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{uuid: string, job: class-string<Job>, data: array<string, mixed>, tries: int, backoff: int|list<int>, pushed_at: int}
     */
    public static function decode(string $json): array
    {
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid queue payload: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($payload) || !isset($payload['job'], $payload['uuid']) || !is_array($payload['data'] ?? null)) {
            throw new RuntimeException('Invalid queue payload.');
        }

        return $payload + ['tries' => 1, 'backoff' => 0, 'pushed_at' => 0];
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
