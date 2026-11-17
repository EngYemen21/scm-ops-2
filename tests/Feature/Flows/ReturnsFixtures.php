<?php

namespace Tests\Feature\Flows;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\JournalLine;
use App\Finance\Services\CustomerInvoiceService;
use App\Models\Batch;
use App\Models\FulfillmentOrder;
use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\InboundShipment;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProofOfDelivery;
use App\Models\PurchaseOrder;
use App\Models\ReturnOrder;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use App\Services\Returns\ReturnsService;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Documents the returns tests start from, written as the modules that own them would leave them: valued stock, a
 * supplier's goods receipt at a purchase price, an invoiced delivery, a return the delivery flow created. Used together
 * with FlowFixtures.
 */
trait ReturnsFixtures
{
    /** A demo user as the caller of a service called directly (the delivery flow calls ReturnsService that way). */
    protected function actor(string $username = 'wm'): AuthUser
    {
        $u = User::where('username', $username)->firstOrFail();

        return new AuthUser($u->id, $u->username, $u->name_ar, $u->name_en, [], [], [], null, 'test-request');
    }

    /** Balance (debit − credit) of the account mapped to a posting role, optionally of one source document. */
    protected function gl(string $role, ?string $sourceNumber = null): float
    {
        $q = JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', '!=', 'draft')->where('fin_accounts.role', $role);
        if ($sourceNumber) {
            $q->where('fin_journals.source_number', $sourceNumber);
        }

        return round((float) $q->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    /** What the payable control account holds for one supplier, as debit − credit (a return to the supplier makes it go up). */
    protected function payableDebit(Supplier $supplier): float
    {
        return round((float) JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', '!=', 'draft')->where('fin_accounts.role', 'ap')->where('fin_journal_lines.partner_type', 'supplier')->where('fin_journal_lines.partner_id', $supplier->id)
            ->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    protected function newSupplier(string $prefix = 'SUP-T'): Supplier
    {
        $code = self::code($prefix);

        return Supplier::create(['code' => $code, 'name_ar' => "مورد اختبار {$code}", 'name_en' => "Test supplier {$code}"])->refresh();
    }

    /** Stock with a cost: an opening balance at `$unitCost` (Dr inventory / Cr opening equity), so later movements are valued. */
    protected function stockAt(Product $product, string $binCode, int $qty, float $unitCost, ?Batch $batch = null): void
    {
        $wh = $this->ryd();
        $bin = $this->inv()->bin($wh->id, $binCode);
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'opening', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty, 'unitCost' => $unitCost,
            'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'Test', 'number' => 'T-OPEN']]));
    }

    /**
     * A supplier's goods receipt already put away: purchase order (price, VAT), GRN line (batch) and the stock in
     * `$binCode`, valued at the purchase price exactly as receiving posts it (Dr inventory / Cr GRNI).
     *
     * @return array{0: PurchaseOrder, 1: GoodsReceipt, 2: ?Batch}
     */
    protected function receipt(Supplier $supplier, Product $product, int $qty, float $price, string $binCode, ?string $batchNo = null, float $vatPct = 15, int $daysAgo = 0): array
    {
        $wh = $this->ryd();
        $at = now()->subDays($daysAgo);
        $po = PurchaseOrder::create(['number' => self::code('PO-R'), 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'status' => 'received', 'total' => $qty * $price,
            'vat_pct' => $vatPct, 'open_qty' => 0, 'sent_at' => $at, 'created_at' => $at]);
        $poLine = PoLine::create(['po_id' => $po->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => $qty, 'price' => $price, 'received_qty' => $qty]);
        $shipment = InboundShipment::create(['number' => self::code('SHP-R'), 'po_id' => $po->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'status' => 'done']);
        $batch = $batchNo ? Batch::firstOrCreate(['product_id' => $product->id, 'batch_no' => $batchNo], ['expiry_date' => now()->addYear(), 'supplier_id' => $supplier->id]) : null;
        $grn = GoodsReceipt::create(['number' => self::code('GRN-R'), 'shipment_id' => $shipment->id, 'po_id' => $po->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id,
            'posted_by' => 'test', 'posted_at' => $at]);
        GrnLine::create(['grn_id' => $grn->id, 'line_no' => 1, 'po_line_id' => $poLine->id, 'product_id' => $product->id, 'received_qty' => $qty, 'accepted_qty' => $qty,
            'batch_no' => $batchNo, 'batch_id' => $batch?->id, 'expiry_date' => $batch?->expiry_date]);
        $bin = $this->inv()->bin($wh->id, $binCode);
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'grn', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty, 'unitCost' => $price,
            'to' => ['warehouseId' => $wh->id, 'binId' => $bin->id], 'reference' => ['type' => 'GoodsReceipt', 'id' => $grn->id, 'number' => $grn->number]]));

        return [$po->refresh(), $grn->refresh(), $batch];
    }

    /**
     * A delivered and invoiced order: `$qty` units loaded at `$cost` (goods in transit), a proof of delivery and the
     * customer invoice the delivery flow issues (price 10 per unit, VAT 15 %, cost of sales out of goods in transit).
     *
     * @return array{0: FulfillmentOrder, 1: CustomerInvoice, 2: Trip}
     */
    protected function invoicedDelivery(Product $product, int $qty, float $cost = 6.0, ?Batch $batch = null): array
    {
        $wh = $this->ryd();
        $this->stockAt($product, 'A-07-1-B1', $qty, $cost, $batch);
        [$trip, $stops, $fos] = $this->dispatchedTrip($this->demoDriver(), [[[$product, $qty]]]);
        $fo = $fos[0]->load(['lines.soLine', 'lines.product']);
        $invoice = DB::transaction(function () use ($wh, $product, $qty, $batch, $trip, $stops, $fo) {
            $this->inv()->post(null, null, ['type' => 'load', 'productId' => $product->id, 'batchId' => $batch?->id, 'qty' => $qty,
                'from' => ['warehouseId' => $wh->id, 'binId' => $this->inv()->bin($wh->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number]]);
            $pod = ProofOfDelivery::create(['number' => self::code('POD-T'), 'trip_id' => $trip->id, 'stop_id' => $stops[0]->id, 'fo_id' => $fo->id, 'customer_id' => $fo->customer_id,
                'result' => 'delivered', 'delivered_qty' => $qty, 'returned_qty' => 0]);

            return $this->app->make(CustomerInvoiceService::class)->issueFromDelivery($this->actor('driver'), $fo, $pod, $fo->lines->mapWithKeys(fn ($l) => [$l->id => $l->qty])->all());
        });
        $fo->update(['status' => 'delivered']);
        $trip->update(['status' => 'closed', 'closed_at' => now()]);

        return [$fo->refresh(), $invoice, $trip->refresh()];
    }

    /**
     * The return a partial / failed delivery creates: already approved, linked to its order and trip (goods on the truck).
     *
     * @param  array<int, array{sku:string, qty:int, batchNo?:string}>  $lines
     */
    protected function deliveryReturn(FulfillmentOrder $fo, Trip $trip, array $lines, string $type = 'del', string $reason = 'del_fail'): ReturnOrder
    {
        return $this->app->make(ReturnsService::class)->create($this->actor('driver'), ['type' => $type, 'source' => 'عميل التوصيل', 'reference' => $fo->number, 'foNumber' => $fo->number,
            'tripNumber' => $trip->number, 'warehouseCode' => 'RYD', 'reasonCode' => $reason, 'lines' => $lines], 'approved');
    }

    /** A return taken through approve → receive → inspect over the API; returns its number. */
    protected function returnInInspection(Product $product, int $qty, array $line = [], string $type = 'cust', string $reason = 'wrong_product', array $extra = []): string
    {
        $r = $this->expectOk($this->postAs('sales', '/api/returns', $extra + ['type' => $type, 'source' => 'اختبار', 'warehouseCode' => 'RYD', 'reasonCode' => $reason,
            'lines' => [$line + ['sku' => $product->sku, 'qty' => $qty]]]));
        foreach (['approve', 'receive'] as $step) {
            $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/{$step}"));
        }
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/inspect", ['findings' => 'سليم']));

        return $r['number'];
    }
}
