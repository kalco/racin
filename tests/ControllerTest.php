<?php

declare(strict_types=1);

namespace Racin\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Racin\Auth\AuthenticationException;
use Racin\Container\Container;
use Racin\Database\Paginator;
use Racin\Http\Attributes\BeforeAction;
use Racin\Http\Attributes\SkipBeforeAction;
use Racin\Http\Controller;
use Racin\Http\Exceptions\BadRequestHttpException;
use Racin\Http\Exceptions\NotFoundHttpException;
use Racin\Http\JsonResponse;
use Racin\Http\Request;
use Racin\Http\Response;
use Racin\Http\Serializer;
use Racin\Http\Status;
use Racin\Routing\Route;
use Racin\Routing\Router;

final class Recorder
{
    /** @var list<string> */
    public array $calls = [];
}

final class WidgetSerializer extends Serializer
{
    protected array $attributes = ['id', 'name', 'label', 'made_at'];

    public function label(object $widget): string
    {
        return strtoupper($widget->name) . ($this->context['suffix'] ?? '');
    }
}

#[BeforeAction('authenticate')]
abstract class BaseController extends Controller
{
    public function __construct(protected readonly Recorder $recorder)
    {
    }

    protected function authenticate(): void
    {
        $this->recorder->calls[] = 'authenticate';
        $name = $this->request->header('X-User') ?? throw new AuthenticationException();
        $this->request->setUser((object) ['name' => $name]);
    }
}

#[SkipBeforeAction('authenticate', only: ['index'])]
#[BeforeAction('setWidget', only: ['show', 'update'])]
final class WidgetsController extends BaseController
{
    private object $widget;

    public function __construct(Recorder $recorder)
    {
        parent::__construct($recorder);
        $this->beforeAction(function (): void {
            $this->recorder->calls[] = 'closure:' . $this->widget->name;
        }, only: ['show']);
        $this->beforeAction('halt', only: ['update']);
    }

    public function index(): Response
    {
        $widgets = [self::find(1), self::find(2)];

        return $this->render(json: new Paginator($widgets, 7, 2, 1), eachSerializer: WidgetSerializer::class, meta: ['source' => 'test']);
    }

    public function show(): Response
    {
        $this->recorder->calls[] = 'show';

        return $this->render(json: $this->widget, serializer: WidgetSerializer::class, context: ['suffix' => '!' . $this->currentUser()->name]);
    }

    public function create(): Response
    {
        $params = $this->params()->require('widget')->permit('name', ['tags' => []], ['flags' => []], ['size' => ['w', 'h']]);

        return $this->render(json: $params, status: 'created', root: false);
    }

    public function update(): Response
    {
        $this->recorder->calls[] = 'update';

        return $this->head('no_content');
    }

    public function destroy(): Response
    {
        return $this->head('accepted', ['X-Queued' => '1']);
    }

    protected function setWidget(int $id): void
    {
        $this->recorder->calls[] = "setWidget:{$id}";
        $this->widget = self::find($id) ?? throw new NotFoundHttpException('No widget.');
    }

    protected function halt(): ?Response
    {
        return $this->render(json: ['halted' => true], status: 'locked');
    }

    protected function helper(): string
    {
        return 'not an action';
    }

    private static function find(int $id): ?object
    {
        return $id <= 3 ? (object) ['id' => $id, 'name' => "widget{$id}", 'made_at' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00')] : null;
    }
}

final class ControllerTest extends TestCase
{
    private Container $container;

    private Router $router;

    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->recorder = new Recorder();
        $this->container->instance(Recorder::class, $this->recorder);
        $this->router = new Router($this->container);
        $this->router->group(['prefix' => 'v1', 'as' => 'v1.'], function (Router $router): void {
            $router->resource('widgets', WidgetsController::class);
            $router->get('/widgets/helper', [WidgetsController::class, 'helper']);
            $router->get('/widgets/call', [WidgetsController::class, 'callAction']);
        });
    }

    public function testResourceRoutesUseRailsActionNames(): void
    {
        $routes = array_map(
            static fn (Route $route): string => implode('|', $route->methods()) . ' ' . $route->uri() . ' ' . $route->getName(),
            array_values(array_filter($this->router->routes(), static fn (Route $r): bool => str_contains((string) $r->getName(), 'widgets'))),
        );

        self::assertSame([
            'GET|HEAD /v1/widgets v1.widgets.index',
            'POST /v1/widgets v1.widgets.create',
            'GET|HEAD /v1/widgets/{id:\d+} v1.widgets.show',
            'PATCH|PUT /v1/widgets/{id:\d+} v1.widgets.update',
            'DELETE /v1/widgets/{id:\d+} v1.widgets.destroy',
        ], $routes);

        $router = new Router(new Container());
        $nested = $router->resource('shops.products', WidgetsController::class, only: ['index', 'show']);
        self::assertSame(['/shops/{shop_id:\d+}/products', '/shops/{shop_id:\d+}/products/{id:\d+}'], array_map(static fn (Route $r): string => $r->uri(), $nested));
        self::assertSame(['shops.products.update'], array_map(static fn (Route $r): ?string => $r->getName(), $router->resource('shops.products', WidgetsController::class, except: ['index', 'create', 'show', 'destroy'])));
    }

    public function testFiltersRunInOrderParentFirstAndGetRouteParameters(): void
    {
        $response = $this->send('GET', '/v1/widgets/2', user: 'ana');

        self::assertSame(['authenticate', 'setWidget:2', 'closure:widget2', 'show'], $this->recorder->calls);
        self::assertSame(['data' => ['id' => 2, 'name' => 'widget2', 'label' => 'WIDGET2!ana', 'made_at' => '2026-01-02T03:04:05+00:00']], $response->decoded());
    }

    public function testSkipBeforeActionAndPaginatedRender(): void
    {
        $response = $this->send('GET', '/v1/widgets');

        self::assertSame([], $this->recorder->calls, 'authenticate is skipped for index');
        self::assertSame(200, $response->status());
        $body = $response->decoded();
        self::assertSame(['WIDGET1', 'WIDGET2'], array_column($body['data'], 'label'));
        self::assertEquals(['total' => 7, 'per_page' => 2, 'current_page' => 1, 'last_page' => 4, 'source' => 'test'], array_intersect_key($body['meta'], array_flip(['total', 'per_page', 'current_page', 'last_page', 'source'])));
    }

    public function testFailingFilterStopsTheRequest(): void
    {
        try {
            $this->send('GET', '/v1/widgets/2');
            self::fail('Expected 401.');
        } catch (AuthenticationException) {
            self::assertSame(['authenticate'], $this->recorder->calls);
        }

        $this->recorder->calls = [];

        try {
            $this->send('GET', '/v1/widgets/9', user: 'ana');
            self::fail('Expected 404.');
        } catch (NotFoundHttpException) {
            self::assertSame(['authenticate', 'setWidget:9'], $this->recorder->calls);
        }
    }

    public function testFilterReturningAResponseHalts(): void
    {
        $response = $this->send('PATCH', '/v1/widgets/1', user: 'ana');

        self::assertSame(423, $response->status());
        self::assertSame(['data' => ['halted' => true]], $response->decoded());
        self::assertNotContains('update', $this->recorder->calls);
    }

    public function testParamsRequireAndPermit(): void
    {
        $response = $this->send('POST', '/v1/widgets', [
            'widget' => [
                'name' => 'Lamp',
                'admin' => true,
                'tags' => ['a', 'b'],
                'flags' => ['x', ['nested']],
                'size' => ['w' => 10, 'h' => 20, 'd' => 5],
                'secret' => ['x' => 1],
            ],
            'other' => 'ignored',
        ], user: 'ana');

        self::assertSame(201, $response->status());
        // "flags" holds a non-scalar, so the whole list is dropped (like Rails).
        self::assertSame(['name' => 'Lamp', 'tags' => ['a', 'b'], 'size' => ['w' => 10, 'h' => 20]], $response->decoded());

        $this->expectException(BadRequestHttpException::class);
        $this->send('POST', '/v1/widgets', ['name' => 'Lamp'], user: 'ana');
    }

    public function testHeadResponses(): void
    {
        $response = $this->send('DELETE', '/v1/widgets/1', user: 'ana');

        self::assertSame(202, $response->status());
        self::assertSame('', $response->content());
        self::assertSame('1', $response->getHeader('X-Queued'));
        self::assertNotInstanceOf(JsonResponse::class, $response);
    }

    public function testOnlyPublicActionsAreRoutable(): void
    {
        foreach (['/v1/widgets/helper', '/v1/widgets/call'] as $uri) {
            try {
                $this->send('GET', $uri, user: 'ana');
                self::fail("{$uri} should not be routable.");
            } catch (NotFoundHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStatusNames(): void
    {
        self::assertSame(200, Status::code('ok'));
        self::assertSame(201, Status::code('created'));
        self::assertSame(204, Status::code('no_content'));
        self::assertSame(422, Status::code('unprocessable_entity'));
        self::assertSame(418, Status::code(418));

        $this->expectException(\InvalidArgumentException::class);
        Status::code('not_a_status');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $method, string $uri, array $data = [], ?string $user = null): Response
    {
        return $this->router->dispatch(Request::create($method, $uri, $data, $user === null ? [] : ['X-User' => $user]));
    }
}
