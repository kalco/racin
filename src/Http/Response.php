<?php

declare(strict_types=1);

namespace Racin\Http;

class Response
{
    /** @var array<string, array{name: string, values: list<string>}> keyed by lower-case name */
    protected array $headers = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(protected string $content = '', protected int $status = 200, array $headers = [])
    {
        $this->withHeaders($headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function noContent(int $status = 204, array $headers = []): self
    {
        return new self('', $status, $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException("Invalid HTTP status code [{$status}].");
        }

        $this->status = $status;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function header(string $name, string $value, bool $replace = true): static
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Header names and values may not contain line breaks.');
        }

        $key = strtolower($name);

        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = ['name' => $name, 'values' => [$value]];
        } else {
            $this->headers[$key]['values'][] = $value;
        }

        return $this;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            foreach ((array) $value as $index => $single) {
                $this->header((string) $name, (string) $single, $index === 0);
            }
        }

        return $this;
    }

    public function getHeader(string $name): ?string
    {
        $header = $this->headers[strtolower($name)] ?? null;

        return $header === null ? null : implode(', ', $header['values']);
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    /**
     * @return array<string, string> original header name => value
     */
    public function headers(): array
    {
        $result = [];

        foreach ($this->headers as $header) {
            $result[$header['name']] = implode(', ', $header['values']);
        }

        return $result;
    }

    /**
     * Emit status, headers and body.
     */
    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $header) {
                foreach ($header['values'] as $index => $value) {
                    header($header['name'] . ': ' . $value, $index === 0);
                }
            }
        }

        if ($withBody && $this->status !== 204 && $this->status !== 304) {
            echo $this->content;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
