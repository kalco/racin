<?php

declare(strict_types=1);

namespace Racin\Auth;

use InvalidArgumentException;
use JsonException;
use Racin\Support\Str;

/**
 * JSON Web Tokens signed with HMAC (HS256 / HS384 / HS512).
 *
 * Only the configured algorithm is accepted when decoding, so "alg: none" and
 * algorithm-confusion tokens are rejected.
 */
final class Jwt
{
    private const array ALGORITHMS = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly string $algorithm = 'HS256',
        private readonly int $ttl = 3600,
        private readonly ?string $issuer = null,
        private readonly int $leeway = 30,
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The JWT secret must be at least 32 bytes. Run "php racin key:generate".');
        }

        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new InvalidArgumentException("Unsupported JWT algorithm [{$algorithm}].");
        }
    }

    /**
     * @param array<string, mixed> $claims custom claims; "sub" should identify the user
     * @param int|null $ttl seconds until expiry; null = configured default, 0 = never expires
     */
    public function encode(array $claims, ?int $ttl = null): string
    {
        $now = time();
        $ttl ??= $this->ttl;

        $claims += ['iat' => $now, 'nbf' => $now, 'jti' => bin2hex(random_bytes(16))];

        if ($ttl > 0) {
            $claims += ['exp' => $now + $ttl];
        }

        if ($this->issuer !== null) {
            $claims += ['iss' => $this->issuer];
        }

        $header = Str::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => $this->algorithm], JSON_THROW_ON_ERROR));
        $payload = Str::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $header . '.' . $payload . '.' . Str::base64UrlEncode($this->sign($header . '.' . $payload));
    }

    /**
     * Verify the signature and time claims and return the payload.
     *
     * @return array<string, mixed>
     * @throws InvalidTokenException
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidTokenException('Malformed token.');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = $this->decodeJson($header64);

        if (($header['alg'] ?? null) !== $this->algorithm) {
            throw new InvalidTokenException('Unexpected token algorithm.');
        }

        $signature = Str::base64UrlDecode($signature64);

        if ($signature === false || !hash_equals($this->sign($header64 . '.' . $payload64), $signature)) {
            throw new InvalidTokenException('Invalid token signature.');
        }

        $claims = $this->decodeJson($payload64);
        $now = time();

        if (isset($claims['exp']) && (!is_int($claims['exp']) || $now - $this->leeway >= $claims['exp'])) {
            throw new InvalidTokenException('Token has expired.');
        }

        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $now + $this->leeway < $claims['nbf'])) {
            throw new InvalidTokenException('Token is not valid yet.');
        }

        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidTokenException('Invalid token issuer.');
        }

        return $claims;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    private function sign(string $data): string
    {
        return hash_hmac(self::ALGORITHMS[$this->algorithm], $data, $this->secret, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $json = Str::base64UrlDecode($segment);

        if ($json === false) {
            throw new InvalidTokenException('Malformed token.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidTokenException('Malformed token.');
        }

        if (!is_array($data)) {
            throw new InvalidTokenException('Malformed token.');
        }

        return $data;
    }
}
