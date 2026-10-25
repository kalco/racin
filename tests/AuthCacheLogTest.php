<?php

declare(strict_types=1);

namespace Racin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Racin\Auth\InvalidTokenException;
use Racin\Auth\Jwt;
use Racin\Cache\ArrayStore;
use Racin\Cache\Cache;
use Racin\Cache\FileStore;
use Racin\Cache\Store;
use Racin\Log\Logger;
use Racin\Support\Hash;
use Racin\Support\Str;
use RuntimeException;

final class AuthCacheLogTest extends TestCase
{
    private const string SECRET = 'a-very-secret-key-of-at-least-32-bytes!!';

    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temp)) {
            (new FileStore($this->temp))->flush();
            @array_map('unlink', glob($this->temp . '/*') ?: []);
            @rmdir($this->temp);
        }
    }

    public function testJwtRoundTrip(): void
    {
        $jwt = new Jwt(self::SECRET, 'HS256', 60, 'racin');
        $claims = $jwt->decode($jwt->encode(['sub' => 7, 'role' => 'admin']));

        self::assertSame(7, $claims['sub']);
        self::assertSame('admin', $claims['role']);
        self::assertSame('racin', $claims['iss']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function testJwtRejectsTamperingExpiryAndForeignAlgorithms(): void
    {
        $jwt = new Jwt(self::SECRET, 'HS256', 60, null, 0);
        $token = $jwt->encode(['sub' => 1]);
        [$header, $payload, $signature] = explode('.', $token);

        $forged = Str::base64UrlEncode((string) json_encode(['sub' => 2, 'exp' => time() + 60]));
        $none = Str::base64UrlEncode('{"typ":"JWT","alg":"none"}');

        $cases = [
            'payload swapped' => "{$header}.{$forged}.{$signature}",
            'alg none' => "{$none}.{$payload}.",
            'other key' => (new Jwt(str_repeat('x', 32)))->encode(['sub' => 1]),
            'other algorithm' => (new Jwt(self::SECRET, 'HS512'))->encode(['sub' => 1]),
            'expired' => $jwt->encode(['sub' => 1, 'exp' => time() - 1]),
            'garbage' => 'not-a-token',
        ];

        foreach ($cases as $name => $bad) {
            try {
                $jwt->decode($bad);
                self::fail("Token accepted: {$name}");
            } catch (InvalidTokenException $e) {
                self::assertSame(401, $e->getStatusCode(), $name);
            }
        }
    }

    public function testJwtRequiresLongSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Jwt('short');
    }

    public function testPasswordHashing(): void
    {
        $hash = Hash::make('secret123');

        self::assertTrue(Hash::verify('secret123', $hash));
        self::assertFalse(Hash::verify('wrong', $hash));
        self::assertFalse(Hash::needsRehash($hash));
        self::assertFalse(Hash::verify('anything', Hash::DUMMY));
        self::assertSame(Hash::COST, password_get_info(Hash::DUMMY)['options']['cost']);
    }

    /**
     * @return iterable<string, array{0: \Closure(string): Store}>
     */
    public static function stores(): iterable
    {
        yield 'array' => [fn (string $dir): Store => new ArrayStore()];
        yield 'file' => [fn (string $dir): Store => new FileStore($dir)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testCacheStores(\Closure $factory): void
    {
        $cache = new Cache($factory($this->temp), 'p:');

        self::assertNull($cache->get('missing'));
        self::assertSame('d', $cache->get('missing', 'd'));

        $cache->put('arr', ['a' => 1], 60);
        self::assertSame(['a' => 1], $cache->get('arr'));
        self::assertTrue($cache->has('arr'));

        self::assertFalse($cache->add('arr', 'other'));
        self::assertTrue($cache->add('new', 'v'));

        self::assertSame(1, $cache->increment('hits', 1, 60));
        self::assertSame(4, $cache->increment('hits', 3));
        self::assertSame(3, $cache->decrement('hits'));

        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };
        self::assertSame('computed', $cache->remember('r', 60, $compute));
        self::assertSame('computed', $cache->remember('r', 60, $compute));
        self::assertSame(1, $calls);

        $cache->put('false', false);
        self::assertFalse($cache->get('false'));

        $cache->put('gone', 'x', 0);
        self::assertNull($cache->get('gone'));

        self::assertSame('v', $cache->pull('new'));
        self::assertNull($cache->get('new'));

        $cache->flush();
        self::assertNull($cache->get('arr'));
    }

    public function testLoggerWritesInterpolatedLinesAboveThreshold(): void
    {
        $logger = new Logger($this->temp, 'info', 'testing');
        $logger->debug('hidden');
        $logger->info('User {id} logged in', ['id' => 5]);
        $logger->error('Boom', ['exception' => new RuntimeException('bad thing')]);

        $contents = (string) file_get_contents($logger->file());

        self::assertStringNotContainsString('hidden', $contents);
        self::assertMatchesRegularExpression('/\] testing\.INFO: User 5 logged in \{"id":5\}/', $contents);
        self::assertStringContainsString('testing.ERROR: Boom {"exception":{"class":"RuntimeException","message":"bad thing"', $contents);
    }
}
