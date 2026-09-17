<?php

namespace App\Http\Controllers\Api\Returns;

use App\Http\Controllers\Controller;
use App\Services\Returns\ReturnsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class ReturnsController extends Controller
{
    public function __construct(private readonly ReturnsService $returns) {}

    public function index(Request $request): array
    {
        return $this->returns->list(Paging::from($request), $request->only(['status', 'type', 'warehouse']));
    }

    public function show(string $id): array
    {
        return $this->returns->get($id);
    }

    public function store(Request $request)
    {
        // foNumber / tripNumber are deliberately not accepted here: only the delivery flow links a return to its FO / trip.
        $data = $request->validate([
            'type' => 'required|in:cust,sup,del,dmg',
            'source' => 'required|string|min:1',
            'reference' => 'nullable|string',
            'customerCode' => 'nullable|string',
            'supplierCode' => 'nullable|string',
            'warehouseCode' => 'required|string|min:1',
            'reasonCode' => 'required|in:damaged,wrong_product,wrong_qty,expired,cust_reject,del_fail,quality,partial,other',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'lines' => 'required|array|min:1',
            'lines.*.sku' => 'required|string|min:1',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.batchNo' => 'nullable|string',
        ]);

        return $this->returns->create(AuthUser::current(), $data);
    }

    public function approve(string $id): array
    {
        return $this->returns->approve(AuthUser::current(), $id);
    }

    public function reject(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string', 'findings' => 'nullable|string']);

        return $this->returns->reject(AuthUser::current(), $id, $data['reason'] ?? null);
    }

    public function receive(string $id): array
    {
        return $this->returns->receive(AuthUser::current(), $id);
    }

    public function inspect(Request $request, string $id): array
    {
        $data = $request->validate(['reason' => 'nullable|string', 'findings' => 'nullable|string']);

        return $this->returns->inspect(AuthUser::current(), $id, $data['findings'] ?? null);
    }

    public function decide(Request $request, string $id): array
    {
        $data = $request->validate([
            'decision' => 'required|in:restock,qtn,dmg,dispose,sup',
            'binCode' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        return $this->returns->decide(AuthUser::current(), $id, $data);
    }
}
