<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** S3-compatible object storage over plain HTTP + SigV4 (path-style addressing: {endpoint}/{bucket}/{key}). */
class S3CompatibleStorage implements ObjectStorageAdapter
{
    private readonly SigV4 $signer;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $bucket,
        string $accessKey,
        string $secretKey,
        ?string $region = null,
        /** Optional public base URL (CDN) for uploaded objects; when absent a presigned URL is returned. */
        private readonly ?string $publicBaseUrl = null,
        private readonly int $timeoutMs = 15000,
    ) {
        $this->signer = new SigV4($accessKey, $secretKey, $region ?: 'us-east-1', 's3');
    }

    public function configured(): bool
    {
        return true;
    }

    private function objectUrl(string $key): string
    {
        return rtrim($this->endpoint, '/').'/'.SigV4::uriEncode($this->bucket).'/'.SigV4::uriEncode($key, false);
    }

    public function putObject(string $key, string $bytes, string $mime): array
    {
        $mime = $mime ?: 'application/octet-stream';
        $signed = $this->signer->signRequest('PUT', $this->objectUrl($key), ['content-type' => $mime], hash('sha256', $bytes));
        $res = Http::timeout($this->timeoutMs / 1000)->withHeaders($signed['headers'])->withBody($bytes, $mime)->put($signed['url']);
        if (! $res->successful()) {
            throw new RuntimeException("storage PUT failed: HTTP {$res->status()} ".Pending::snippet($res->body()));
        }
        $url = $this->publicBaseUrl ? rtrim($this->publicBaseUrl, '/').'/'.SigV4::uriEncode($key, false) : $this->getSignedUrl($key);

        return ['status' => 'uploaded', 'key' => $key, 'url' => $url];
    }

    public function getSignedUrl(string $key, int $expiresSeconds = 900): ?string
    {
        return $this->signer->presignUrl('GET', $this->objectUrl($key), $expiresSeconds);
    }

    /** HEAD bucket with a hard timeout; never throws. */
    public function probe(int $timeoutMs): array
    {
        try {
            $signed = $this->signer->signRequest('HEAD', rtrim($this->endpoint, '/').'/'.SigV4::uriEncode($this->bucket), [], hash('sha256', ''));
            $res = Http::timeout($timeoutMs / 1000)->connectTimeout($timeoutMs / 1000)->withHeaders($signed['headers'])->head($signed['url']);

            return $res->successful() ? ['ok' => true, 'detail' => "bucket {$this->bucket} reachable"] : ['ok' => false, 'detail' => "HTTP {$res->status()}"];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("storage probe failed: {$detail}");

            return ['ok' => false, 'detail' => $detail];
        }
    }
}
