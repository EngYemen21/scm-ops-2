<?php

namespace App\Services\Procurement;

use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Services\Procurement\ProcurementHelpers as H;
use App\Support\AppError;
use App\Support\AuthUser;
use Throwable;

/** Replenishment suggestions (suggest only — never auto-creates a PO), procurement dashboard, supplier performance. */
class ProcurementService
{
    public const CONSUMPTION_WINDOW_DAYS = 28;

    public const COVER_DAYS = 8;

    public const DEFAULT_LEAD_DAYS = 5;

    public function __construct(
        private readonly PrService $pr,
        private readonly RfqService $rfq,
        private readonly PoService $po,
    ) {}

    /**
     * suggested = max(0, ADC×lead + safety + ADC×COVER_DAYS − available). ADC = pick movements of the last 28 days / 28;
     * lead = preferred supplier lead days (or 5); safety = product.reorder_min; urgent when available = 0.
     *
     * @param  array{warehouse?:?string, urgent?:?bool, sku?:?string, limit?:?int}  $q
     */
    public function suggestions(array $q = []): array
    {
        $wh = ! empty($q['warehouse']) ? H::findWarehouse($q['warehouse']) : null;
        $since = now()->subDays(self::CONSUMPTION_WINDOW_DAYS);

        $products = Product::with(['suppliers.supplier', 'homeWarehouse:id,code'])->where('active', true)
            ->when(! empty($q['sku']), fn ($w) => $w->where('sku', $q['sku']))->orderBy('sku')->get();
        $bal = InventoryBalance::query()->where('quarantine', false)->where('blocked', false)->when($wh, fn ($w) => $w->where('warehouse_id', $wh->id))
            ->groupBy('product_id')->selectRaw('product_id, COALESCE(SUM(on_hand), 0) AS on_hand, COALESCE(SUM(reserved), 0) AS reserved')->toBase()->get()->keyBy('product_id');
        $pick = InventoryMovement::query()->where('type', 'pick')->where('created_at', '>=', $since)->when($wh, fn ($w) => $w->where('src_warehouse_id', $wh->id))
            ->groupBy('product_id')->selectRaw('product_id, COALESCE(SUM(qty), 0) AS qty')->toBase()->get()->keyBy('product_id');
        $byName = [];
        foreach (Supplier::where('active', true)->get() as $s) {
            $byName[$s->name_ar] = $s;
            $byName[$s->name_en] = $s;
        }
        $incoming = [];
        $openLines = PoLine::query()->whereHas('po', fn ($p) => $p->whereIn('status', ['approved', 'sent', 'confirmed', 'partial'])->when($wh, fn ($w) => $w->where('warehouse_id', $wh->id)))
            ->get(['product_id', 'qty', 'received_qty']);
        foreach ($openLines as $l) {
            $incoming[$l->product_id] = ($incoming[$l->product_id] ?? 0) + max(0, $l->qty - $l->received_qty);
        }

        $rows = [];
        foreach ($products as $p) {
            $onHand = (int) ($bal[$p->id]->on_hand ?? 0);
            $reserved = (int) ($bal[$p->id]->reserved ?? 0);
            $avail = max(0, $onHand - $reserved);
            $adc = H::round2(((int) ($pick[$p->id]->qty ?? 0)) / self::CONSUMPTION_WINDOW_DAYS);
            $pref = H::preferredSupplierOf($p, $byName);
            $lead = $pref['leadDays'] ?? self::DEFAULT_LEAD_DAYS;
            $safety = (int) $p->reorder_min;
            $sug = max(0, (int) ceil($adc * $lead + $safety + $adc * self::COVER_DAYS - $avail));
            if ($sug <= 0) {
                continue;
            }
            $inc = $incoming[$p->id] ?? 0;
            $adcText = PoService::num($adc);
            $why = [];
            $whyE = [];
            if ($avail === 0) {
                $why[] = 'نافد تمامًا'.($reserved > 0 ? ' وطلبات مفتوحة تنتظر' : '');
                $whyE[] = 'Out of stock'.($reserved > 0 ? '; open orders waiting' : '');
            } elseif ($avail < $p->reorder_min) {
                $why[] = "المتاح {$avail} أقل من حد الطلب {$p->reorder_min}";
                $whyE[] = "Available {$avail} below reorder {$p->reorder_min}";
            }
            $why[] = "مستهلك يومي {$adcText} × مهلة توريد {$lead} أيام + مخزون أمان {$safety} + تغطية ".self::COVER_DAYS." أيام − متاح {$avail}";
            $whyE[] = "ADC {$adcText} × lead {$lead} + safety {$safety} + ".self::COVER_DAYS."-day cover − available {$avail}";
            if ($p->storage_class !== 'ambient' && $p->shelf_life_days && $p->shelf_life_days <= 21) {
                $why[] = ($p->storage_class === 'chilled' ? 'مبرد' : 'مجمد')." قصير العمر ({$p->shelf_life_days} يومًا)، طلب صغير متكرر أفضل من مخزون كبير";
                $whyE[] = "Short-life {$p->storage_class} ({$p->shelf_life_days} days) — frequent small orders";
            }
            if ($inc > 0) {
                $why[] = "قيد التوريد {$inc} ضمن أوامر شراء مفتوحة";
                $whyE[] = "{$inc} incoming on open POs";
            }
            if (! $pref) {
                $why[] = 'لا يوجد مورد مفضل — يُنصح بطلب عروض أسعار';
                $whyE[] = 'No preferred supplier — RFQ recommended';
            }
            $sup = $pref['supplier'] ?? null;
            $rows[] = [
                'sku' => $p->sku, 'productId' => $p->id, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'storageClass' => $p->storage_class, 'warehouse' => $wh?->code ?: ($p->homeWarehouse?->code ?: null),
                'avail' => $avail, 'reserved' => $reserved, 'adc' => $adc, 'lead' => $lead, 'safety' => $safety, 'incoming' => $inc, 'sug' => $sug, 'urgent' => $avail === 0,
                'supplier' => $sup ? ['code' => $sup->code, 'nameAr' => $sup->name_ar, 'nameEn' => $sup->name_en, 'score' => $sup->score] : null,
                'price' => $pref['price'] ?? ($p->purchase_price !== null ? (float) $p->purchase_price : null), 'why' => $why, 'whyE' => $whyE,
            ];
        }
        $urgentCount = count(array_filter($rows, fn ($r) => $r['urgent']));
        $filtered = ! empty($q['urgent']) ? array_values(array_filter($rows, fn ($r) => $r['urgent'])) : $rows;
        usort($filtered, fn ($a, $b) => ((int) $b['urgent'] - (int) $a['urgent']) ?: ($b['sug'] - $a['sug']));
        $limit = (int) (($q['limit'] ?? null) ?: 200);

        return [
            'items' => array_slice($filtered, 0, $limit), 'total' => count($filtered), 'urgent' => $urgentCount,
            'params' => ['windowDays' => self::CONSUMPTION_WINDOW_DAYS, 'coverDays' => self::COVER_DAYS, 'defaultLeadDays' => self::DEFAULT_LEAD_DAYS, 'warehouse' => $wh?->code],
            'generatedAt' => now()->toJSON(),
        ];
    }

    private function suggestionFor(string $sku, ?string $warehouse = null): array
    {
        return $this->suggestions(['sku' => $sku, 'warehouse' => $warehouse])['items'][0]
            ?? throw AppError::rule('NO_SUGGESTION', "لا يوجد اقتراح تزويد حالي للمنتج {$sku}", "No replenishment suggestion for {$sku}");
    }

    private function needWarehouse(array $s, ?string $code = null): string
    {
        return ($code ?: $s['warehouse']) ?: throw AppError::validation('WAREHOUSE_REQUIRED', 'حدد المستودع', 'Warehouse is required');
    }

    /** Suggestion → submitted PR. */
    public function toPr(AuthUser $user, string $sku, array $dto)
    {
        $s = $this->suggestionFor($sku, $dto['warehouseCode'] ?? null);

        return $this->pr->create($user, [
            'warehouseCode' => $this->needWarehouse($s, $dto['warehouseCode'] ?? null), 'needDate' => ($dto['needDate'] ?? null) ?: H::isoDatePlus((int) $s['lead']),
            'priority' => ($dto['priority'] ?? null) ?: ($s['urgent'] ? 'urgent' : 'normal'), 'justification' => ($dto['justification'] ?? null) ?: implode(' · ', $s['why']),
            'costCenter' => ($dto['costCenter'] ?? null) ?: 'ops', 'lines' => [['sku' => $s['sku'], 'qty' => (int) (($dto['qty'] ?? null) ?: $s['sug']), 'price' => $s['price']]],
        ], ['submit' => true, 'source' => 'suggestion']);
    }

    /** Suggestion → RFQ (invites suppliers by rule; default = category suppliers). */
    public function toRfq(AuthUser $user, string $sku, array $dto)
    {
        $s = $this->suggestionFor($sku, $dto['deliveryWarehouseCode'] ?? null);

        return $this->rfq->create($user, [
            'lines' => [['sku' => $s['sku'], 'qty' => (int) (($dto['qty'] ?? null) ?: $s['sug'])]], 'invitedRule' => ($dto['invitedRule'] ?? null) ?: 'cat', 'supplierCodes' => $dto['supplierCodes'] ?? null,
            'closeDate' => ($dto['closeDate'] ?? null) ?: H::isoDatePlus(3), 'terms' => $dto['terms'] ?? null, 'deliveryWarehouseCode' => ($dto['deliveryWarehouseCode'] ?? null) ?: ($s['warehouse'] ?: null),
            'notes' => ($dto['notes'] ?? null) ?: implode(' · ', $s['why']),
        ], ['source' => 'suggestion']);
    }

    /** Suggestion → PO draft (status draft; `POST /po/:id/submit` starts the approval chain). */
    public function toPo(AuthUser $user, string $sku, array $dto)
    {
        $s = $this->suggestionFor($sku, $dto['warehouseCode'] ?? null);
        $supplierCode = ($dto['supplierCode'] ?? null) ?: ($s['supplier']['code'] ?? null);
        if (! $supplierCode) {
            throw AppError::validation('SUPPLIER_REQUIRED', "لا يوجد مورد مفضل لـ {$s['nameAr']} — حدد المورد", 'Supplier is required (no preferred supplier)');
        }
        $price = isset($dto['price']) ? (float) $dto['price'] : $s['price'];
        if (! $price || $price <= 0) {
            throw AppError::validation('PRICE_REQUIRED', "حدد سعر الوحدة لـ {$s['nameAr']}", 'Unit price is required');
        }

        return $this->po->create($user, [
            'supplierCode' => $supplierCode, 'warehouseCode' => $this->needWarehouse($s, $dto['warehouseCode'] ?? null), 'dueDate' => ($dto['dueDate'] ?? null) ?: H::isoDatePlus((int) $s['lead']),
            'paymentTerms' => $dto['paymentTerms'] ?? null, 'reference' => 'اقتراح تزويد', 'notes' => implode(' · ', $s['why']), 'lines' => [['sku' => $s['sku'], 'qty' => (int) (($dto['qty'] ?? null) ?: $s['sug']), 'price' => $price]],
            'overrideSupplierScore' => $dto['overrideSupplierScore'] ?? null, 'overrideReason' => $dto['overrideReason'] ?? null,
        ], ['draft' => true, 'source' => 'suggestion']);
    }

    /** Counters for the procurement page header. */
    public function dashboard(): array
    {
        $weekStart = H::dayStart();
        $weekEnd = $weekStart->copy()->addDays(7);
        $pendingPos = PurchaseOrder::where('status', 'pending')->with(['approvals' => fn ($q) => $q->where('decision', 'pending')->orderBy('step')])->get(['id', 'number', 'total']);
        $expected = InboundShipment::where('status', 'expected')->where('eta', '>=', $weekStart)->where('eta', '<', $weekEnd)
            ->with(['po:id,number', 'supplier:id,code,name_ar,name_en', 'warehouse:id,code'])->orderBy('eta')->orderBy('id')->get();
        try {
            $urgent = $this->suggestions(['urgent' => true, 'limit' => 1])['total'];
        } catch (Throwable) {
            $urgent = 0;
        }
        $bySteps = [];
        foreach ($pendingPos as $po) {
            $st = $po->approvals->first();
            if (! $st) {
                continue;
            }
            $bySteps[$st->role_key] ??= ['roleKey' => $st->role_key, 'labelAr' => $st->label_ar, 'labelEn' => $st->label_en, 'count' => 0, 'total' => 0];
            $bySteps[$st->role_key]['count']++;
            $bySteps[$st->role_key]['total'] = H::round2($bySteps[$st->role_key]['total'] + (float) $po->total);
        }

        return [
            'pendingPrApprovals' => PurchaseRequisition::whereIn('status', ['submitted', 'review'])->count(),
            'openRfqs' => Rfq::whereIn('status', ['open', 'quoted', 'compared'])->count(),
            'poPendingApproval' => ['count' => $pendingPos->count(), 'total' => H::round2($pendingPos->sum(fn ($p) => (float) $p->total)), 'bySteps' => array_values($bySteps)],
            'expectedInboundsThisWeek' => ['count' => $expected->count(), 'from' => $weekStart->toJSON(), 'to' => $weekEnd->toJSON(), 'items' => $expected->map(fn ($s) => [
                'id' => $s->id, 'number' => $s->number, 'eta' => $s->eta?->toJSON(), 'po' => $s->po ? ['number' => $s->po->number] : null,
                'supplier' => $s->supplier ? ['code' => $s->supplier->code, 'nameAr' => $s->supplier->name_ar, 'nameEn' => $s->supplier->name_en] : null,
                'warehouse' => $s->warehouse ? ['code' => $s->warehouse->code] : null,
            ])->all()],
            'posSentAwaitingConfirmation' => PurchaseOrder::where('status', 'sent')->count(),
            'urgentSuggestions' => $urgent,
            'generatedAt' => now()->toJSON(),
        ];
    }

    /** Supplier OTIF / fill rate / lead days computed from GRNs vs PO due dates; stored fields are the fallback when there is no history. */
    public function supplierPerformance(string $code): array
    {
        $supplier = H::findSupplier($code, false);
        $pos = PurchaseOrder::where('supplier_id', $supplier->id)->whereNotIn('status', ['draft', 'cancelled'])
            ->with(['lines:id,po_id,qty', 'grns' => fn ($q) => $q->select(['id', 'po_id', 'posted_at'])->orderBy('posted_at')->orderBy('id'), 'grns.lines:id,grn_id,accepted_qty'])->get();
        $delivered = $pos->filter(fn ($p) => $p->grns->isNotEmpty());
        $onTimeInFull = 0;
        $ordered = 0;
        $accepted = 0;
        $leadSum = 0.0;
        $leadN = 0;
        foreach ($delivered as $po) {
            $ord = (int) $po->lines->sum('qty');
            $acc = (int) $po->grns->sum(fn ($g) => $g->lines->sum('accepted_qty'));
            $ordered += $ord;
            $accepted += $acc;
            $first = $po->grns->first()->posted_at;
            $last = $po->grns->last()->posted_at;
            $onTime = ! $po->due_date || $last->lte(H::dayStart($po->due_date)->addDay());
            if ($onTime && $acc >= $ord) {
                $onTimeInFull++;
            }
            $start = $po->sent_at ?: ($po->approved_at ?: $po->created_at);
            $leadSum += ($first->getTimestampMs() - $start->getTimestampMs()) / 86400000;
            $leadN++;
        }
        $computed = $delivered->count() > 0;

        return [
            'supplier' => ['id' => $supplier->id, 'code' => $supplier->code, 'nameAr' => $supplier->name_ar, 'nameEn' => $supplier->name_en, 'category' => $supplier->category, 'categoryEn' => $supplier->category_en,
                'score' => $supplier->score, 'isNew' => $supplier->is_new, 'active' => $supplier->active],
            'otif' => $computed ? (int) round(($onTimeInFull / $delivered->count()) * 100) : $supplier->otif,
            'fillRate' => $computed && $ordered > 0 ? (int) round(($accepted / $ordered) * 100) : $supplier->fill_rate,
            'leadDays' => $leadN ? H::round2($leadSum / $leadN) : $supplier->lead_days,
            'score' => $supplier->score,
            'ordersCount' => $pos->count() ?: $supplier->orders_count,
            'totalValue' => $pos->count() ? H::round2($pos->sum(fn ($p) => (float) $p->total)) : (float) $supplier->total_value,
            'openPos' => $pos->filter(fn ($p) => in_array($p->status, ['pending', 'approved', 'sent', 'confirmed', 'partial'], true))->count(),
            'basis' => ['source' => $computed ? 'computed' : 'stored', 'ordersWithGrn' => $delivered->count(), 'onTimeInFull' => $onTimeInFull, 'orderedQty' => $ordered, 'acceptedQty' => $accepted],
            'stored' => ['otif' => $supplier->otif, 'fillRate' => $supplier->fill_rate, 'leadDays' => $supplier->lead_days, 'ordersCount' => $supplier->orders_count, 'totalValue' => (float) $supplier->total_value],
        ];
    }
}
