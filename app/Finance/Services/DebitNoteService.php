<?php

namespace App\Finance\Services;

use App\Finance\Models\DebitNote;
use App\Finance\Models\Journal;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReturnDecision;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\Supplier;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supplier debit notes (DN-YYYY-NNNNN): the financial document of goods sent back to a supplier.
 *
 * The stock movement that takes the goods out (`supret` with the supplier as partner) already posts
 * Dr payable / Cr inventory at the cost the stock leaves the books at. The note claims the PURCHASE value, so its one
 * journal posts only the difference: Dr payable (price − cost + VAT) / Cr purchase price variance (price − cost) /
 * Cr VAT input (VAT); a negative difference lands on the other side. Together the supplier's payable goes down by
 * exactly the note total and inventory by the cost.
 *
 * One note per (return, supplier). Issuing is idempotent; cancelling reverses the journal and lets a corrected note be
 * issued again from the return.
 */
class DebitNoteService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountMap $map,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /**
     * Issues the note(s) of a return from its recorded supplier decisions (one `supret` movement each). Runs inside the
     * caller's transaction — the return row is expected to be locked by the caller. A supplier that already has a
     * non-cancelled note on this return is skipped, so a retry issues nothing twice.
     *
     * @return DebitNote[] the active notes of the return (existing + new)
     */
    public function issue(?AuthUser $user, ReturnOrder $r): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DebitNoteService::issue() must run inside the transaction of the return it documents.');
        }
        $decisions = ReturnDecision::where('return_id', $r->id)->where('decision', 'sup')->whereNotNull('movement_id')->orderBy('at')->orderBy('id')->get();
        if ($decisions->isEmpty()) {
            return [];
        }
        $lines = ReturnLine::where('return_id', $r->id)->get()->keyBy('id');
        $movements = InventoryMovement::whereIn('id', $decisions->pluck('movement_id')->all())->get()->keyBy('id');
        $products = Product::whereIn('id', $movements->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $po = $this->poOf($r);
        $notes = [];
        foreach ($decisions->groupBy(fn (ReturnDecision $d) => (string) ($d->supplier_id ?: $r->supplier_id)) as $supplierId => $group) {
            if ($supplierId === '') {
                continue; // left as a stock loss (no supplier to claim from)
            }
            $existing = DebitNote::where('return_id', $r->id)->where('supplier_id', $supplierId)->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            if ($existing) {
                $notes[] = $existing;

                continue;
            }
            $supplier = Supplier::findOrFail($supplierId);
            $rows = [];
            $vatPct = $po ? (float) $po->vat_pct : null;
            foreach ($group as $d) {
                $mv = $movements->get($d->movement_id);
                if (! $mv) {
                    continue;
                }
                $product = $products->get($mv->product_id);
                $line = $d->line_id ? $lines->get($d->line_id) : null;
                $qty = (int) $d->qty;
                $unitCost = (float) ($mv->unit_cost ?? 0);
                $cost = $mv->total_cost !== null ? round((float) $mv->total_cost, 2) : 0.0; // null = the movement posted nothing (zero-cost item)
                $priced = $this->purchasePrice($supplierId, $mv->product_id, $po?->id);
                $price = $line && $line->unit_price !== null ? (float) $line->unit_price : ($priced['price'] ?? $unitCost);
                $vatPct ??= $priced['vatPct'] ?? null;
                $rows[] = [
                    'lineNo' => $line?->line_no, 'sku' => $product?->sku, 'nameAr' => $product?->name_ar, 'nameEn' => $product?->name_en, 'batchNo' => $mv->batch_no,
                    'qty' => $qty, 'price' => round($price, 4), 'total' => round($qty * $price, 2), 'unitCost' => round($unitCost, 4), 'cost' => $cost,
                    'priceSource' => $line && $line->unit_price !== null ? 'return' : (isset($priced['price']) ? 'po' : 'cost'), 'movement' => $mv->number,
                ];
            }
            if (! $rows) {
                continue;
            }
            $vatPct ??= (float) ($this->settings->get('sales.vatPct') ?? 15);
            $subtotal = round(array_sum(array_column($rows, 'total')), 2);
            $costTotal = round(array_sum(array_column($rows, 'cost')), 2);
            $vat = round($subtotal * $vatPct / 100, 2);
            $total = round($subtotal + $vat, 2);
            $note = DebitNote::create([
                'number' => $this->numbering->next('DN'), 'supplier_id' => $supplier->id, 'return_id' => $r->id, 'po_id' => $po?->id, 'grn_id' => $r->grn_id,
                'date' => now(), 'subtotal' => $subtotal, 'vat_pct' => $vatPct, 'vat_amount' => $vat, 'total' => $total, 'cost_total' => $costTotal, 'status' => 'issued',
                'lines' => $rows, 'notes' => "إشعار مدين عن المرتجع {$r->number}".($po ? " — أمر الشراء {$po->number}" : ''),
                'created_by_id' => $user?->id, 'created_by' => $user?->username ?? 'system',
            ]);
            $journal = $this->post($user, $note, $supplier, $r);
            if ($journal) {
                $note->update(['journal_id' => $journal->id]);
            }
            $this->audit->log($user, ['action' => 'DN.ISSUE', 'entityType' => 'DebitNote', 'entityId' => $note->id, 'entityNumber' => $note->number,
                'newValue' => ['supplier' => $supplier->code, 'return' => $r->number, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => $total, 'cost' => $costTotal, 'journal' => $journal?->number]]);
            $this->audit->status($user, 'DebitNote', $note->id, $note->number, null, 'issued');
            $this->notify->activity($user, 'DebitNote', $note->id, $note->number,
                "صدر إشعار مدين {$note->number} على المورد {$supplier->name_ar} بقيمة ".number_format($total, 2)." ر.س عن المرتجع {$r->number}",
                "Debit note {$note->number} issued to {$supplier->name_en} for return {$r->number}", ['finance', 'proc']);
            $notes[] = $note->refresh();
        }
        if ($notes && ! in_array($r->debit_note_id, array_map(fn (DebitNote $n) => $n->id, $notes), true)) {
            $r->forceFill(['debit_note_id' => $notes[0]->id])->save();
        }

        return $notes;
    }

    /** Cancels a note: its journal is reversed (the payable goes back up by price difference + VAT); the goods stay returned. */
    public function cancel(AuthUser $user, string $idOrNumber, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $note = DebitNote::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('DN_NOT_FOUND', "إشعار المدين {$idOrNumber} غير موجود", 'Debit note not found');
            if ($note->status === 'cancelled') {
                throw AppError::rule('DN_STATE', "إشعار المدين {$note->number} ملغى مسبقًا", 'Debit note is already cancelled');
            }
            $reversal = $note->journal_id ? $this->ledger->reverse($user, $note->journal_id, "إلغاء {$note->number}: {$reason}") : null;
            $note->update(['status' => 'cancelled', 'cancelled_at' => now(), 'notes' => trim(($note->notes ? $note->notes."\n" : '')."إلغاء ({$user->username}): {$reason}")]);
            $this->audit->log($user, ['action' => 'DN.CANCEL', 'entityType' => 'DebitNote', 'entityId' => $note->id, 'entityNumber' => $note->number,
                'oldValue' => 'issued', 'newValue' => ['status' => 'cancelled', 'reason' => $reason, 'reversal' => $reversal?->number]]);
            $this->audit->status($user, 'DebitNote', $note->id, $note->number, 'issued', 'cancelled', $reason);
            $rtn = $note->return_id ? ReturnOrder::whereKey($note->return_id)->value('number') : null;
            $this->notify->activity($user, 'DebitNote', $note->id, $note->number,
                "أُلغي إشعار المدين {$note->number}".($rtn ? " (المرتجع {$rtn})" : '')." — {$reason}. البضاعة بقيت مرتجعة؛ أصدر إشعارًا مصححًا من صفحة المرتجع.",
                "Debit note {$note->number} cancelled — {$reason}", ['finance', 'proc']);

            return $this->get($note->id);
        });
    }

    public function get(string $idOrNumber): array
    {
        $note = DebitNote::with(['supplier:id,code,name_ar,name_en,vat', 'return:id,number,type,status,warehouse_id', 'journal:id,number,status'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('DN_NOT_FOUND', "إشعار المدين {$idOrNumber} غير موجود", 'Debit note not found');

        return $this->present($note) + [
            'po' => $note->po_id ? PurchaseOrder::whereKey($note->po_id)->first(['id', 'number', 'status'])?->toArray() : null,
            'grn' => $note->grn_id ? GoodsReceipt::whereKey($note->grn_id)->first(['id', 'number'])?->toArray() : null,
            'journals' => Journal::where('source_type', 'DebitNote')->where('source_id', $note->id)->orderBy('created_at')->orderBy('number')
                ->get(['id', 'number', 'journal_date', 'status', 'kind', 'memo', 'total_debit', 'total_credit'])->map->toArray()->all(),
        ];
    }

    /** @param  array{status?:?string, supplier?:?string, return?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = DebitNote::with(['supplier:id,code,name_ar,name_en', 'return:id,number,type,status', 'journal:id,number,status']);
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['supplier'])) {
            $q->whereHas('supplier', fn ($s) => $s->where('code', $f['supplier'])->orWhere('id', $f['supplier']));
        }
        if (! empty($f['return'])) {
            $q->whereHas('return', fn ($s) => $s->where('number', $f['return'])->orWhere('id', $f['return']));
        }
        if (! empty($f['from'])) {
            $q->whereDate('date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)
                ->orWhereHas('supplier', fn ($s) => $s->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhereHas('return', fn ($s) => $s->where('number', 'like', $like)));
        }
        $column = match ($page->sort) {
            'total' => 'total', 'date' => 'date', default => 'created_at'
        };

        return $page->paginate($q->orderBy($column, $page->order)->orderBy('id', $page->order), fn (DebitNote $n) => $this->present($n));
    }

    /** The compact figures a return shows for its notes (no finance permission needed to see that a note exists). */
    public function ofReturn(string $returnId): array
    {
        return DebitNote::with('supplier:id,code,name_ar,name_en')->where('return_id', $returnId)->orderBy('created_at')->orderBy('number')->get()
            ->map(fn (DebitNote $n) => [
                'id' => $n->id, 'number' => $n->number, 'status' => $n->status, 'date' => $n->date?->toJSON(), 'subtotal' => $n->subtotal, 'vatPct' => $n->vat_pct, 'vatAmount' => $n->vat_amount,
                'total' => $n->total, 'costTotal' => $n->cost_total, 'supplier' => $n->supplier ? ['code' => $n->supplier->code, 'nameAr' => $n->supplier->name_ar, 'nameEn' => $n->supplier->name_en] : null,
                'lines' => $n->lines ?? [],
            ])->all();
    }

    /**
     * What goods sent back to suppliers took off the payables, per supplier — the returns side of the payables
     * sub-ledger (aging report, sub-ledger ↔ general-ledger reconciliation):
     *  - `notes`     the standing (issued) debit notes at their total: stock movement + note journal lower the payable
     *                by exactly that amount;
     *  - `unclaimed` goods that left to a supplier and have no standing note — its note was cancelled and not issued
     *                again yet, or they were returned before debit notes existed. The stock movement alone debited the
     *                payable, at the cost the stock left at.
     *
     * @return array<string, array{notes: float, unclaimed: float, count: int}> keyed by supplier id
     */
    public function supplierCredits(): array
    {
        $out = $covered = [];
        $blank = ['notes' => 0.0, 'unclaimed' => 0.0, 'count' => 0];
        foreach (DebitNote::where('status', 'issued')->get(['supplier_id', 'return_id', 'total']) as $n) {
            $out[$n->supplier_id] ??= $blank;
            $out[$n->supplier_id]['notes'] = round($out[$n->supplier_id]['notes'] + (float) $n->total, 2);
            $out[$n->supplier_id]['count']++;
            $covered["{$n->return_id}|{$n->supplier_id}"] = true;
        }
        // every return-to-supplier decision whose movement reached the ledger, grouped the way notes are issued: per return and supplier
        $sent = DB::table('return_decisions as d')->join('returns as r', 'r.id', '=', 'd.return_id')->join('inventory_movements as m', 'm.id', '=', 'd.movement_id')
            ->join('fin_journals as j', 'j.id', '=', 'm.journal_id')->where('d.decision', 'sup')->where('j.status', 'posted')
            ->groupBy('d.return_id', 'd.supplier_id', 'r.supplier_id')
            ->selectRaw('d.return_id, d.supplier_id as decided_supplier_id, r.supplier_id as return_supplier_id, coalesce(sum(m.total_cost), 0) as cost')->get();
        foreach ($sent as $row) {
            $supplierId = $row->decided_supplier_id ?: $row->return_supplier_id;
            if (! $supplierId || isset($covered["{$row->return_id}|{$supplierId}"])) {
                continue; // no supplier: the movement posted a stock loss, not a payable
            }
            $out[$supplierId] ??= $blank;
            $out[$supplierId]['unclaimed'] = round($out[$supplierId]['unclaimed'] + (float) $row->cost, 2);
        }

        return $out;
    }

    /**
     * The purchase price a supplier is claimed for a product: the given purchase order's line, else the last order of
     * that supplier that priced the product. Null when the supplier never sold it to us (the caller falls back to cost).
     *
     * @return array{price: float, vatPct: float, poId: string, poNumber: string}|null
     */
    public function purchasePrice(string $supplierId, string $productId, ?string $poId = null): ?array
    {
        $base = fn () => PoLine::query()->join('purchase_orders as po', 'po.id', '=', 'po_lines.po_id')->where('po_lines.product_id', $productId)
            ->select(['po_lines.price', 'po.id as po_id', 'po.number as po_number', 'po.vat_pct']);
        $row = $poId ? $base()->where('po.id', $poId)->first() : null;
        $row ??= $base()->where('po.supplier_id', $supplierId)->whereNotIn('po.status', ['draft', 'pending', 'cancelled'])
            ->orderByDesc('po.created_at')->orderByDesc('po.id')->first();

        return $row ? ['price' => (float) $row->price, 'vatPct' => (float) $row->vat_pct, 'poId' => $row->po_id, 'poNumber' => $row->po_number] : null;
    }

    // ───────────── internals ─────────────

    private function poOf(ReturnOrder $r): ?PurchaseOrder
    {
        if ($r->po_id) {
            return PurchaseOrder::find($r->po_id);
        }
        $poId = $r->grn_id ? GoodsReceipt::whereKey($r->grn_id)->value('po_id') : null;

        return $poId ? PurchaseOrder::find($poId) : null;
    }

    /** The one journal of a note: what the stock movement did not already post. Null when there is nothing to post. */
    private function post(?AuthUser $user, DebitNote $note, Supplier $supplier, ReturnOrder $r): ?Journal
    {
        $diff = round((float) $note->subtotal - (float) $note->cost_total, 2);  // purchase value above (+) / below (−) the cost the stock left at
        $vat = round((float) $note->vat_amount, 2);
        $payable = round($diff + $vat, 2);
        if (abs($diff) < 0.01 && abs($vat) < 0.01) {
            return null;
        }
        $dims = ['referenceType' => 'DebitNote', 'referenceId' => $note->id, 'warehouseId' => $r->warehouse_id, 'costCenterId' => $this->map->forWarehouse($r->warehouse_id)?->id];
        // a positive amount goes to the line's natural side for a return (payable ↓ = debit, variance / VAT ↓ = credit); a negative one swaps sides
        $side = fn (float $amount, string $natural) => [($amount >= 0 ? $natural : ($natural === 'debit' ? 'credit' : 'debit')) => abs($amount)];

        return $this->ledger->post($user, [
            'eventKey' => "dn:{$note->id}", 'date' => $note->date, 'source' => ['type' => 'DebitNote', 'id' => $note->id, 'number' => $note->number],
            'memo' => "إشعار مدين {$note->number} — {$supplier->name_ar} — مرتجع {$r->number}",
            'lines' => [
                ['role' => 'ap', 'description' => "{$note->number} · {$supplier->name_ar} · فرق القيمة الشرائية والضريبة", 'partnerType' => 'supplier', 'partnerId' => $supplier->id] + $side($payable, 'debit') + $dims,
                ['role' => 'ppv', 'description' => 'فرق سعر الشراء عن تكلفة المخزون المرتجع'] + $side($diff, 'credit') + $dims,
                ['role' => 'vat_input', 'description' => "عكس ضريبة مدخلات {$note->vat_pct}% · {$note->number}"] + $side($vat, 'credit') + $dims,
            ],
        ]);
    }

    private function present(DebitNote $n): array
    {
        $row = $n->toArray();
        $row['supplier'] = $n->supplier ? ['code' => $n->supplier->code, 'nameAr' => $n->supplier->name_ar, 'nameEn' => $n->supplier->name_en, 'vatNo' => $n->supplier->vat ?? null] : null;
        $row['return'] = $n->return ? ['number' => $n->return->number, 'type' => $n->return->type, 'status' => $n->return->status] : null;
        $row['journal'] = $n->journal ? ['id' => $n->journal->id, 'number' => $n->journal->number, 'status' => $n->journal->status] : null;

        return $row;
    }
}
