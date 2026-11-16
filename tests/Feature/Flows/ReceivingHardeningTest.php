<?php

namespace Tests\Feature\Flows;

use App\Finance\Models\JournalLine;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PutawayTask;
use App\Models\ReturnOrder;
use App\Models\ShipmentLine;
use App\Models\StagingEntry;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Operational hardening 2026-11, workstream W7 (docs/ops-hardening/SPEC.md): rejected goods stay open on the purchase
 * order and an order can be closed short · a delivery can be refused at the gate and an arrival undone · a receiving
 * line can be split over several batches · a putaway task can be split over several bins and clears only its own
 * staging entry · a receipt that is still untouched can be voided, with the stock, the supplier accrual, the purchase
 * order and the shipment back to where they were.
 */
class ReceivingHardeningTest extends ApiTestCase
{
    use FlowFixtures;

    /** arrive → inspect → GRN; returns the GRN body. */
    private function receive(string $number, array $lines, string $as = 'wm'): array
    {
        $url = "/api/inbound/shipments/{$number}";
        $this->expectOk($this->postAs($as, "{$url}/arrive", ['carrier' => 'RH-TEST']));
        $this->expectOk($this->postAs($as, "{$url}/inspect"));

        return $this->expectOk($this->postAs($as, "{$url}/grn", ['lines' => $lines]));
    }

    /** Balance (debit − credit) of an account role over the journals of one source document. */
    private function gl(string $role, string $sourceNumber): float
    {
        return round((float) JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', '!=', 'draft')->where('fin_accounts.role', $role)->where('fin_journals.source_number', $sourceNumber)
            ->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    /** Codes of free ambient storage bins in RYD. */
    private function ambientBins(int $n): array
    {
        $wh = $this->ryd();

        return Bin::where('warehouse_id', $wh->id)->where('status', 'active')->where('type', '!=', 'virtual')->whereNull('fixed_product_id')
            ->whereHas('zone', fn ($z) => $z->where('type', 'ambient'))
            ->whereNotIn('id', InventoryBalance::select('bin_id')->where('warehouse_id', $wh->id)->where('on_hand', '>', 0))
            ->orderBy('code')->limit($n)->pluck('code')->all();
    }

    private function poFigures(PurchaseOrder $po): array
    {
        return $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po->number}"))['receipt'];
    }

    // ───────────────────────── 1. rejected goods stay open · the order is closed short ─────────────────────────

    public function test_rejected_goods_stay_open_on_the_order_and_arrive_on_a_follow_up_shipment(): void
    {
        $p = $this->product('RH-REJ');
        [$first, $po] = $this->expectedShipment([[$p, 100]]);
        $grn = $this->receive($first->number, [['lineNo' => 1, 'acceptedQty' => 80, 'damagedQty' => 5, 'rejectedQty' => 15]]);

        // 80 in staging, 5 in the damaged area, 15 never entered: they are what the supplier still owes
        $this->assertSame(85, $this->totalOnHand($p));
        $this->assertSame(15, $grn['lines'][0]['remainingQty']);
        $this->assertSame(['partial', 15], [$po->refresh()->status, $po->open_qty]);
        $this->assertSame(['damage', 'rejected'], OpsException::where('entity_number', $grn['number'])->pluck('kind')->sort()->values()->all(), 'nothing is short: no shortage exception');
        $this->assertStringContainsString($po->number, OpsException::where('entity_number', $grn['number'])->where('kind', 'rejected')->value('text_ar'));

        // the PO page: four figures, and a progress that never counts the rejection
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po->number}"));
        $this->assertSame(['ordered' => 100, 'received' => 80, 'damaged' => 5, 'rejected' => 15, 'open' => 15, 'waived' => 0], $detail['receipt']);
        $this->assertSame(85, $detail['receivedPct']);
        $this->assertSame(15, $detail['lines'][0]['openQty']);
        $this->assertTrue($detail['rejectedStaysOpen']);
        $row = $this->expectOk($this->getAs('proc', "/api/procurement/po?q={$po->number}"))['items'][0];
        $this->assertSame([85, 15, 15], [$row['receivedPct'], $row['receipt']['rejected'], $row['receipt']['open']]);

        // the follow-up shipment carries exactly the rejected quantity
        $state = $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$first->number}"))['backorder'];
        $this->assertSame([true, 15, 1], [$state['allowed'], $state['openQty'], $state['openLines']]);
        $second = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]));
        $this->assertSame(15, $second['lines'][0]['orderedQty']);
        $this->receive($second['number'], [['lineNo' => 1, 'acceptedQty' => 15]]);
        $this->assertSame(['received', 0], [$po->refresh()->status, $po->open_qty]);
        $this->assertSame(['ordered' => 100, 'received' => 95, 'damaged' => 5, 'rejected' => 15, 'open' => 0, 'waived' => 0], $this->poFigures($po));
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'PO_NOTHING_OPEN', [422]);
        $this->assertReconciles();
    }

    public function test_with_the_policy_off_a_rejection_counts_as_fulfilled_as_before(): void
    {
        $p = $this->product('RH-OFF');
        [$shipment, $po] = $this->expectedShipment([[$p, 100]]);
        $settings = $this->app->make(SettingsService::class);
        $settings->set('procurement.rejectedStaysOpen', false);
        try {
            $grn = $this->receive($shipment->number, [['lineNo' => 1, 'acceptedQty' => 90, 'rejectedQty' => 10]]);
            $this->assertSame(0, $grn['lines'][0]['remainingQty']);
            $this->assertSame(['received', 0], [$po->refresh()->status, $po->open_qty]);
            $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'PO_NOTHING_OPEN', [422]);
            $this->assertSame(0, $this->poFigures($po)['open']);
        } finally {
            $settings->set('procurement.rejectedStaysOpen', true);
        }
        $this->assertSame(10, $this->poFigures($po)['open'], 'the same order under the policy: the rejected 10 are owed');
    }

    public function test_an_order_is_closed_short_and_what_is_open_is_waived(): void
    {
        $p = $this->product('RH-CLS');
        [$first, $po] = $this->expectedShipment([[$p, 50]]);
        $close = "/api/procurement/po/{$po->number}/close";

        // an order the supplier will never deliver is closed without a receipt: everything is waived and the
        // shipment that never came is cancelled with it (a sent order cannot be cancelled, it is closed with a reason)
        [$never, $dead] = $this->expectedShipment([[$p, 7]]);
        $gone = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$dead->number}/close", ['reason' => 'المورد لن يورّد']));
        $this->assertSame(['closed', 0, 'cancelled'], [$gone['status'], $gone['openQty'], InboundShipment::whereKey($never->id)->value('status')]);
        $this->assertSame(['ordered' => 7, 'received' => 0, 'damaged' => 0, 'rejected' => 0, 'open' => 0, 'waived' => 7], $this->poFigures($dead));

        $this->receive($first->number, [['lineNo' => 1, 'acceptedQty' => 30]]);
        $this->assertSame(['partial', 20], [$po->refresh()->status, $po->open_qty]);

        $this->expectRejected($this->postAs('wm', $close, ['reason' => 'لا صلاحية']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', $close), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('proc', $close, ['reason' => 'لا']), 'INVALID_INPUT', [400]);
        $this->assertSame('partial', $po->refresh()->status);

        // a truck at the gate has to be received or refused first; a shipment that never came is cancelled with the order
        $followUp = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]));
        $this->expectOk($this->postAs('wm', "/api/inbound/shipments/{$followUp['number']}/arrive"));
        $body = $this->expectRejected($this->postAs('proc', $close, ['reason' => 'المورد أوقف الصنف']), 'PO_CLOSE_SHIPMENT_OPEN', [422]);
        $this->assertSame($followUp['number'], $body['details']['shipment']);
        $this->expectOk($this->postAs('wm', "/api/inbound/shipments/{$followUp['number']}/unarrive"));

        $closed = $this->expectOk($this->postAs('proc', $close, ['reason' => 'المورد أوقف الصنف']));
        $this->assertSame(['closed', 'proc', 'المورد أوقف الصنف', 0], [$closed['status'], $closed['closedBy'], $closed['closeReason'], $closed['openQty']]);
        $this->assertNotNull($closed['closedAt']);
        $this->assertSame('cancelled', InboundShipment::where('number', $followUp['number'])->value('status'), 'the expected follow-up is cancelled with the order');
        $this->assertSame(['ordered' => 50, 'received' => 30, 'damaged' => 0, 'rejected' => 0, 'open' => 0, 'waived' => 20], $this->poFigures($po));
        $detail = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po->number}"));
        $this->assertSame([0, 20, false], [$detail['lines'][0]['openQty'], $detail['lines'][0]['waivedQty'], $detail['canClose']]);

        $audit = AuditLog::where('action', 'PO.CLOSE')->where('entity_number', $po->number)->get();
        $this->assertCount(1, $audit);
        $logged = is_array($audit[0]->new_value) ? $audit[0]->new_value : json_decode((string) $audit[0]->new_value, true);
        $this->assertSame([20, 'المورد أوقف الصنف'], [$logged['waivedQty'], $logged['reason']]);
        $this->assertSame(1, ActivityLog::where('entity_number', $po->number)->where('text_ar', 'like', '%أُقفل%')->count(), 'procurement and finance are told');

        // closed is final: nothing more is received, nothing is closed twice
        $this->expectRejected($this->postAs('proc', $close, ['reason' => 'مرة ثانية']), 'PO_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]), 'PO_NOT_RECEIVABLE', [422]);
        $this->assertFalse($this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$first->number}"))['backorder']['allowed']);
        $this->assertReconciles();
    }

    // ───────────────────────── 3. refuse at the gate · un-arrive ─────────────────────────

    public function test_a_delivery_is_refused_at_the_gate_and_a_wrong_arrival_is_undone(): void
    {
        $p = $this->product('RH-GATE');
        [$shipment, $po] = $this->expectedShipment([[$p, 40]]);
        $url = "/api/inbound/shipments/{$shipment->number}";
        $movements = InventoryMovement::count();

        // nothing to refuse or undo before the truck is here
        $this->expectRejected($this->postAs('worker', "{$url}/refuse", ['reason' => 'لم تصل بعد']), 'SHIPMENT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('worker', "{$url}/unarrive"), 'SHIPMENT_TRANSITION', [422]);

        // a wrong click on "arrived" is undone; the carrier typed with it goes too
        $this->expectOk($this->postAs('worker', "{$url}/arrive", ['carrier' => 'WRONG-TRUCK']));
        $this->expectRejected($this->postAs('sales', "{$url}/unarrive"), 'FORBIDDEN', [403]);
        $back = $this->expectOk($this->postAs('worker', "{$url}/unarrive"));
        $this->assertSame(['expected', null, null], [$back['status'], $back['arrivedAt'], $back['carrier']]);
        $this->assertSame(1, AuditLog::where('action', 'SHIPMENT.UNARRIVE')->where('entity_number', $shipment->number)->count());

        // arrived for real, inspection started: un-arrive is over, the delivery can still be turned away
        $this->expectOk($this->postAs('worker', "{$url}/arrive", ['carrier' => 'TRK-7']));
        $this->expectOk($this->postAs('worker', "{$url}/inspect"));
        $this->expectRejected($this->postAs('worker', "{$url}/unarrive"), 'SHIPMENT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/cancel"), 'SHIPMENT_TRANSITION', [422]); // cancel stays "before arrival"
        $this->expectRejected($this->postAs('sales', "{$url}/refuse", ['reason' => 'غير مصرح']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', "{$url}/refuse"), 'INVALID_INPUT', [400]);
        $this->assertSame('inspecting', $shipment->refresh()->status);

        $refused = $this->expectOk($this->postAs('worker', "{$url}/refuse", ['reason' => 'سلسلة التبريد مقطوعة']));
        $this->assertSame(['cancelled', 'worker', 'سلسلة التبريد مقطوعة'], [$refused['status'], $refused['refusedBy'], $refused['refusedReason']]);
        $this->assertNotNull($refused['refusedAt']);
        $this->assertSame([true, 40], [$refused['backorder']['allowed'], $refused['backorder']['openQty']], 'the order stays open for a new delivery');
        $this->assertSame($movements, InventoryMovement::count(), 'a refusal moves no stock');
        $this->assertSame('sent', $po->refresh()->status);
        $exc = OpsException::where('entity_number', $shipment->number)->get();
        $this->assertCount(1, $exc);
        $this->assertSame(['rejected', 'proc', $po->number], [$exc[0]->kind, $exc[0]->owner_role, $exc[0]->document_number]);
        $this->assertSame(1, AuditLog::where('action', 'SHIPMENT.REFUSE')->where('entity_number', $shipment->number)->count());

        // a refused shipment is closed for good; the goods come on a follow-up shipment
        $this->expectRejected($this->postAs('worker', "{$url}/refuse", ['reason' => 'مرة ثانية']), 'SHIPMENT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 40]]]), 'SHIPMENT_CANCELLED', [422]);
        $again = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]));
        $grn = $this->receive($again['number'], [['lineNo' => 1, 'acceptedQty' => 40]]);
        $this->assertSame('received', $po->refresh()->status);

        // once a receipt exists the gate is behind us: the way back is the receipt void, and "start inspection" is not a back door
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$again['number']}/refuse", ['reason' => 'بعد الاستلام']), 'SHIPMENT_HAS_GRN', [422]);
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$again['number']}/inspect"), 'SHIPMENT_TRANSITION', [422]);
        $this->assertSame('putaway', $grn['shipment']['status']);

        // an order that was closed while its truck stood at the gate: nothing is received on it — the delivery is refused
        [$late, $closedPo] = $this->expectedShipment([[$p, 5]]);
        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$late->number}/arrive"));
        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$late->number}/inspect"));
        $closedPo->update(['status' => 'closed']);
        $this->expectRejected($this->postAs('wm', "/api/inbound/shipments/{$late->number}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 5]]]), 'PO_NOT_RECEIVABLE', [422]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$late->number}/refuse", ['reason' => 'أمر الشراء مقفل']))['status']);
        $this->assertReconciles();
    }

    // ───────────────────────── 2. several batches on one receiving line ─────────────────────────

    public function test_a_receiving_line_is_split_over_several_batches(): void
    {
        $tracked = $this->product('RH-BAT', tracksExpiry: true);
        $plain = $this->product('RH-BAT2');
        [$shipment, $po] = $this->expectedShipment([[$tracked, 100], [$plain, 30]]);
        $url = "/api/inbound/shipments/{$shipment->number}";
        $this->expectOk($this->postAs('wm', "{$url}/arrive"));
        $this->expectOk($this->postAs('wm', "{$url}/inspect"));
        [$b1, $b2] = ['L1-'.self::uid(), 'L2-'.self::uid()];
        $batches = [['qty' => 50, 'batchNo' => $b1, 'expiryDate' => '2027-01-31'], ['qty' => 40, 'batchNo' => $b2, 'mfgDate' => '2026-09-01', 'expiryDate' => '2027-03-31']];
        $line1 = ['lineNo' => 1, 'acceptedQty' => 90, 'damagedQty' => 4, 'rejectedQty' => 6, 'batches' => $batches];
        $line2 = ['lineNo' => 2, 'acceptedQty' => 30];
        $post = fn (array $l1, array $extra = []) => $this->postAs('wm', "{$url}/grn", ['lines' => array_merge([$l1, $line2], $extra)]);

        // a repeated line is refused instead of silently replacing the first one
        $this->expectRejected($post($line1, [['lineNo' => 1, 'acceptedQty' => 10, 'batchNo' => 'X', 'expiryDate' => '2027-01-01']]), 'LINE_DUPLICATE', [400]);
        // the batches must add up to the accepted quantity
        $body = $this->expectRejected($post(['acceptedQty' => 95, 'damagedQty' => 0, 'rejectedQty' => 0] + $line1), 'BATCH_SPLIT_SUM', [400]);
        $this->assertSame(['lineNo' => 1, 'accepted' => 95, 'batches' => 90], $body['details']);
        $noExpiry = $line1;
        unset($noExpiry['batches'][1]['expiryDate']);
        $this->expectRejected($post($noExpiry), 'EXPIRY_REQUIRED', [400]);
        $twice = $line1;
        $twice['batches'][1]['batchNo'] = strtolower($b1);
        $this->expectRejected($post($twice), 'BATCH_SPLIT_DUPLICATE', [400]);
        $zero = $line1;
        $zero['batches'][0]['qty'] = 0;
        $this->expectRejected($post($zero), 'INVALID_INPUT', [400]);
        $noNumber = $line1;
        unset($noNumber['batches'][0]['batchNo']);
        $this->expectRejected($post($noNumber), 'INVALID_INPUT', [400]);
        $this->assertSame(0, GoodsReceipt::where('shipment_id', $shipment->id)->count());
        $this->assertSame(0, $this->totalOnHand($tracked));

        $grn = $this->expectOk($post($line1));
        // one receipt row, one batch, one putaway task and one movement per batch; the first row carries the line's damaged / rejected
        $this->assertSame([[1, 1], [1, 2], [2, 1]], array_map(fn ($l) => [$l['lineNo'], $l['splitNo']], $grn['lines']));
        $this->assertSame([60, 50, 4, 6, 6, $b1, 'rejected'], array_map(fn ($k) => $grn['lines'][0][$k], ['receivedQty', 'acceptedQty', 'damagedQty', 'rejectedQty', 'remainingQty', 'batchNo', 'qcResult']));
        $this->assertSame([40, 40, 0, 0, 0, $b2, 'accepted'], array_map(fn ($k) => $grn['lines'][1][$k], ['receivedQty', 'acceptedQty', 'damagedQty', 'rejectedQty', 'remainingQty', 'batchNo', 'qcResult']));
        $this->assertEqualsCanonicalizing([[50, $b1], [40, $b2], [30, null]], array_map(fn ($t) => [$t['qty'], $t['batchNo']], $grn['putaways']));
        $this->assertEqualsCanonicalizing([['grn', 50, $b1, 'STG-IN'], ['grn', 40, $b2, 'STG-IN'], ['dmg', 4, $b1, 'DMG-01'], ['grn', 30, null, 'STG-IN']],
            array_map(fn ($m) => [$m['type'], $m['qty'], $m['batchNo'], $m['dstBin']['code']], $grn['movements']));
        $this->assertSame([90, 4], [$this->onHand($tracked, 'STG-IN'), $this->onHand($tracked, 'DMG-01')]);
        $this->assertStringStartsWith('2027-03-31', (string) Batch::where('product_id', $tracked->id)->where('batch_no', $b2)->value('expiry_date'));
        $this->assertSame(2, Batch::where('product_id', $tracked->id)->count());
        $line = ShipmentLine::where('shipment_id', $shipment->id)->where('line_no', 1)->first();
        $this->assertSame([90, 4, 6, $b1], [$line->accepted_qty, $line->damaged_qty, $line->rejected_qty, $line->batch_no]);
        $this->assertSame(['partial', 6], [$po->refresh()->status, $po->open_qty], 'only the 6 rejected are still owed');
        $this->assertSame(3, StagingEntry::where('reference_type', 'PutawayTask')->whereIn('reference_id', array_column($grn['putaways'], 'id'))->where('status', 'waiting')->count());

        // each batch is put away on its own
        foreach ($grn['putaways'] as $task) {
            $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$task['number']}/confirm"));
        }
        $this->assertSame('done', $shipment->refresh()->status);
        $this->assertSame(90, $this->inv()->availability($tracked->id)['total']);
        $this->assertReconciles();
    }

    // ───────────────────────── 5. split putaway · a task clears only its own staging entry ─────────────────────────

    public function test_a_putaway_task_is_split_over_bins_and_clears_only_its_own_staging_entry(): void
    {
        $a = $this->product('RH-PUT-A');
        $b = $this->product('RH-PUT-B');
        [$shipment] = $this->expectedShipment([[$a, 60], [$b, 60]]); // two tasks of one receipt with the SAME quantity
        $grn = $this->receive($shipment->number, [['lineNo' => 1, 'acceptedQty' => 60], ['lineNo' => 2, 'acceptedQty' => 60]]);
        $taskA = collect($grn['putaways'])->firstWhere('productId', $a->id);
        $taskB = collect($grn['putaways'])->firstWhere('productId', $b->id);
        [$bin1, $bin2] = $this->ambientBins(2);
        $confirm = "/api/inbound/putaway/{$taskA['number']}/confirm";
        $entry = fn (array $task) => StagingEntry::where('reference_type', 'PutawayTask')->where('reference_id', $task['id'])->first();

        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin1, 'qty' => 0]), 'INVALID_INPUT', [400]);
        $body = $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin1, 'qty' => 61]), 'PUTAWAY_QTY', [400]);
        $this->assertSame(['remaining' => 60, 'entered' => 61], $body['details']);

        // 25 go into the first bin: the task stays open for the other 35
        $part = $this->expectOk($this->postAs('worker', $confirm, ['scannedBin' => $bin1, 'scannedProduct' => $a->sku, 'qty' => 25]));
        $this->assertSame([25, 25, 35, false, false], [$part['qty'], $part['doneQty'], $part['remaining'], $part['taskDone'], $part['shipmentDone']]);
        $this->assertSame([35, 25], [$this->onHand($a, 'STG-IN'), $this->onHand($a, $bin1)]);
        $open = collect($this->expectOk($this->getAs('worker', '/api/inbound/putaway?grn='.$grn['number']))['items'])->firstWhere('number', $taskA['number']);
        $this->assertSame(['open', 60, 25], [$open['status'], $open['qty'], $open['doneQty']]);
        $this->assertSame(['waiting', 35], [$entry($taskA)->status, $entry($taskA)->qty], 'the entry shows what still waits in staging');
        $this->assertSame(['waiting', 60], [$entry($taskB)->status, $entry($taskB)->qty]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin2, 'qty' => 36]), 'PUTAWAY_QTY', [400]);

        // the rest goes into another bin (no quantity = everything that is left)
        $rest = $this->expectOk($this->postAs('worker', $confirm, ['scannedBin' => $bin2]));
        $this->assertSame([35, 60, 0, true, false], [$rest['qty'], $rest['doneQty'], $rest['remaining'], $rest['taskDone'], $rest['shipmentDone']]);
        $this->assertSame([0, 25, 35], [$this->onHand($a, 'STG-IN'), $this->onHand($a, $bin1), $this->onHand($a, $bin2)]);
        $this->assertSame('cleared', $entry($taskA)->status);
        // the bug the audit found: the other task of the same receipt has the same quantity — its entry must still be waiting
        $this->assertSame('waiting', $entry($taskB)->status);
        $this->assertSame('putaway', $shipment->refresh()->status);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin2]), 'PUTAWAY_DONE', [409]);
        $this->assertSame(2, InventoryMovement::where('reference_type', 'PutawayTask')->where('reference_id', $taskA['id'])->where('type', 'putaway')->count());

        $last = $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$taskB['number']}/confirm"));
        $this->assertSame([60, true, true], [$last['qty'], $last['taskDone'], $last['shipmentDone']]);
        $this->assertSame('done', $shipment->refresh()->status);
        $this->assertSame(0, StagingEntry::whereIn('reference_id', [$taskA['id'], $taskB['id']])->where('status', 'waiting')->count());
        $this->assertReconciles();
    }

    public function test_staging_entries_of_older_receipts_are_cleared_one_at_a_time(): void
    {
        $a = $this->product('RH-LEG-A');
        $b = $this->product('RH-LEG-B');
        [$shipment] = $this->expectedShipment([[$a, 12], [$b, 12]]);
        $grn = $this->receive($shipment->number, [['lineNo' => 1, 'acceptedQty' => 12], ['lineNo' => 2, 'acceptedQty' => 12]]);
        // receipts posted before this release reference the GRN, not the task
        StagingEntry::whereIn('reference_id', array_column($grn['putaways'], 'id'))->update(['reference_type' => 'GoodsReceipt', 'reference_id' => $grn['id'], 'reference_number' => $grn['number']]);
        $waiting = fn () => StagingEntry::where('reference_type', 'GoodsReceipt')->where('reference_id', $grn['id'])->where('status', 'waiting')->count();
        $this->assertSame(2, $waiting());

        $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$grn['putaways'][0]['number']}/confirm"));
        $this->assertSame(1, $waiting(), 'one putaway clears one entry, even when both have the same quantity');
        $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$grn['putaways'][1]['number']}/confirm"));
        $this->assertSame(0, $waiting());
        $this->assertReconciles();
    }

    // ───────────────────────── 4. voiding a goods receipt ─────────────────────────

    public function test_an_untouched_receipt_is_voided_and_everything_returns_to_where_it_was(): void
    {
        $p = $this->product('RH-VOID', tracksExpiry: true);
        [$shipment, $po] = $this->expectedShipment([[$p, 100]]);
        [$b1, $b2] = ['V1-'.self::uid(), 'V2-'.self::uid()];
        $grn = $this->receive($shipment->number, [['lineNo' => 1, 'acceptedQty' => 70, 'damagedQty' => 10, 'rejectedQty' => 5,
            'batches' => [['qty' => 40, 'batchNo' => $b1, 'expiryDate' => '2027-02-28'], ['qty' => 30, 'batchNo' => $b2, 'expiryDate' => '2027-04-30']]]]);
        $void = "/api/inbound/grns/{$grn['number']}/void";

        // the receipt valued 80 units at the order price 10: inventory ↑ 800, supplier accrual (GRNI) ↑ 800
        $this->assertEqualsWithDelta(800.0, $this->gl('inventory', $grn['number']), 0.001);
        $this->assertEqualsWithDelta(-800.0, $this->gl('grni', $grn['number']), 0.001);
        $this->assertSame(['partial', 20], [$po->refresh()->status, $po->open_qty]);
        $this->assertSame(3, OpsException::where('entity_number', $grn['number'])->where('status', 'open')->count()); // damage, rejected, shortage
        $this->assertTrue($this->expectOk($this->getAs('wm', "/api/inbound/grns/{$grn['number']}"))['void']['allowed']);

        // permission, reason, unknown receipt — nothing changes
        $this->expectRejected($this->postAs('worker', $void, ['reason' => 'ليست صلاحيتي']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', $void, ['reason' => 'ليست صلاحيتي']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', $void), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inbound/grns/GRN-NOPE/void', ['reason' => 'غير موجود']), 'GRN_NOT_FOUND', [404]);
        $this->assertSame(80, $this->totalOnHand($p));

        $voided = $this->expectOk($this->postAs('wm', $void, ['reason' => 'استُلمت على شحنة خاطئة']));
        $this->assertSame([true, 'wm', 'استُلمت على شحنة خاطئة'], [$voided['voided'], $voided['voidedBy'], $voided['voidReason']]);
        $this->assertNotNull($voided['voidedAt']);
        $this->assertSame(['inspecting', 'sent'], [$voided['shipment']['status'], $voided['po']['status']]);
        $this->assertSame(['cancelled', 'cancelled'], array_column($voided['putaways'], 'status'));
        $this->assertEqualsCanonicalizing([[40, 'STG-IN'], [30, 'STG-IN'], [10, 'DMG-01']],
            array_map(fn ($m) => [$m['qty'], $m['srcBin']['code']], array_values(array_filter($voided['movements'], fn ($m) => $m['type'] === 'grnvoid'))));
        $this->assertSame(['GRN_VOIDED', false], [$voided['void']['code'], $voided['void']['allowed']]);

        // stock and money: nothing of this receipt is left on the books
        $this->assertSame(0, $this->totalOnHand($p));
        $this->assertEqualsWithDelta(0.0, $this->gl('inventory', $grn['number']), 0.001, 'inventory value is back to what it was');
        $this->assertEqualsWithDelta(0.0, $this->gl('grni', $grn['number']), 0.001, 'the accrual the receipt created is closed');
        $this->assertReconciles();

        // documents: order, shipment, tasks, staging, exceptions
        $this->assertSame(['sent', 100], [$po->refresh()->status, $po->open_qty]);
        $line = PoLine::where('po_id', $po->id)->first();
        $this->assertSame([0, 0, 0], [$line->received_qty, $line->damaged_qty, $line->rejected_qty]);
        $sl = ShipmentLine::where('shipment_id', $shipment->id)->first();
        $this->assertSame([0, 0, 0], [$sl->accepted_qty, $sl->damaged_qty, $sl->rejected_qty]);
        $detail = $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$shipment->number}"));
        $this->assertSame(['inspecting', null, null, 100], [$detail['status'], $detail['completedAt'], $detail['grn'], $detail['totals']['open']]);
        $this->assertNotNull($detail['grns'][0]['voidedAt']);
        $this->assertSame(0, $this->expectOk($this->getAs('wm', '/api/inbound/putaway?grn='.$grn['number']))['total']);
        $this->assertSame(0, StagingEntry::whereIn('reference_id', array_column($grn['putaways'], 'id'))->where('status', 'waiting')->count());
        $excs = OpsException::where('entity_number', $grn['number'])->get();
        $this->assertSame(['resolved'], $excs->pluck('status')->unique()->values()->all());
        $this->assertStringContainsString('استُلمت على شحنة خاطئة', $excs[0]->resolution);
        $this->assertSame(1, AuditLog::where('action', 'GRN.VOID')->where('entity_number', $grn['number'])->count());
        $this->assertSame(1, ActivityLog::where('entity_number', $grn['number'])->where('text_ar', 'like', '%أُلغي إذن الاستلام%')->count());

        // a voided receipt is over: no second void, no putaway of its tasks
        $this->expectRejected($this->postAs('wm', $void, ['reason' => 'مرة ثانية']), 'GRN_VOIDED', [409]);
        $this->expectRejected($this->postAs('worker', "/api/inbound/putaway/{$grn['putaways'][0]['number']}/confirm"), 'PUTAWAY_CANCELLED', [409]);

        // the shipment is received again, correctly this time
        $again = $this->expectOk($this->postAs('wm', "/api/inbound/shipments/{$shipment->number}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 100, 'batchNo' => $b1, 'expiryDate' => '2027-02-28']]]));
        $this->assertNotSame($grn['number'], $again['number']);
        $this->assertSame(['received', 0], [$po->refresh()->status, $po->open_qty]);
        $this->assertSame($again['number'], $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$shipment->number}"))['grn']);
        $this->assertSame(100, $this->totalOnHand($p));

        // lists: the voided receipt stays visible as history and is never counted
        $log = $this->expectOk($this->getAs('wm', "/api/inbound/grns?po={$po->number}"));
        $this->assertSame([[$again['number'], false], [$grn['number'], true]], array_map(fn ($g) => [$g['number'], $g['voided']], $log['items']));
        $this->assertSame([$again['number']], array_column($this->expectOk($this->getAs('wm', "/api/inbound/grns?po={$po->number}&voided=0"))['items'], 'number'));
        $order = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$po->number}"));
        $this->assertSame(100, $order['lines'][0]['acceptedQty'], 'invoice matching sees only the receipt in force');
        $this->assertSame([$again['number']], $order['trace'][0]['grns']);
        $this->assertSame(1, $this->expectOk($this->getAs('proc', "/api/procurement/po?q={$po->number}"))['items'][0]['_count']['grns']);
        $this->assertReconciles();
    }

    public function test_a_receipt_that_was_touched_is_too_late_to_void(): void
    {
        // putaway started
        $p = $this->product('RH-LATE-P');
        [$s1] = $this->expectedShipment([[$p, 50]]);
        $g1 = $this->receive($s1->number, [['lineNo' => 1, 'acceptedQty' => 50]]);
        $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$g1['putaways'][0]['number']}/confirm", ['qty' => 10]));
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g1['number']}/void", ['reason' => 'بعد بدء التخزين']), 'GRN_VOID_LATE', [422]);
        $this->assertSame(['putaway', [$g1['putaways'][0]['number']]], [$body['details']['blocker'], $body['details']['tasks']]);
        $state = $this->expectOk($this->getAs('wm', "/api/inbound/grns/{$g1['number']}"))['void'];
        $this->assertSame([false, 'GRN_VOID_LATE', 'putaway'], [$state['allowed'], $state['code'], $state['blocker']]);
        $this->assertSame(50, $this->totalOnHand($p));

        // the damaged quantity already left the damaged area (scrapped)
        $d = $this->product('RH-LATE-D');
        [$s2] = $this->expectedShipment([[$d, 25]]);
        $g2 = $this->receive($s2->number, [['lineNo' => 1, 'acceptedQty' => 20, 'damagedQty' => 5]]);
        $dmg = $this->inv()->specialBin($this->ryd()->id, 'DMG-01');
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'scrap', 'productId' => $d->id, 'qty' => 5, 'from' => ['warehouseId' => $this->ryd()->id, 'binId' => $dmg->id], 'reference' => ['type' => 'Test', 'number' => 'T-SCRAP']]));
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g2['number']}/void", ['reason' => 'التالف أُتلف']), 'GRN_VOID_LATE', [422]);
        $this->assertSame(['damaged', 'DMG-01', 0, 5], [$body['details']['blocker'], $body['details']['bin'], $body['details']['free'], $body['details']['needed']]);
        $this->assertNull(GoodsReceipt::where('number', $g2['number'])->value('voided_at'));

        // part of the accepted quantity is no longer in inbound staging (moved by hand)
        $m = $this->product('RH-LATE-M');
        [$s4] = $this->expectedShipment([[$m, 10]]);
        $g4 = $this->receive($s4->number, [['lineNo' => 1, 'acceptedQty' => 10]]);
        $stg = $this->inv()->specialBin($this->ryd()->id, 'STG-IN');
        $shelf = $this->inv()->bin($this->ryd()->id, $this->ambientBins(1)[0]);
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'move', 'productId' => $m->id, 'qty' => 4, 'from' => ['warehouseId' => $this->ryd()->id, 'binId' => $stg->id],
            'to' => ['warehouseId' => $this->ryd()->id, 'binId' => $shelf->id], 'reference' => ['type' => 'Test', 'number' => 'T-MOVE']]));
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g4['number']}/void", ['reason' => 'نُقل جزء منها']), 'GRN_VOID_LATE', [422]);
        $this->assertSame(['stock', 'STG-IN', 6, 10], [$body['details']['blocker'], $body['details']['bin'], $body['details']['free'], $body['details']['needed']]);

        // a purchase return was raised on the receipt: the return is the way back; cancelled, it stops nothing
        $r = $this->product('RH-LATE-R');
        [$s5] = $this->expectedShipment([[$r, 8]]);
        $g5 = $this->receive($s5->number, [['lineNo' => 1, 'acceptedQty' => 8]]);
        $return = ReturnOrder::create(['number' => self::code('RTN-T'), 'type' => 'supplier', 'warehouse_id' => $this->ryd()->id, 'status' => 'pending', 'attachments' => [], 'grn_id' => $g5['id']]);
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g5['number']}/void", ['reason' => 'عليه مرتجع']), 'GRN_VOID_LATE', [422]);
        $this->assertSame(['return', [$return->number]], [$body['details']['blocker'], $body['details']['returns']]);
        $return->update(['status' => 'cancelled']);
        $this->assertTrue($this->expectOk($this->getAs('wm', "/api/inbound/grns/{$g5['number']}"))['void']['allowed']);

        // a supplier invoice bills the received quantity: cancel it first
        $i = $this->product('RH-LATE-I');
        [$s3, $po3] = $this->expectedShipment([[$i, 30]]);
        $g3 = $this->receive($s3->number, [['lineNo' => 1, 'acceptedQty' => 30]]);
        $invoice = $this->expectOk($this->postAs('proc', '/api/procurement/invoices', ['po' => $po3->number, 'supplierInvoiceNo' => 'RH-'.self::uid(), 'invoiceDate' => now()->toDateString(), 'vatPct' => 15, 'lines' => [['lineNo' => 1, 'qty' => 20, 'price' => 10]]]));
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g3['number']}/void", ['reason' => 'مفوترة']), 'GRN_VOID_LATE', [422]);
        $this->assertSame(['invoice', [$invoice['number']]], [$body['details']['blocker'], $body['details']['invoices']]);
        $this->expectOk($this->postAs('proc', "/api/procurement/invoices/{$invoice['number']}/cancel", ['reason' => 'سُجلت على استلام خاطئ']));
        $this->assertTrue($this->expectOk($this->getAs('wm', "/api/inbound/grns/{$g3['number']}"))['void']['allowed']);
        $this->expectOk($this->postAs('wm', "/api/inbound/grns/{$g3['number']}/void", ['reason' => 'بعد إلغاء الفاتورة']));
        $this->assertSame(['sent', 30], [$po3->refresh()->status, $po3->open_qty]);
        $this->assertReconciles();
    }

    public function test_voiding_next_to_a_follow_up_shipment_never_puts_two_shipments_on_the_same_quantity(): void
    {
        // a follow-up shipment is already waiting for the remainder
        $p = $this->product('RH-FUP');
        [$first, $po] = $this->expectedShipment([[$p, 100]]);
        $g1 = $this->receive($first->number, [['lineNo' => 1, 'acceptedQty' => 60]]);
        $followUp = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po->number]));
        $body = $this->expectRejected($this->postAs('wm', "/api/inbound/grns/{$g1['number']}/void", ['reason' => 'شحنة المتابعة مفتوحة']), 'GRN_VOID_FOLLOWUP_OPEN', [422]);
        $this->assertSame($followUp['number'], $body['details']['shipment']);
        $this->expectOk($this->postAs('admin', "/api/inbound/shipments/{$followUp['number']}/cancel"));
        $voided = $this->expectOk($this->postAs('wm', "/api/inbound/grns/{$g1['number']}/void", ['reason' => 'أُلغيت شحنة المتابعة']));
        $this->assertSame(['inspecting', 'sent'], [$voided['shipment']['status'], $voided['po']['status']], 'a cancelled follow-up does not stop the shipment from being reopened');
        $this->assertSame(100, $po->refresh()->open_qty);

        // the follow-up was already received: the first shipment is closed and the remainder goes on a new follow-up
        $q = $this->product('RH-FUP2');
        [$one, $po2] = $this->expectedShipment([[$q, 100]]);
        $ga = $this->receive($one->number, [['lineNo' => 1, 'acceptedQty' => 60]]);
        $two = $this->expectOk($this->postAs('wm', '/api/inbound/shipments/backorder', ['po' => $po2->number]));
        $gb = $this->receive($two['number'], [['lineNo' => 1, 'acceptedQty' => 40]]);
        $this->assertSame('received', $po2->refresh()->status);
        $voided = $this->expectOk($this->postAs('wm', "/api/inbound/grns/{$ga['number']}/void", ['reason' => 'الاستلام الأول خاطئ']));
        $this->assertSame(['done', 'partial'], [$voided['shipment']['status'], $voided['po']['status']]);
        $this->assertSame(60, $po2->refresh()->open_qty, 'what the second receipt accepted stays received');
        $this->assertSame(40, $this->totalOnHand($q));
        $state = $this->expectOk($this->getAs('wm', "/api/inbound/shipments/{$one->number}"))['backorder'];
        $this->assertSame([true, 60], [$state['allowed'], $state['openQty']]);
        $this->assertNull(GoodsReceipt::where('number', $gb['number'])->value('voided_at'));
        $this->assertSame(0, PutawayTask::where('grn_id', $ga['id'])->where('status', 'open')->count());
        $this->assertReconciles();
    }
}
