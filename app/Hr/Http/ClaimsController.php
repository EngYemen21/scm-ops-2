<?php

namespace App\Hr\Http;

use App\Hr\Services\ClaimsService;
use App\Hr\Services\FilesService;
use App\Http\Controllers\Controller;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Expense claims. The routes say which permission opens each call (claimant: `expense.claim`; reviewer:
 * `expense.review`; the books: `expense.create` / `gl.approve` on top); the service decides whose claim it is.
 * Action buttons post no body: every rule here is optional unless the action cannot mean anything without it.
 */
class ClaimsController extends Controller
{
    private const LINE_RULES = [
        'lines' => 'array|max:200', 'lines.*.id' => 'nullable|string|max:40', 'lines.*.date' => 'required|date', 'lines.*.category' => 'required|string|max:40',
        'lines.*.description' => 'required|string|min:2|max:500', 'lines.*.amount' => 'required|numeric|gt:0|max:99999999', 'lines.*.vatPct' => 'nullable|numeric|min:0|max:100',
        'lines.*.amountIncludesVat' => 'nullable|boolean', 'lines.*.paidBy' => 'nullable|in:employee,company',
    ];

    public function __construct(private readonly ClaimsService $claims) {}

    public function index(Request $request): array
    {
        return $this->claims->list(AuthUser::current(), Paging::from($request, 25), $request->only(['scope', 'state', 'employee', 'department', 'source', 'from', 'to']));
    }

    public function show(string $id): array
    {
        return $this->claims->get(AuthUser::current(), $id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|min:3|max:200', 'notes' => 'nullable|string|max:2000', 'employee' => 'nullable|string|max:40'] + self::LINE_RULES);

        return response()->json($this->claims->create(AuthUser::current(), $data), 201);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['title' => 'sometimes|string|min:3|max:200', 'notes' => 'sometimes|nullable|string|max:2000'] + ['lines' => 'sometimes|array|max:200'] + self::LINE_RULES);

        return $this->claims->update(AuthUser::current(), $id, $data);
    }

    public function addReceipt(Request $request, string $id, string $lineId): JsonResponse
    {
        return response()->json($this->claims->addReceipt(AuthUser::current(), $id, $lineId, $request->validate(FilesService::rules())), 201);
    }

    public function removeReceipt(string $id, string $fileId): array
    {
        return $this->claims->removeReceipt(AuthUser::current(), $id, $fileId);
    }

    // ── the claimant
    public function submit(string $id): JsonResponse
    {
        return response()->json($this->claims->submit(AuthUser::current(), $id), 201);
    }

    public function withdraw(string $id): JsonResponse
    {
        return response()->json($this->claims->withdraw(AuthUser::current(), $id), 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->claims->cancel(AuthUser::current(), $id, $data['reason'] ?? null), 201);
    }

    // ── the reviewer
    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        return response()->json($this->claims->approve(AuthUser::current(), $id, $data['note'] ?? null), 201);
    }

    public function refuse(Request $request, string $id): JsonResponse
    {
        return response()->json($this->claims->refuse(AuthUser::current(), $id, $this->reason($request)), 201);
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        return response()->json($this->claims->sendBack(AuthUser::current(), $id, $this->reason($request)), 201);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        return response()->json($this->claims->reopen(AuthUser::current(), $id, $this->reason($request)), 201);
    }

    // ── the books
    public function post(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['date' => 'nullable|date', 'bankAccount' => 'nullable|string|max:40', 'reimburse' => 'nullable|in:later,now', 'reference' => 'nullable|string|max:80',
            'costCenter' => 'nullable|string|max:40', 'accounts' => 'nullable|array', 'accounts.*' => 'nullable|string|max:20']);

        return response()->json($this->claims->post(AuthUser::current(), $id, $data), 201);
    }

    public function unpost(Request $request, string $id): JsonResponse
    {
        return response()->json($this->claims->unpost(AuthUser::current(), $id, $this->reason($request)), 201);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|min:2|max:80', 'date' => 'nullable|date', 'method' => 'nullable|in:bank,cash,cheque', 'bankAccount' => 'nullable|string|max:40']);

        return response()->json($this->claims->pay(AuthUser::current(), $id, $data), 201);
    }

    public function unpay(Request $request, string $id): JsonResponse
    {
        return response()->json($this->claims->unpay(AuthUser::current(), $id, $this->reason($request)), 201);
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => 'required|string|min:5|max:500'])['reason'];
    }
}
