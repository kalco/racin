<?php

declare(strict_types=1);

namespace Racin\Support;

use Racin\Application;

/**
 * Base class for service providers listed in config/app.php "providers".
 *
 * register() runs first for every provider (bind things into the container),
 * boot() runs afterwards (use things other providers registered).
 */
abstract class ServiceProvider
{
    public function __construct(protected Application $app)
    {
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
