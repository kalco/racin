<?php

declare(strict_types=1);

namespace Racin\Http\Middleware;

use Closure;
use Racin\Config\Config;
use Racin\Http\Middleware;
use Racin\Http\Request;
use Racin\Http\Response;

/**
 * CORS headers and preflight handling, configured in config/cors.php.
 */
final class HandleCors implements Middleware
{
    /** @var array<string, mixed> */
    private readonly array $options;

    public function __construct(Config $config)
    {
        $this->options = (array) $config->get('cors', []) + [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['*'],
            'allowed_headers' => ['*'],
            'exposed_headers' => [],
            'max_age' => 0,
            'supports_credentials' => false,
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return $next($request);
        }

        $allowed = $this->originAllowed($origin);

        if ($request->isMethod('OPTIONS') && $request->hasHeader('Access-Control-Request-Method')) {
            $response = Response::noContent();

            if ($allowed) {
                $this->addOriginHeaders($response, $origin);
                $response->header('Access-Control-Allow-Methods', $this->allowedMethods($request));
                $response->header('Access-Control-Allow-Headers', $this->allowedHeaders($request));

                if ((int) $this->options['max_age'] > 0) {
                    $response->header('Access-Control-Max-Age', (string) (int) $this->options['max_age']);
                }
            }

            return $response->header('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
        }

        $response = $next($request);

        if ($allowed) {
            $this->addOriginHeaders($response, $origin);

            if ($this->options['exposed_headers'] !== []) {
                $response->header('Access-Control-Expose-Headers', implode(', ', (array) $this->options['exposed_headers']));
            }
        }

        return $response;
    }

    private function addOriginHeaders(Response $response, string $origin): void
    {
        $credentials = (bool) $this->options['supports_credentials'];
        $wildcard = in_array('*', (array) $this->options['allowed_origins'], true);

        if ($wildcard && !$credentials) {
            $response->header('Access-Control-Allow-Origin', '*');
        } else {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin', false);
        }

        if ($credentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
    }

    private function originAllowed(string $origin): bool
    {
        foreach ((array) $this->options['allowed_origins'] as $pattern) {
            if ($pattern === '*' || $pattern === $origin) {
                return true;
            }

            if (str_contains((string) $pattern, '*')) {
                $regex = '#^' . str_replace('\*', '[^/]*', preg_quote((string) $pattern, '#')) . '$#i';

                if (preg_match($regex, $origin) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function allowedMethods(Request $request): string
    {
        $methods = (array) $this->options['allowed_methods'];

        return in_array('*', $methods, true)
            ? strtoupper((string) $request->header('Access-Control-Request-Method', 'GET'))
            : implode(', ', array_map('strtoupper', $methods));
    }

    private function allowedHeaders(Request $request): string
    {
        $headers = (array) $this->options['allowed_headers'];

        return in_array('*', $headers, true)
            ? (string) $request->header('Access-Control-Request-Headers', '')
            : implode(', ', $headers);
    }
}
