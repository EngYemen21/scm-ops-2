<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\TransfersService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /api/inventory/transfers — inter-warehouse transfers and their state machine. */
class TransfersController extends Controller
{
    public function __construct(private readonly TransfersService $transfers) {}

    public function index(Request $request): array
    {
        $f = $request->validate(['status' => 'nullable|string', 'warehouse' => 'nullable|string', 'from' => 'nullable|string', 'to' => 'nullable|string']);

        return $this->transfers->list(Paging::from($request), $f);
    }

    public function show(string $id): array
    {
        return $this->transfers->get($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fromWarehouseCode' => 'required|string|min:1', 'toWarehouseCode' => 'required|string|min:1',
            'date' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}/', 'date'], 'eta' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}-\d{2}/', 'date'],
            'reasonCode' => 'sometimes|in:shortage,rebalance,season,surplus,other', 'notes' => 'nullable|string', 'submit' => 'sometimes|boolean',
            'lines' => 'required|array|min:1', 'lines.*.sku' => 'required|string|min:1', 'lines.*.qty' => 'required|integer|min:1',
            'lines.*.batchNo' => 'nullable|string', 'lines.*.fromBin' => 'required|string|min:1', 'lines.*.toBin' => 'required|string|min:1',
        ]);
        if ($data['fromWarehouseCode'] === $data['toWarehouseCode']) {
            throw ValidationException::withMessages(['toWarehouseCode' => 'المصدر والوجهة لا يمكن أن يتطابقا']);
        }
        $data['submit'] = filter_var($data['submit'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return response()->json($this->transfers->create(AuthUser::current(), $data + ['reasonCode' => 'shortage']), 201);
    }

    public function approve(Request $request, string $id): array
    {
        return $this->transfers->act(AuthUser::current(), $id, 'approve', $this->note($request));
    }

    public function reject(Request $request, string $id): array
    {
        return $this->transfers->act(AuthUser::current(), $id, 'reject', $this->note($request));
    }

    public function receive(Request $request, string $id): array
    {
        // `lines` may be empty or absent: every line that is not listed is received in full
        $data = $request->validate([
            'lines' => 'sometimes|array', 'lines.*.lineNo' => 'required|integer|min:1', 'lines.*.receivedQty' => 'required|integer|min:0', 'note' => 'nullable|string',
        ]);

        return $this->transfers->receive(AuthUser::current(), $id, $data['lines'] ?? [], $data['note'] ?? null);
    }

    /** submit | cancel | start-picking | picking-done | ship | close */
    public function action(Request $request, string $id, string $action): array
    {
        if (! isset(TransfersService::ACTIONS[$action]) || in_array($action, ['approve', 'reject'], true)) {
            throw AppError::validation('BAD_ACTION', "إجراء غير معروف: {$action}", "Unknown transfer action {$action}");
        }

        return $this->transfers->act(AuthUser::current(), $id, $action, $this->note($request));
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => 'nullable|string'])['note'] ?? null;
    }
}
