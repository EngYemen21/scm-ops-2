<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Generic REST ERP: POST {ERP_BASE_URL}/documents/{type} with `{ type, payload, source, at }`. */
class HttpErp implements ErpAdapter
{
    protected string $label = 'ERP';

    public function __construct(protected readonly string $baseUrl, protected readonly ?string $token = null, protected readonly int $timeoutMs = 15000) {}

    public function configured(): bool
    {
        return true;
    }

    protected function endpoint(string $type): string
    {
        return rtrim($this->baseUrl, '/').'/documents/'.rawurlencode($type);
    }

    public function pushDocument(string $type, array $payload): array
    {
        try {
            $request = Http::timeout($this->timeoutMs / 1000)->acceptJson()->asJson();
            if ($this->token) {
                $request = $request->withToken($this->token);
            }
            $res = $request->post($this->endpoint($type), ['type' => $type, 'payload' => (object) $payload, 'source' => 'scm-ops', 'at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')]);
            if (! $res->successful()) {
                return ['status' => 'error', 'detail' => trim("HTTP {$res->status()} ".Pending::snippet($res->body(), 100))];
            }
            $json = $res->json();
            $reference = is_array($json) ? ($json['id'] ?? $json['reference'] ?? $json['documentId'] ?? null) : null;

            return ['status' => 'ok', 'reference' => $reference !== null ? (string) $reference : null];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("{$this->label} {$type} failed: {$detail}");

            return ['status' => 'error', 'detail' => $detail];
        }
    }
}
