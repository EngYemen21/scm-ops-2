<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationsService;
use App\Services\Integrations\OutboxService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationsController extends Controller
{
    public function __construct(private readonly IntegrationsService $integrations, private readonly OutboxService $outbox) {}

    public function status(): array
    {
        return $this->integrations->status();
    }

    public function outbox(Request $request): array
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(OutboxService::STATUSES)], 'type' => 'nullable|string']);
        $type = trim((string) ($data['type'] ?? ''));

        return $this->outbox->list(Paging::from($request), $data['status'] ?? null, $type === '' ? null : $type);
    }

    public function run(Request $request): array
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:1000']);

        return $this->outbox->processPending((int) ($data['limit'] ?? 50));
    }

    /** The event after the retry; JSON `null` for an unknown id (the reference answers an empty success, not a 404). */
    public function retry(string $id): JsonResponse
    {
        $event = $this->outbox->retry($id);

        return $event ? response()->json($event) : (new JsonResponse)->setJson('null');
    }

    public function attach(Request $request)
    {
        $this->trim($request, ['entityType', 'entityId', 'fileName', 'mime']);
        $data = $request->validate(['entityType' => 'required|string|min:1|max:60', 'entityId' => 'required|string|min:1|max:60'] + self::fileRules());

        return $this->integrations->attach(AuthUser::current(), $data['entityType'], $data['entityId'], $data);
    }

    public function attachments(Request $request)
    {
        $this->trim($request, ['entityType', 'entityId']);
        $data = $request->validate(['entityType' => 'required|string|min:1', 'entityId' => 'required|string|min:1']);

        return $this->integrations->attachments($data['entityType'], $data['entityId']);
    }

    public function podAttach(Request $request, string $podId)
    {
        $this->trim($request, ['fileName', 'mime']);
        $data = $request->validate(['kind' => 'required|in:signature,photo'] + self::fileRules());

        return $this->integrations->podAttach($podId, $data['kind'], $data, AuthUser::current());
    }

    public function podAttachments(string $podId)
    {
        return $this->integrations->podAttachments($podId);
    }

    /** Base64 of a 5 MB file is ~6.7 MB of text (+ an optional data-URL prefix). */
    private static function fileRules(): array
    {
        $maxBase64Chars = (int) ceil(IntegrationsService::MAX_ATTACHMENT_BYTES / 3) * 4 + 128;

        return [
            'fileName' => 'required|string|min:1|max:200',
            'mime' => ['required', 'string', 'max:120', 'regex:/^[\w.+-]+\/[\w.+-]+$/'],
            'base64' => "required|string|min:1|max:{$maxBase64Chars}",
        ];
    }

    private function trim(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => trim($request->input($key))]);
            }
        }
    }
}
