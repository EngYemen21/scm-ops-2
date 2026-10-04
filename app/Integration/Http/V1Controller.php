<?php

namespace App\Integration\Http;

use App\Integration\Services\InboxService;
use App\Integration\Services\IntegrationRunner;
use App\Integration\Support\Systems;
use App\Support\AppError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The system-to-system gateway /api/v1 (docs/integration/ARCHITECTURE.md §5). Every route is signature-authenticated. */
class V1Controller
{
    public function health(Request $request): array
    {
        $system = (string) $request->attributes->get('intSystem');

        return [
            'status' => 'ok', 'system' => 'ops', 'apiVersion' => 'v1', 'time' => now()->toIso8601ZuluString('millisecond'),
            'caller' => ['system' => $system, 'keyId' => $request->attributes->get('intKeyId'), 'scopes' => array_values((array) (Systems::get($system)['scopes'] ?? []))],
        ];
    }

    /** One envelope, a JSON array of envelopes, or {events:[…]} (≤ max_batch). Answers per event, in order. */
    public function events(Request $request, InboxService $inbox): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (! is_array($body)) {
            throw AppError::validation('INVALID_JSON', 'جسم الطلب ليس JSON صالحًا', 'Body is not valid JSON');
        }
        $events = array_is_list($body) ? $body : (isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body]);
        if (! $events) {
            throw AppError::validation('EMPTY_BATCH', 'لا توجد أحداث', 'No events');
        }
        if (count($events) > (int) config('integration.max_batch', 100)) {
            throw AppError::validation('BATCH_TOO_LARGE', 'عدد الأحداث أكبر من المسموح', 'Too many events in one request');
        }
        $system = (string) $request->attributes->get('intSystem');
        $results = [];
        foreach ($events as $env) {
            $results[] = is_array($env) ? $inbox->accept($system, $env, $request->attributes->get('requestId'))
                : ['eventId' => null, 'status' => 'rejected', 'code' => 'INVALID_ENVELOPE', 'message' => 'event must be an object'];
        }

        return response()->json(['results' => $results], 202);
    }

    public function heartbeat(IntegrationRunner $runner): array
    {
        return $runner->run(100, 'heartbeat');
    }
}
