<?php

namespace App\Portal\Http;

use App\Http\Controllers\Api\Sales\SalesRules;
use App\Http\Controllers\Controller;
use App\Portal\Models\LineChange;
use App\Portal\Models\OrderRequest;
use App\Portal\Services\LineChangesService;
use App\Portal\Services\OrderRequestsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order requests and the log of order-line changes. Thin: validate, call a service, return.
 */
class RequestsController extends Controller
{
    public function __construct(private readonly OrderRequestsService $requests, private readonly LineChangesService $changes) {}

    public function index(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'status' => 'nullable|string|max:80', 'customer' => 'nullable|string|max:160', 'branch' => 'nullable|string|max:40', 'origin' => 'nullable|in:ops,odoo',
            'from' => ['nullable', PortalRules::DAY], 'to' => ['nullable', PortalRules::DAY],
        ]);

        return $this->requests->list(Paging::from($request, 25), $filters);
    }

    public function show(string $id): array
    {
        return $this->requests->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::lineRules() + [
            'customerId' => 'required|string|max:160', 'branchId' => 'nullable|string|max:40', 'requestedBy' => 'nullable|string|max:190', 'note' => 'nullable|string|max:4000',
        ]);

        return response()->json($this->requests->create(AuthUser::current(), $data), 201);
    }

    public function lines(Request $request, string $id): array
    {
        $data = $request->validate(self::lineRules());

        return $this->requests->setLines(AuthUser::current(), $id, $data['lines']);
    }

    public function approve(Request $request, string $id): array
    {
        $data = $request->validate(['validUntil' => ['nullable', 'string', SalesRules::DATE], 'note' => 'nullable|string|max:1000']);

        return $this->requests->approve(AuthUser::current(), $id, $data);
    }

    public function reject(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000']);

        return $this->requests->reject(AuthUser::current(), $id, $data['reason']);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        return $this->requests->cancel(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function note(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|min:1|max:5000']);

        return response()->json($this->requests->note(AuthUser::current(), $id, $data['text']), 201);
    }

    /** The log of order-line changes: who changed what on which order line, before and after. */
    public function changes(Request $request): array
    {
        $filters = PortalRules::filters($request, [
            'type' => 'nullable|in:'.implode(',', LineChange::TYPES), 'field' => 'nullable|in:'.implode(',', LineChange::FIELDS), 'customer' => 'nullable|string|max:160',
            'product' => 'nullable|string|max:80', 'orderRef' => 'nullable|string|max:60', 'request' => 'nullable|string|max:40', 'by' => 'nullable|string|max:190', 'origin' => 'nullable|in:ops,odoo',
            'from' => ['nullable', PortalRules::DAY], 'to' => ['nullable', PortalRules::DAY],
        ]);

        return $this->changes->list(Paging::from($request, 50), $filters);
    }

    /** The lines of a request: whole units of products OPS sells; a price may be missing while the request waits. */
    private static function lineRules(): array
    {
        return [
            'lines' => 'required|array|min:1|max:'.OrderRequest::MAX_LINES, 'lines.*.sku' => 'required|string|min:1|max:80', 'lines.*.qty' => 'required|integer|min:1|max:9999999',
            'lines.*.price' => 'nullable|numeric|min:0|max:99999999',
        ];
    }
}
