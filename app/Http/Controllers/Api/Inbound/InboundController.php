<?php

namespace App\Http\Controllers\Api\Inbound;

use App\Http\Controllers\Controller;
use App\Services\Inbound\InboundService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class InboundController extends Controller
{
    private const DATE = 'regex:/^\d{4}-\d{2}-\d{2}/';

    public function __construct(private readonly InboundService $inbound) {}

    public function shipments(Request $request): array
    {
        return $this->inbound->listShipments(Paging::from($request), $request->only(['status', 'warehouse', 'supplier', 'po']));
    }

    public function scan(string $code): array
    {
        return $this->inbound->scan($code);
    }

    public function shipment(string $id): array
    {
        return $this->inbound->getShipment($id);
    }

    public function arrive(Request $request, string $id)
    {
        $data = $request->validate(['carrier' => 'nullable|string']);

        return $this->inbound->arrive(AuthUser::current(), $id, $data['carrier'] ?? null);
    }

    public function inspect(string $id)
    {
        return $this->inbound->startInspection(AuthUser::current(), $id);
    }

    public function cancel(string $id)
    {
        return $this->inbound->cancel(AuthUser::current(), $id);
    }

    public function backorder(Request $request): array
    {
        $data = $request->validate(['po' => 'required|string', 'eta' => ['nullable', 'string', self::DATE]]);

        return $this->inbound->createBackorder(AuthUser::current(), $data['po'], $data['eta'] ?? null);
    }

    public function postGrn(Request $request, string $id): array
    {
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.lineNo' => 'required|integer|min:1',
            'lines.*.acceptedQty' => 'required|integer|min:0',
            'lines.*.damagedQty' => 'nullable|integer|min:0',
            'lines.*.rejectedQty' => 'nullable|integer|min:0',
            'lines.*.batchNo' => 'nullable|string',
            'lines.*.mfgDate' => ['nullable', 'string', self::DATE],
            'lines.*.expiryDate' => ['nullable', 'string', self::DATE],
            'lines.*.qcNote' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $lines = array_map(fn (array $l) => [
            'lineNo' => (int) $l['lineNo'], 'acceptedQty' => (int) $l['acceptedQty'], 'damagedQty' => (int) ($l['damagedQty'] ?? 0), 'rejectedQty' => (int) ($l['rejectedQty'] ?? 0),
            'batchNo' => $l['batchNo'] ?? null, 'mfgDate' => $l['mfgDate'] ?? null, 'expiryDate' => $l['expiryDate'] ?? null, 'qcNote' => $l['qcNote'] ?? null,
        ], array_values($data['lines']));

        return $this->inbound->postGrn(AuthUser::current(), $id, $lines, $data['notes'] ?? null);
    }

    public function grns(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        return $this->inbound->listGrns(Paging::from($request), $request->only(['warehouse', 'supplier', 'po', 'from', 'to']));
    }

    public function grn(string $id): array
    {
        return $this->inbound->getGrn($id);
    }

    public function putaway(Request $request): array
    {
        return $this->inbound->listPutaway(Paging::from($request), $request->only(['warehouse', 'status', 'grn']));
    }

    public function confirmPutaway(Request $request, string $id): array
    {
        $data = $request->validate(['scannedBin' => 'nullable|string', 'scannedProduct' => 'nullable|string']);

        return $this->inbound->confirmPutaway(AuthUser::current(), $id, $data['scannedBin'] ?? null, $data['scannedProduct'] ?? null);
    }
}
