<?php

namespace App\Services\Integrations\Adapters;

use DateTimeInterface;

/**
 * AWS Signature Version 4 without an SDK. Works for S3 and S3-compatible stores (MinIO, Cloudflare R2, Spaces, Wasabi…).
 * Header signing for requests, query signing for presigned URLs.
 * Reference: https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final class SigV4
{
    public function __construct(private readonly string $accessKey, private readonly string $secretKey, private readonly string $region = 'us-east-1', private readonly string $service = 's3') {}

    /** RFC 3986 encoding as required by SigV4 (encodes everything except unreserved characters). */
    public static function uriEncode(string $value, bool $encodeSlash = true): string
    {
        $encoded = rawurlencode($value);

        return $encodeSlash ? $encoded : str_replace('%2F', '/', $encoded);
    }

    /**
     * Signs a request with an Authorization header. $payloadHash is the hex SHA-256 of the body (or 'UNSIGNED-PAYLOAD').
     *
     * @param  array<string,string>  $headers
     * @return array{url:string, headers:array<string,string>}
     */
    public function signRequest(string $method, string $url, array $headers, string $payloadHash, ?DateTimeInterface $now = null): array
    {
        [$amz, $date] = self::amzDate($now);
        $parts = parse_url($url);
        $all = $headers + ['host' => self::host($parts), 'x-amz-date' => $amz, 'x-amz-content-sha256' => $payloadHash];
        $lower = [];
        foreach ($all as $k => $v) {
            $lower[strtolower($k)] = preg_replace('/\s+/', ' ', trim($v));
        }
        ksort($lower);
        $canonicalHeaders = implode('', array_map(fn ($k, $v) => "{$k}:{$v}\n", array_keys($lower), $lower));
        $signedHeaders = implode(';', array_keys($lower));
        $canonicalRequest = implode("\n", [strtoupper($method), self::canonicalPath($parts['path'] ?? '/'), self::canonicalQuery($parts['query'] ?? ''), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $signature = hash_hmac('sha256', implode("\n", ['AWS4-HMAC-SHA256', $amz, $scope, hash('sha256', $canonicalRequest)]), $this->signingKey($date));

        return ['url' => $url, 'headers' => $headers + [
            'x-amz-date' => $amz, 'x-amz-content-sha256' => $payloadHash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ]];
    }

    /** Presigned URL (query-string auth); valid for $expiresSeconds (max 7 days per AWS). */
    public function presignUrl(string $method, string $url, int $expiresSeconds, ?DateTimeInterface $now = null): string
    {
        [$amz, $date] = self::amzDate($now);
        $parts = parse_url($url);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        parse_str($parts['query'] ?? '', $query);
        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => "{$this->accessKey}/{$scope}", 'X-Amz-Date' => $amz,
            'X-Amz-Expires' => (string) min(max(1, $expiresSeconds), 604800), 'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = self::canonicalQuery($query);
        $host = self::host($parts);
        $canonicalRequest = implode("\n", [strtoupper($method), self::canonicalPath($parts['path'] ?? '/'), $canonicalQuery, "host:{$host}\n", 'host', 'UNSIGNED-PAYLOAD']);
        $signature = hash_hmac('sha256', implode("\n", ['AWS4-HMAC-SHA256', $amz, $scope, hash('sha256', $canonicalRequest)]), $this->signingKey($date));

        return ($parts['scheme'] ?? 'https')."://{$host}".($parts['path'] ?? '/')."?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    /** @return array{0:string, 1:string} [20260915T120000Z, 20260915] */
    private static function amzDate(?DateTimeInterface $now): array
    {
        $amz = gmdate('Ymd\THis\Z', ($now ?? now())->getTimestamp());

        return [$amz, substr($amz, 0, 8)];
    }

    private static function host(array $parts): string
    {
        return ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function signingKey(string $date): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        foreach ([$this->region, $this->service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return $key;
    }

    private static function canonicalQuery(array|string $query): string
    {
        if (is_string($query)) {
            parse_str($query, $parsed);
            $query = $parsed;
        }
        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = [self::uriEncode((string) $k), self::uriEncode((string) $v)];
        }
        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn ($p) => "{$p[0]}={$p[1]}", $pairs));
    }

    /** S3 canonical URI: every segment encoded exactly once (segments in the URL are already encoded — decode first). */
    private static function canonicalPath(string $path): string
    {
        return implode('/', array_map(fn ($segment) => self::uriEncode(rawurldecode($segment)), explode('/', $path))) ?: '/';
    }
}
