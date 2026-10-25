<?php

declare(strict_types=1);

namespace Racin\Http;

use InvalidArgumentException;

/**
 * HTTP status names as in Rails (status: 'created', 'unprocessable_entity', ...).
 */
final class Status
{
    public const array CODES = [
        'continue' => 100,
        'ok' => 200,
        'created' => 201,
        'accepted' => 202,
        'no_content' => 204,
        'moved_permanently' => 301,
        'found' => 302,
        'see_other' => 303,
        'not_modified' => 304,
        'bad_request' => 400,
        'unauthorized' => 401,
        'payment_required' => 402,
        'forbidden' => 403,
        'not_found' => 404,
        'method_not_allowed' => 405,
        'not_acceptable' => 406,
        'conflict' => 409,
        'gone' => 410,
        'payload_too_large' => 413,
        'unsupported_media_type' => 415,
        'unprocessable_entity' => 422,
        'unprocessable_content' => 422,
        'locked' => 423,
        'too_many_requests' => 429,
        'internal_server_error' => 500,
        'not_implemented' => 501,
        'bad_gateway' => 502,
        'service_unavailable' => 503,
    ];

    public static function code(int|string $status): int
    {
        if (is_int($status)) {
            return $status;
        }

        return self::CODES[$status] ?? throw new InvalidArgumentException("Unknown HTTP status name [{$status}].");
    }
}
