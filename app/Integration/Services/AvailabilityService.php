<?php

namespace App\Integration\Services;

use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\TransferLine;
use App\Models\Warehouse;
use App\Services\Core\SettingsService;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use Illuminate\Support\Carbon;

/**
 * Stock as another system may see it (ARCHITECTURE §4 "ATP"), per product it knows by ITS id. Read-only and computed
 * by the central inventory engine's own rules — Sales never computes stock.
 *
 *   onHand      physical quantity in every location (incl. staging, quarantine, damaged, expired)
 *   reserved    promised to sales orders; allocated = the part already tied to bins/batches
 *   available   what the engine would allocate now: storage zones, not quarantined/blocked/expired, minus reserved
 *   quarantine / damaged / expired / staging   the quantities that are NOT sellable, by reason
 *   nearExpiry  available quantity expiring within the configured window (sell first)
 *   inTransfer  moving between warehouses towards this scope
 *   incoming    open purchase-order quantity not yet received; incomingEta = earliest expected arrival
 *   atp         Available To Promise now = available; atpWithIncoming adds what is on order (dated by incomingEta)
 */
class AvailabilityService
{
    public const MAX_PRODUCTS = 100;

    public function __construct(private readonly ExternalRefs $refs, private readonly InventoryService $inventory, private readonly SettingsService $settings) {}

    /** @param string[] $externalIds */
    public function forExternal(string $system, array $externalIds, ?string $warehouseCode = null): array
    {
        $externalIds = array_values(array_unique(array_filter(array_map('trim', $externalIds), fn ($v) => $v !== '')));
        if (! $externalIds) {
            throw AppError::validation('PRODUCTS_REQUIRED', 'حدد المنتجات', 'products is required (comma-separated ids)');
        }
        if (count($externalIds) > self::MAX_PRODUCTS) {
            throw AppError::validation('TOO_MANY_PRODUCTS', 'عدد المنتجات أكبر من المسموح', 'At most '.self::MAX_PRODUCTS.' products per call');
        }
        $warehouse = null;
        if ($warehouseCode) {
            $warehouse = Warehouse::where('code', $warehouseCode)->where('active', true)->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', 'المستودع غير موجود', 'Warehouse not found');
        }
        $map = $this->refs->internalIds($system, 'product', $externalIds);
        $items = [];
        foreach ($externalIds as $ext) {
            $pid = $map[$ext] ?? null;
            $items[] = $pid ? ['productId' => $ext, 'mapped' => true] + $this->forProduct($pid, $warehouse) : ['productId' => $ext, 'mapped' => false];
        }

        return ['asOf' => now()->toIso8601ZuluString('millisecond'), 'warehouse' => $warehouse?->code, 'items' => $items];
    }

    public function forProduct(string $productId, ?Warehouse $warehouse = null): array
    {
        $p = Product::findOrFail($productId);
        $today = Carbon::today();
        $soon = $today->copy()->addDays((int) ($this->settings->get('inventory.expiringSoonDays') ?: 30));
        $rows = InventoryBalance::with(['bin.zone', 'batch'])->where('product_id', $productId)
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))->get();
        $sum = ['onHand' => 0, 'reserved' => 0, 'allocated' => 0, 'quarantine' => 0, 'damaged' => 0, 'expired' => 0, 'staging' => 0, 'nearExpiry' => 0];
        foreach ($rows as $r) {
            $zone = $r->bin?->zone?->type;
            $expired = $r->batch?->expiry_date && $r->batch->expiry_date->lt($today);
            $sum['onHand'] += $r->on_hand;
            $sum['reserved'] += $r->reserved;
            $sum['allocated'] += $r->allocated;
            match (true) {
                $r->quarantine || $zone === 'quarantine' => $sum['quarantine'] += $r->on_hand,
                $zone === 'damaged' => $sum['damaged'] += $r->on_hand,
                $expired => $sum['expired'] += $r->on_hand,
                $zone === 'staging' => $sum['staging'] += $r->on_hand,
                default => null,
            };
            if (! $expired && ! $r->quarantine && ! $r->blocked && $r->batch?->expiry_date && $r->batch->expiry_date->lte($soon)
                && ! in_array($zone, InventoryService::NON_STORAGE_ZONES, true)) {
                $sum['nearExpiry'] += max(0, $r->on_hand - $r->reserved);
            }
        }
        $available = $this->inventory->availability($productId, $warehouse?->id)['total'];
        $openPo = fn ($q) => $q->whereIn('status', ['approved', 'sent', 'confirmed', 'partial'])->when($warehouse, fn ($w) => $w->where('warehouse_id', $warehouse->id));
        $poLines = PoLine::where('product_id', $productId)->whereHas('po', $openPo)->get(['po_id', 'qty', 'received_qty']);
        $incoming = (int) $poLines->sum(fn ($l) => max(0, $l->qty - $l->received_qty));
        $eta = null;
        if ($incoming > 0) {
            $poIds = $poLines->filter(fn ($l) => $l->qty > $l->received_qty)->pluck('po_id')->unique()->all();
            $eta = InboundShipment::whereIn('po_id', $poIds)->whereIn('status', ['expected', 'arrived', 'inspecting'])->whereNotNull('eta')->min('eta')
                ?? PurchaseOrder::whereIn('id', $poIds)->whereNotNull('due_date')->min('due_date');
        }
        $inTransfer = (int) TransferLine::where('product_id', $productId)
            ->whereHas('transfer', fn ($q) => $q->where('status', 'transit')->when($warehouse, fn ($w) => $w->where('to_warehouse_id', $warehouse->id)))
            ->get(['qty', 'received_qty'])->sum(fn ($l) => $l->qty - ($l->received_qty ?? 0));

        return [
            'sku' => $p->sku, 'unit' => 'base', 'onHand' => $sum['onHand'], 'reserved' => $sum['reserved'], 'allocated' => $sum['allocated'],
            'available' => $available, 'quarantine' => $sum['quarantine'], 'damaged' => $sum['damaged'], 'expired' => $sum['expired'],
            'staging' => $sum['staging'], 'nearExpiry' => $sum['nearExpiry'], 'inTransfer' => $inTransfer, 'incoming' => $incoming,
            'incomingEta' => $eta ? Carbon::parse($eta)->toDateString() : null,
            'atp' => $available, 'atpWithIncoming' => $available + $incoming,
        ];
    }
}
