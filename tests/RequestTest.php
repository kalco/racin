<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Http\Exceptions\BadRequestHttpException;
use Racin\Http\Request;

final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([]);
    }

    public function testPathStripsScriptAndBaseDirectory(): void
    {
        $sub = new Request('GET', '/api/public/users/5?x=1', server: ['SCRIPT_NAME' => '/api/public/index.php']);
        $script = new Request('GET', '/index.php/users//5/', server: ['SCRIPT_NAME' => '/index.php']);
        $root = new Request('GET', '/', server: ['SCRIPT_NAME' => '/index.php']);

        self::assertSame('/users/5', $sub->path());
        self::assertSame('/users/5', $script->path());
        self::assertSame('/', $root->path());

        // Sub-folder project rewriting into public/: /api/v1/... is served by /api/public/index.php.
        self::assertSame('/v1/posts/7', (new Request('GET', '/api/v1/posts/7', server: ['SCRIPT_NAME' => '/api/public/index.php']))->path());
        self::assertSame('/', (new Request('GET', '/api', server: ['SCRIPT_NAME' => '/api/public/index.php']))->path());
        self::assertSame('/apiary', (new Request('GET', '/apiary', server: ['SCRIPT_NAME' => '/api/public/index.php']))->path(), 'only whole segments');    }

    public function testJsonBodyAndTypedAccessors(): void
    {
        $request = Request::create('POST', '/x?page=2', ['name' => '  Ana ', 'age' => '31', 'admin' => 'yes', 'tags' => ['a'], 'meta' => ['k' => 'v']]);

        self::assertTrue($request->isJson());
        self::assertSame('Ana', $request->string('name'));
        self::assertSame(31, $request->integer('age'));
        self::assertSame(0, $request->integer('missing'));
        self::assertTrue($request->boolean('admin'));
        self::assertSame(['a'], $request->array('tags'));
        self::assertSame('v', $request->input('meta.k'));
        self::assertSame(2, $request->integer('page'));
        self::assertSame(['name' => '  Ana ', 'meta' => ['k' => 'v']], $request->only(['name', 'meta']));
        self::assertTrue($request->filled('name'));
        self::assertFalse($request->has('nope'));
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $request = Request::create('POST', '/x', [], ['Content-Type' => 'application/json'], '{broken');

        $this->expectException(BadRequestHttpException::class);
        $request->all();
    }

    public function testFormEncodedPutBody(): void
    {
        $request = Request::create('PUT', '/x', [], ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1&b[c]=2');

        self::assertSame(['a' => '1', 'b' => ['c' => '2']], $request->body());
    }

    public function testHeadersBearerAndMethodOverride(): void
    {
        $request = Request::create('POST', '/x', [], ['Authorization' => 'Bearer abc.def.ghi', 'X-HTTP-Method-Override' => 'patch']);

        self::assertSame('abc.def.ghi', $request->bearerToken());
        self::assertSame('PATCH', $request->method());
        self::assertSame('Bearer abc.def.ghi', $request->header('authorization'));
        self::assertNull(Request::create('GET', '/', [], ['Authorization' => 'Basic x'])->bearerToken());
    }

    public function testForwardedHeadersOnlyTrustedFromProxies(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1'];
        $headers = ['X-Forwarded-For' => '203.0.113.9, 10.0.0.2', 'X-Forwarded-Proto' => 'https'];

        $untrusted = Request::create('GET', '/', [], $headers, null, $server);
        self::assertSame('10.0.0.1', $untrusted->ip());
        self::assertFalse($untrusted->isSecure());

        Request::setTrustedProxies(['10.0.0.1', '10.0.0.2']);
        $trusted = Request::create('GET', '/', [], $headers, null, $server);
        self::assertSame('203.0.113.9', $trusted->ip());
        self::assertTrue($trusted->isSecure());
    }

    public function testHostHeaderIsSanitised(): void
    {
        self::assertSame('localhost', Request::create('GET', '/', [], ['Host' => "evil.com\r\nX: y"])->host());
        self::assertSame('api.local:8080', Request::create('GET', '/', [], ['Host' => 'API.local:8080'])->host());
    }
}
