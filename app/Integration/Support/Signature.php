<?php

namespace App\Integration\Support;

/**
 * HMAC-SHA256 request signing between B2B systems (docs/integration/ARCHITECTURE.md §9).
 *
 *   X-B2B-System     the calling system's code (e.g. "sales")
 *   X-B2B-Key-Id     which of its keys signed (two may be active during a rotation)
 *   X-B2B-Timestamp  unix seconds; rejected outside ±max_skew_seconds (replay window)
 *   X-B2B-Signature  hex HMAC-SHA256(secret, "timestamp\nMETHOD\npath?query\nsha256hex(body)")
 *
 * The body hash binds the exact bytes sent; the path binds the endpoint, so a captured signature cannot be replayed
 * against another route or with another payload.
 */
final class Signature
{
    public const H_SYSTEM = 'X-B2B-System';

    public const H_KEY = 'X-B2B-Key-Id';

    public const H_TIME = 'X-B2B-Timestamp';

    public const H_SIG = 'X-B2B-Signature';

    public static function canonical(string $timestamp, string $method, string $pathWithQuery, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".$pathWithQuery."\n".hash('sha256', $body);
    }

    public static function sign(string $secret, string $timestamp, string $method, string $pathWithQuery, string $body): string
    {
        return hash_hmac('sha256', self::canonical($timestamp, $method, $pathWithQuery, $body), $secret);
    }

    /** Headers for an outgoing signed request. */
    public static function headers(string $system, string $keyId, string $secret, string $method, string $pathWithQuery, string $body, ?int $timestamp = null): array
    {
        $ts = (string) ($timestamp ?? now()->getTimestamp());

        return [
            self::H_SYSTEM => $system, self::H_KEY => $keyId, self::H_TIME => $ts,
            self::H_SIG => self::sign($secret, $ts, $method, $pathWithQuery, $body),
        ];
    }
}
