<?php

declare(strict_types=1);

namespace Racin\Http;

use JsonException;
use Racin\Http\Exceptions\BadRequestHttpException;
use Racin\Routing\Route;
use Racin\Support\Arr;
use Racin\Validation\Validator;

class Request
{
    /** @var list<string> proxies whose X-Forwarded-* headers are trusted */
    protected static array $trustedProxies = [];

    /** @var array<string, string> lower-case header name => value */
    protected array $headers = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $routeParameters = [];

    protected ?Route $route = null;

    protected mixed $user = null;

    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    /** @var array<string, UploadedFile|array<mixed>>|null */
    private ?array $normalizedFiles = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $form parsed form body ($_POST); null = parse from content
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files raw $_FILES structure
     * @param array<string, string> $cookies
     */
    public function __construct(
        protected string $method = 'GET',
        protected string $uri = '/',
        protected array $query = [],
        protected ?array $form = null,
        array $headers = [],
        protected array $server = [],
        protected array $files = [],
        protected array $cookies = [],
        protected ?string $content = null,
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }

        $this->method = strtoupper($method);

        // PHP only parses multipart uploads for POST, so forms send POST with
        // "_method=PATCH" (or the X-HTTP-Method-Override header) to update with a file.
        if ($this->method === 'POST') {
            $override = strtoupper((string) ($this->header('X-HTTP-Method-Override') ?? (is_string($this->form['_method'] ?? null) ? $this->form['_method'] : '')));

            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $override;
            }
        }
    }

    /**
     * Build a request from PHP's superglobals.
     */
    public static function capture(): static
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && $value !== '') {
                $headers[str_replace('_', '-', $key)] = (string) $value;
            }
        }

        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        return new static(
            $method,
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            strtoupper($method) === 'POST' && $_POST !== [] ? $_POST : null,
            $headers,
            $_SERVER,
            $_FILES,
            $_COOKIE,
        );
    }

    /**
     * Build a request by hand (tests, internal sub-requests).
     *
     * @param array<string, mixed> $parameters query string for GET/HEAD, JSON body otherwise (form fields when $files are given)
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     * @param array<string, UploadedFile|array<mixed>> $files uploads, as a multipart/form-data request
     */
    public static function create(
        string $method,
        string $uri,
        array $parameters = [],
        array $headers = [],
        ?string $content = null,
        array $server = [],
        array $files = [],
    ): static {
        $method = strtoupper($method);
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $form = null;

        if (in_array($method, ['GET', 'HEAD'], true)) {
            $query = array_replace($query, $parameters);
        } elseif ($files !== []) {
            $form = $parameters;
            $headers += ['Content-Type' => 'multipart/form-data; boundary=racin'];
        } elseif ($content === null && $parameters !== []) {
            $content = json_encode($parameters, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }

        $server += [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_NAME' => 'localhost',
            'HTTP_HOST' => $headers['Host'] ?? 'localhost',
        ];

        return new static($method, $uri, $query, $form, $headers, $server, $files, [], $content ?? '');
    }

    /**
     * @param list<string> $proxies IP addresses of reverse proxies / load balancers
     */
    public static function setTrustedProxies(array $proxies): void
    {
        static::$trustedProxies = $proxies;
    }

    // ---------------------------------------------------------------------
    // Request line
    // ---------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Path relative to the application root, always starting with "/" and without a trailing slash.
     */
    public function path(): string
    {
        $path = rawurldecode(explode('?', $this->uri, 2)[0]);
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');

        if (str_ends_with($script, '.php')) {
            $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

            // A project in a sub-folder whose .htaccess rewrites into public/ ("/api/v1/x" served by
            // /api/public/index.php): the URL has no "/public", so the folder above it is the base.
            $bases = str_ends_with($base, '/public') ? [$base, substr($base, 0, -strlen('/public'))] : [$base];

            if (str_starts_with($path, $script)) {
                $path = substr($path, strlen($script));
            } else {
                foreach ($bases as $candidate) {
                    if ($candidate !== '' && ($path === $candidate || str_starts_with($path, $candidate . '/'))) {
                        $path = substr($path, strlen($candidate));
                        break;
                    }
                }
            }
        }

        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');

        return $path;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        if ($this->isFromTrustedProxy() && ($proto = $this->header('X-Forwarded-Proto')) !== null) {
            return strtolower(trim(explode(',', $proto)[0])) === 'https';
        }

        $https = $this->server['HTTPS'] ?? '';

        return $https !== '' && strtolower((string) $https) !== 'off';
    }

    public function host(): string
    {
        $host = $this->header('Host') ?? (string) ($this->server['SERVER_NAME'] ?? 'localhost');
        $host = strtolower(trim($host));

        // Reject anything that is not a plausible host[:port] to avoid header injection.
        return preg_match('/^[a-z0-9.\-\[\]:]+$/', $host) === 1 ? $host : 'localhost';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . explode('?', $this->uri, 2)[0];
    }

    public function fullUrl(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri;
    }

    /**
     * Client IP. X-Forwarded-For is only honoured when the direct peer is a trusted proxy.
     */
    public function ip(): ?string
    {
        $remote = isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : null;

        if ($remote === null || !$this->isFromTrustedProxy()) {
            return $remote;
        }

        $forwarded = $this->header('X-Forwarded-For');

        if ($forwarded === null) {
            return $remote;
        }

        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));

        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }

            if (!in_array($ip, static::$trustedProxies, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    // ---------------------------------------------------------------------
    // Headers
    // ---------------------------------------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        if (preg_match('/^Bearer\s+(\S+)$/i', trim((string) $header), $match) === 1) {
            return $match[1];
        }

        return null;
    }

    public function isJson(): bool
    {
        $type = strtolower((string) $this->header('Content-Type', ''));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    // ---------------------------------------------------------------------
    // Input
    // ---------------------------------------------------------------------

    /**
     * Raw request body.
     */
    public function content(): string
    {
        if ($this->content === null) {
            $this->content = (string) file_get_contents('php://input');
        }

        return $this->content;
    }

    /**
     * Query string value(s).
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : Arr::get($this->query, $key, $default);
    }

    /**
     * Parsed body: JSON for JSON requests, form fields otherwise.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        if ($this->isJson()) {
            $content = trim($this->content());

            if ($content === '') {
                return $this->parsedBody = [];
            }

            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $e) {
                throw new BadRequestHttpException('Malformed JSON body: ' . $e->getMessage(), $e);
            }

            return $this->parsedBody = is_array($decoded) ? $decoded : [];
        }

        if ($this->form !== null) {
            return $this->parsedBody = $this->form;
        }

        $type = strtolower((string) $this->header('Content-Type', ''));

        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            $fields = [];
            parse_str($this->content(), $fields);

            return $this->parsedBody = $fields;
        }

        return $this->parsedBody = [];
    }

    /**
     * All input: query string merged with the body (body wins).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->body());
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->all() : Arr::get($this->all(), $key, $default);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        $result = [];

        foreach ($keys as $key) {
            if (Arr::has($all, $key)) {
                Arr::set($result, $key, Arr::get($all, $key));
            }
        }

        return $result;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        $all = $this->all();

        foreach ($keys as $key) {
            Arr::forget($all, $key);
        }

        return $all;
    }

    public function has(string $key): bool
    {
        return Arr::has($this->all(), $key);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return !($value === null || $value === '' || $value === []);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->input($key);

        return is_array($value) ? $value : [];
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Uploaded file(s) for a field, or null.
     *
     * @return UploadedFile|array<mixed>|null
     */
    public function file(string $key): UploadedFile|array|null
    {
        return Arr::get($this->allFiles(), $key);
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);

        return $file instanceof UploadedFile && $file->isValid();
    }

    /**
     * Every upload, as (nested) UploadedFile objects.
     *
     * @return array<string, mixed>
     */
    public function allFiles(): array
    {
        return $this->normalizedFiles ??= self::normalizeFiles($this->files);
    }

    /**
     * Validate all input (including uploads) and return the validated subset.
     * Throws ValidationException (422) on failure.
     *
     * @param array<string, string|array<mixed>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validate(array $rules, array $messages = []): array
    {
        return Validator::make(array_replace($this->all(), $this->allFiles()), $rules, $messages)->validate();
    }

    // ---------------------------------------------------------------------
    // Routing / attributes / auth
    // ---------------------------------------------------------------------

    public function setRoute(Route $route, array $parameters): void
    {
        $this->route = $route;
        $this->routeParameters = $parameters;
    }

    public function route(?string $parameter = null, mixed $default = null): mixed
    {
        if ($parameter === null) {
            return $this->route;
        }

        return $this->routeParameters[$parameter] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function user(): mixed
    {
        return $this->user;
    }

    public function setUser(mixed $user): void
    {
        $this->user = $user;
    }

    protected function isFromTrustedProxy(): bool
    {
        $remote = $this->server['REMOTE_ADDR'] ?? null;

        return $remote !== null && in_array($remote, static::$trustedProxies, true);
    }

    /**
     * Convert PHP's $_FILES layout into nested UploadedFile objects.
     *
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function normalizeFiles(array $files): array
    {
        $result = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $result[$key] = $file; // already built (tests, Request::create)
                continue;
            }

            if (!is_array($file)) {
                continue;
            }

            if (isset($file['tmp_name']) && !is_array($file['tmp_name'])) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $result[$key] = UploadedFile::fromArray($file);
                }

                continue;
            }

            if (isset($file['tmp_name']) && is_array($file['tmp_name'])) {
                $nested = [];

                foreach (array_keys($file['tmp_name']) as $index) {
                    $nested[$index] = [
                        'tmp_name' => $file['tmp_name'][$index],
                        'name' => $file['name'][$index] ?? '',
                        'size' => $file['size'][$index] ?? 0,
                        'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    ];
                }

                $result[$key] = self::normalizeFiles($nested);
                continue;
            }

            $result[$key] = self::normalizeFiles($file);
        }

        return $result;
    }
}
