<?php

declare(strict_types=1);

use Racin\Application;
use Racin\Config\Env;
use Racin\Http\Exceptions\HttpException;

if (!function_exists('app')) {
    /**
     * The application, or a service resolved from it.
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $app = Application::getInstance();

        return $abstract === null ? $app : $app->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Application::getInstance()->config()->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Environment variable with "true"/"false"/"null"/"empty" converted. Use only in config files.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Application::getInstance()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return Application::getInstance()->storagePath($path);
    }
}

if (!function_exists('abort')) {
    /**
     * @param array<string, string> $headers
     */
    function abort(int $status, string $message = '', array $headers = []): never
    {
        throw new HttpException($status, $message, $headers);
    }
}

if (!function_exists('abort_if')) {
    function abort_if(bool $condition, int $status, string $message = ''): void
    {
        if ($condition) {
            abort($status, $message);
        }
    }
}

if (!function_exists('__')) {
    /**
     * Translate a "group.key" line for the current locale.
     *
     * @param array<string, scalar|null> $replace
     */
    function __(string $key, array $replace = [], ?string $locale = null): string
    {
        return Application::getInstance()->make(Racin\Translation\Translator::class)->get($key, $replace, $locale);
    }
}

if (!function_exists('trans_choice')) {
    /**
     * @param array<string, scalar|null> $replace
     */
    function trans_choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        return Application::getInstance()->make(Racin\Translation\Translator::class)->choice($key, $count, $replace, $locale);
    }
}

if (!function_exists('dispatch')) {
    /**
     * Push a job onto the default queue connection. Returns the job id.
     */
    function dispatch(Racin\Queue\Job $job, int $delay = 0, ?string $queue = null): string
    {
        return Application::getInstance()->make(Racin\Queue\QueueManager::class)->push($job, $delay, $queue);
    }
}

if (!function_exists('broadcast')) {
    /**
     * Send a real-time event to WebSocket channels.
     *
     * @param string|list<string> $channels
     * @param array<string, mixed> $data
     */
    function broadcast(string|array $channels, string $event, array $data = []): void
    {
        Application::getInstance()->make(Racin\Broadcasting\Broadcaster::class)->broadcast((array) $channels, $event, $data);
    }
}

if (!function_exists('storage_url')) {
    /**
     * Public URL of a stored file (null for null paths or private disks).
     */
    function storage_url(?string $path, ?string $disk = null): ?string
    {
        return $path === null || $path === '' ? null : Application::getInstance()->make(Racin\Storage\StorageManager::class)->disk($disk)->url($path);
    }
}

if (!function_exists('logger')) {
    function logger(): Psr\Log\LoggerInterface
    {
        return Application::getInstance()->make(Psr\Log\LoggerInterface::class);
    }
}
