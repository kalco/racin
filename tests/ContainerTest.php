<?php

declare(strict_types=1);

namespace Racin\Tests;

use PHPUnit\Framework\TestCase;
use Racin\Container\ArgumentTypeException;
use Racin\Container\Container;
use Racin\Container\ContainerException;
use Racin\Container\NotFoundException;

interface Clock
{
    public function now(): int;
}

final class FixedClock implements Clock
{
    public function now(): int
    {
        return 42;
    }
}

final class Service
{
    public function __construct(public readonly Clock $clock, public readonly string $label = 'default')
    {
    }
}

final class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}

final class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}

final class Invokable
{
    public function __invoke(Clock $clock, int $times = 1): int
    {
        return $clock->now() * $times;
    }
}

final class ContainerTest extends TestCase
{
    public function testAutowiresConstructorDependencies(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        $service = $container->make(Service::class);

        self::assertInstanceOf(FixedClock::class, $service->clock);
        self::assertSame('default', $service->label);
    }

    public function testSingletonReturnsSameInstanceAndBindReturnsNew(): void
    {
        $container = new Container();
        $container->singleton(Clock::class, FixedClock::class);
        $container->bind(Service::class);

        self::assertSame($container->make(Clock::class), $container->make(Clock::class));
        self::assertNotSame($container->make(Service::class), $container->make(Service::class));
    }

    public function testInstanceAliasAndClosureBinding(): void
    {
        $container = new Container();
        $clock = new FixedClock();
        $container->instance(Clock::class, $clock);
        $container->alias(Clock::class, 'clock');
        $container->bind('answer', fn (Container $c): int => $c->make('clock')->now());

        self::assertSame($clock, $container->get('clock'));
        self::assertSame(42, $container->make('answer'));
        self::assertTrue($container->has('clock'));
    }

    public function testNamedParametersOverrideAutowiring(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        self::assertSame('custom', $container->make(Service::class, ['label' => 'custom'])->label);
    }

    public function testCallInjectsAndCoercesScalarStrings(): void
    {
        $container = new Container();
        $container->bind(Clock::class, FixedClock::class);

        self::assertSame(84, $container->call(Invokable::class, ['times' => '2']));
        self::assertSame(42, $container->call([new Invokable(), '__invoke']));
        self::assertSame(5, $container->call(fn (int $a, int $b = 3): int => $a + $b, ['a' => '2']));
    }

    public function testInvalidScalarThrowsArgumentTypeException(): void
    {
        $container = new Container();

        $this->expectException(ArgumentTypeException::class);
        $container->call(fn (int $id): int => $id, ['id' => '12abc']);
    }

    public function testUnboundInterfaceAndMissingClass(): void
    {
        $container = new Container();

        try {
            $container->make(Clock::class);
            self::fail('Expected exception');
        } catch (ContainerException $e) {
            self::assertStringContainsString('not bound', $e->getMessage());
        }

        $this->expectException(NotFoundException::class);
        $container->make('Nope\\Missing');
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        (new Container())->make(CircularA::class);
    }
}
