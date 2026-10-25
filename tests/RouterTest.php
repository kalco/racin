<?php

declare(strict_types=1);

namespace Racin\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Racin\Container\Container;
use Racin\Http\Exceptions\MethodNotAllowedHttpException;
use Racin\Http\Exceptions\NotFoundHttpException;
use Racin\Http\JsonResponse;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Routing\Attributes\Get;
use Racin\Routing\Attributes\Middleware as UseMiddleware;
use Racin\Routing\Attributes\Post;
use Racin\Routing\Attributes\Prefix;
use Racin\Routing\Router;

final class PostsController
{
    public function show(int $id, Request $request): array
    {
        return ['id' => $id, 'q' => $request->query('q')];
    }

    public function slug(?string $slug = 'none'): array
    {
        return ['slug' => $slug];
    }
}

#[Prefix('/articles', name: 'articles.')]
#[UseMiddleware('tag:class')]
final class ArticlesController
{
    #[Get('/{id:\d+}', name: 'show')]
    public function show(int $id): array
    {
        return ['article' => $id];
    }

    #[Post('/', middleware: 'tag:method')]
    public function store(): array
    {
        return ['created' => true];
    }
}

final class TagMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, string $tag = 'x'): Response
    {
        $response = $next($request);

        return $response->header('X-Tags', trim($response->getHeader('X-Tags') . ',' . $tag, ','));
    }
}

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router(new Container());
        $router->aliasMiddleware('tag', TagMiddleware::class);

        return $router;
    }

    public function testMatchesStaticAndDynamicRoutesAndInjectsParameters(): void
    {
        $router = $this->router();
        $router->get('/posts/{id:\d+}', [PostsController::class, 'show']);
        $router->get('/ping', fn (): array => ['pong' => true]);

        $response = $router->dispatch(Request::create('GET', '/posts/15?q=x'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['id' => 15, 'q' => 'x'], $response->decoded());
        self::assertSame(['pong' => true], $router->dispatch(Request::create('GET', '/ping/'))->decoded());
    }

    public function testOptionalParameters(): void
    {
        $router = $this->router();
        $router->get('/tags/{slug?}', [PostsController::class, 'slug']);

        self::assertSame(['slug' => 'php'], $router->dispatch(Request::create('GET', '/tags/php'))->decoded());
        self::assertSame(['slug' => 'none'], $router->dispatch(Request::create('GET', '/tags'))->decoded());
    }

    public function testConstraintMismatchIsNotFound(): void
    {
        $router = $this->router();
        $router->get('/posts/{id:\d+}', [PostsController::class, 'show']);

        $this->expectException(NotFoundHttpException::class);
        $router->dispatch(Request::create('GET', '/posts/abc'));
    }

    public function testUnconstrainedNonNumericForIntParameterIsNotFound(): void
    {
        $router = $this->router();
        $router->get('/posts/{id}', [PostsController::class, 'show']);

        $this->expectException(NotFoundHttpException::class);
        $router->dispatch(Request::create('GET', '/posts/abc'));
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $router = $this->router();
        $router->get('/items', fn (): array => []);
        $router->post('/items', fn (): array => []);

        try {
            $router->dispatch(Request::create('DELETE', '/items'));
            self::fail('Expected 405');
        } catch (MethodNotAllowedHttpException $e) {
            self::assertSame(405, $e->getStatusCode());
            self::assertSame('GET, HEAD, POST', $e->getHeaders()['Allow']);
        }
    }

    public function testHeadFallsBackToGetAndNullBecomes204(): void
    {
        $router = $this->router();
        $router->get('/empty', fn () => null);

        self::assertSame(204, $router->dispatch(Request::create('HEAD', '/empty'))->status());
    }

    public function testGroupsPrefixMiddlewareAndNames(): void
    {
        $router = $this->router();

        $router->group(['prefix' => 'v1', 'middleware' => 'tag:outer', 'as' => 'v1.'], function (Router $router): void {
            $router->group(['prefix' => '/admin', 'middleware' => ['tag:inner'], 'as' => 'admin.'], function (Router $router): void {
                $router->get('/stats', fn (): array => ['ok' => true])->middleware('tag:route')->name('stats');
            });
        });

        $response = $router->dispatch(Request::create('GET', '/v1/admin/stats'));

        // Outermost middleware runs last on the way out.
        self::assertSame('route,inner,outer', $response->getHeader('X-Tags'));
        self::assertSame('/v1/admin/stats', $router->path('v1.admin.stats'));
    }

    public function testReversePathWithParametersAndQuery(): void
    {
        $router = $this->router();
        $router->get('/users/{id}/posts/{post?}', fn (): array => [])->name('user.posts');

        self::assertSame('/users/5/posts/9', $router->path('user.posts', ['id' => 5, 'post' => 9]));
        self::assertSame('/users/5/posts?page=2', $router->path('user.posts', ['id' => 5, 'page' => 2]));
    }

    public function testAttributeRoutes(): void
    {
        $router = $this->router();
        $router->controllers([ArticlesController::class]);

        $show = $router->dispatch(Request::create('GET', '/articles/7'));
        $store = $router->dispatch(Request::create('POST', '/articles'));

        self::assertSame(['article' => 7], $show->decoded());
        self::assertSame('class', $show->getHeader('X-Tags'));
        self::assertSame('method,class', $store->getHeader('X-Tags'));
        self::assertSame('/articles/3', $router->path('articles.show', ['id' => 3]));
    }

    public function testRouteCacheRoundTrip(): void
    {
        $router = $this->router();
        $router->get('/posts/{id:\d+}', [PostsController::class, 'show'])->name('posts.show')->middleware('tag:a');

        $cached = $this->router();
        $cached->loadCache($router->toCache());

        self::assertSame(['id' => 3, 'q' => null], $cached->dispatch(Request::create('GET', '/posts/3'))->decoded());
        self::assertSame('/posts/3', $cached->path('posts.show', ['id' => 3]));
    }

    public function testClosureRoutesCannotBeCached(): void
    {
        $router = $this->router();
        $router->get('/x', fn (): array => []);

        $this->expectException(\LogicException::class);
        $router->toCache();
    }
}
