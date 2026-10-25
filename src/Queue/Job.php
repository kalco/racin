<?php

declare(strict_types=1);

namespace Racin\Queue;

use InvalidArgumentException;
use Racin\Container\Container;
use ReflectionClass;
use Throwable;

/**
 * A unit of background work.
 *
 * Constructor parameters must be promoted public properties holding only
 * scalars, null and arrays (pass ids, not models): they are stored as JSON and
 * passed back to the constructor by name when the job runs. handle() gets its
 * own dependencies injected by the container.
 *
 *     final class SendInvoice extends Job
 *     {
 *         public int $tries = 5;
 *
 *         public function __construct(public readonly int $orderId)
 *         {
 *         }
 *
 *         public function handle(Mailer $mailer): void { ... }
 *     }
 *
 *     dispatch(new SendInvoice($order->id));
 */
abstract class Job
{
    /** Attempts before the job is moved to failed_jobs. */
    public int $tries = 3;

    /** Seconds to wait before a retry; a list gives one value per attempt (last one repeats). */
    public int|array $backoff = 10;

    /**
     * Queue (channel) name.
     */
    public function queue(): string
    {
        return 'default';
    }

    /**
     * Called once when the job has used up its tries.
     */
    public function failed(Throwable $exception): void
    {
    }

    /**
     * Constructor arguments to store in the payload.
     *
     * @return array<string, mixed>
     */
    public function payloadData(): array
    {
        $constructor = (new ReflectionClass($this))->getConstructor();
        $data = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if (!$parameter->isPromoted()) {
                throw new InvalidArgumentException(static::class . ": constructor parameter \${$name} must be a promoted property to be queued.");
            }

            $value = $this->{$name};
            self::assertSerializable($value, static::class . '::$' . $name);
            $data[$name] = $value;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromPayload(Container $container, string $class, array $data): self
    {
        if (!class_exists($class) || !is_subclass_of($class, self::class)) {
            throw new InvalidArgumentException("Queued class [{$class}] is not a job.");
        }

        return $container->make($class, $data);
    }

    private static function assertSerializable(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertSerializable($item, "{$path}[{$key}]");
            }

            return;
        }

        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException("{$path} holds a " . get_debug_type($value) . '; queued jobs may only hold scalars, null and arrays (pass ids instead of objects).');
        }
    }
}
