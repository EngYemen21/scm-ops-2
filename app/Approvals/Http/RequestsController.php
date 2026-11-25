<?php

namespace App\Approvals\Http;

use App\Approvals\Services\ApprovalFlow;
use App\Approvals\Services\AttachmentsService;
use App\Approvals\Services\RequestsService;
use App\Http\Controllers\Controller;
use App\Services\Integrations\FileStore;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Approval requests: the lists, one request, raising and editing it, the decisions of its approvers, its comments and files. */
class RequestsController extends Controller
{
    public function __construct(private readonly RequestsService $requests, private readonly AttachmentsService $attachments) {}

    public function summary(): array
    {
        return $this->requests->summary(AuthUser::current());
    }

    public function index(Request $request): array
    {
        $f = $request->validate([
            'scope' => ['nullable', Rule::in(RequestsService::SCOPES)], 'status' => 'nullable|string|max:120', 'category' => 'nullable|string|max:60', 'source' => 'nullable|in:odoo,ops',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'requester' => 'nullable|string|max:120', 'amountMin' => 'nullable|numeric', 'amountMax' => 'nullable|numeric',
        ]);

        return $this->requests->list(AuthUser::current(), Paging::from($request, 25), $f);
    }

    public function show(string $id): array
    {
        return $this->requests->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => 'required|string|max:60', 'submit' => 'nullable|boolean', 'requester' => 'nullable|string|max:60'] + self::rules());

        return response()->json($this->requests->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        return $this->requests->update(AuthUser::current(), $id, $request->validate(self::rules()));
    }

    public function submit(string $id): array
    {
        return $this->requests->submit(AuthUser::current(), $id);
    }

    public function approve(Request $request, string $id): array
    {
        $data = $request->validate(['comment' => 'nullable|string|max:2000']);

        return $this->requests->approve(AuthUser::current(), $id, $data['comment'] ?? null);
    }

    public function refuse(Request $request, string $id): array
    {
        $data = $request->validate(['comment' => 'nullable|string|max:2000']);

        return $this->requests->refuse(AuthUser::current(), $id, $data['comment'] ?? null);
    }

    public function withdraw(Request $request, string $id): array
    {
        $data = $request->validate(['comment' => 'nullable|string|max:2000']);

        return $this->requests->withdraw(AuthUser::current(), $id, $data['comment'] ?? null);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->requests->cancel(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function reopen(string $id): array
    {
        return $this->requests->reopen(AuthUser::current(), $id);
    }

    public function addApprover(Request $request, string $id): array
    {
        $data = $request->validate(['user' => 'required|string|max:60', 'required' => 'nullable|boolean', 'sequence' => 'nullable|integer|min:0|max:100000']);

        return $this->requests->addApprover(AuthUser::current(), $id, $data);
    }

    public function removeApprover(Request $request, string $id, string $line): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->requests->removeApprover(AuthUser::current(), $id, $line, $data['reason'] ?? null);
    }

    public function comment(Request $request, string $id): array
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:4000']);

        return $this->requests->comment(AuthUser::current(), $id, $data['text']);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        // base64 of a 5 MB file is ~6.7 MB of text (+ an optional data-URL prefix)
        $maxBase64 = (int) ceil(AttachmentsService::MAX_BYTES / 3) * 4 + 128;
        $data = $request->validate([
            'fileName' => 'required|string|min:1|max:200', 'mime' => ['required', 'string', 'max:120', 'regex:/^[\w.+-]+\/[\w.+-]+$/'], 'base64' => "required|string|min:1|max:{$maxBase64}",
        ]);

        return response()->json($this->attachments->add(AuthUser::current(), $id, $data), 201);
    }

    public function detach(Request $request, string $id, string $attachment): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->attachments->remove(AuthUser::current(), $id, $attachment, $data['reason'] ?? null);
    }

    /** The bytes of one attachment: images and PDF inline, anything else as a download. */
    public function file(string $id, string $attachment): Response
    {
        $file = $this->attachments->open(AuthUser::current(), $id, $attachment);
        $inline = in_array($file['mime'], FileStore::INLINE_MIMES, true);
        $headers = [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file['fileName']),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ];
        if (isset($file['disk'])) {
            // a file of the Odoo archive can be large: it is streamed from its disk, never loaded whole
            return response()->stream(function () use ($file) {
                $stream = Storage::disk($file['disk'])->readStream($file['path']);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);
        }

        return response($file['bytes'], 200, $headers + ['Content-Length' => (string) $file['size']]);
    }

    /** The fields of a request, the same when it is raised and when its draft is edited. */
    private static function rules(): array
    {
        return [
            'title' => 'nullable|string|max:250', 'amount' => 'nullable|numeric|min:0|max:9999999999', 'quantity' => 'nullable|numeric|min:0|max:9999999999',
            'date' => 'nullable|date', 'periodStart' => 'nullable|date', 'periodEnd' => 'nullable|date', 'location' => 'nullable|string|max:250', 'reference' => 'nullable|string|max:120',
            'partnerType' => ['nullable', Rule::in(ApprovalFlow::PARTNER_TYPES)], 'partner' => 'nullable|string|max:60', 'partnerName' => 'nullable|string|max:250',
            'paymentMethod' => ['nullable', Rule::in(ApprovalFlow::PAYMENT_METHODS)], 'reason' => 'nullable|string|max:8000',
            'items' => 'nullable|array|max:200', 'items.*.product' => 'nullable|string|max:80', 'items.*.description' => 'nullable|string|max:500',
            'items.*.quantity' => 'required|numeric|gt:0|max:9999999999', 'items.*.uom' => 'nullable|string|max:60',
            'approvers' => 'nullable|array|max:20', 'approvers.*.user' => 'required|string|max:60', 'approvers.*.required' => 'nullable|boolean', 'approvers.*.sequence' => 'nullable|integer|min:0|max:100000',
        ];
    }
}
