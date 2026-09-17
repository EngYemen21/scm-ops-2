<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\CountsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /api/inventory/counts — cycle / full / spot / ABC counts. */
class CountsController extends Controller
{
    public function __construct(private readonly CountsService $counts) {}

    public function index(Request $request): array
    {
        $f = $request->validate(['status' => 'nullable|string', 'warehouse' => 'nullable|string', 'type' => 'nullable|string']);

        return $this->counts->list(Paging::from($request), $f, AuthUser::current());
    }

    public function show(string $id): array
    {
        return $this->counts->get($id, AuthUser::current());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouseCode' => 'required|string|min:1', 'zoneCode' => 'nullable|string', 'type' => 'sometimes|in:cycle,full,spot,abc',
            'scope' => 'sometimes|in:zone,all,abc,neg,exp,random', 'blind' => 'sometimes|boolean', 'freeze' => 'sometimes|boolean',
            'date' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}/', 'date'], 'counterUsername' => 'nullable|string', 'notes' => 'nullable|string',
        ]);
        $data += ['type' => 'cycle', 'scope' => 'zone'];
        $data['blind'] = filter_var($data['blind'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $data['freeze'] = filter_var($data['freeze'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($data['scope'] === 'zone' && empty($data['zoneCode'])) {
            throw ValidationException::withMessages(['zoneCode' => 'نطاق «منطقة» يتطلب رمز المنطقة']);
        }

        return response()->json($this->counts->schedule(AuthUser::current(), $data), 201);
    }

    public function start(string $id): array
    {
        return $this->counts->start(AuthUser::current(), $id);
    }

    public function enter(Request $request, string $id): array
    {
        $data = $request->validate(['lines' => 'required|array|min:1', 'lines.*.lineId' => 'required|string|min:1', 'lines.*.countedQty' => 'required|integer|min:0']);

        return $this->counts->enter(AuthUser::current(), $id, $data['lines']);
    }

    public function complete(string $id): array
    {
        return $this->counts->complete(AuthUser::current(), $id);
    }

    public function approve(Request $request, string $id): array
    {
        return $this->counts->approve(AuthUser::current(), $id, $this->note($request));
    }

    public function close(Request $request, string $id): array
    {
        return $this->counts->close(AuthUser::current(), $id, $this->note($request));
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => 'nullable|string'])['note'] ?? null;
    }
}
