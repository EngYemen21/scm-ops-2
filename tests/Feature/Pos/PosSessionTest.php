<?php

namespace Tests\Feature\Pos;

use App\Finance\Models\Journal;
use App\Pos\Models\PosSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * Sessions: opened with the float counted by denomination, one per register and one per cashier (unique keys, not
 * only a check), cash in and out with their journals, closed with the cash counted by denomination — the difference
 * explained and posted — and immutable afterwards with its end-of-day report frozen.
 */
class PosSessionTest extends ApiTestCase
{
    use PosFixtures;

    public function test_a_session_from_float_to_count_and_its_frozen_end_of_day_report(): void
    {
        $w = $this->world();
        // the float counted: 1 × 100 + 1 × 50 + 2 × 0.50 = 151.00
        $s = $this->expectOk($this->postAs($w['cashier'], '/api/pos/sessions', ['register' => $w['register'], 'counts' => [['value' => 100, 'count' => 1], ['value' => '50', 'count' => 1], ['value' => 0.5, 'count' => 2], ['value' => 5, 'count' => 0]]]));
        $this->assertMatchesRegularExpression('/^PSS-\d{4}-\d{5}$/', $s['number']);
        $this->assertSame(['open', '151.00', 3], [$s['state'], $s['openingCash'], count($s['openingCounts'])]);
        $this->assertSame($s['id'], $this->expectOk($this->getAs($w['cashier'], '/api/pos/sessions/current'))['session']['id']);
        $this->expectRejected($this->postAs($w['cashier2'], '/api/pos/sessions', ['register' => $w['register'], 'counts' => [['value' => 3, 'count' => 1]]]), 'POS_DENOMINATION', [400]);

        // one per register, one per cashier — and the database itself refuses a second open row
        $this->expectRejected($this->postAs($w['cashier2'], '/api/pos/sessions', ['register' => $w['register']]), 'POS_SESSION_OPEN', [409]);
        $reg2 = $this->expectOk($this->postAs('admin', '/api/pos/registers', ['code' => "QPR2{$w['tag']}", 'nameAr' => 'صندوق ثان', 'warehouse' => $w['wh'], 'customer' => $w['walkin'], 'methods' => [$w['mCash']]]));
        $this->expectRejected($this->postAs($w['cashier'], '/api/pos/sessions', ['register' => $reg2['id']]), 'POS_CASHIER_BUSY', [409]);
        try {
            DB::table('pos_sessions')->insert(['id' => (string) Str::ulid(), 'number' => "X{$w['tag']}", 'register_id' => $w['register'], 'state' => 'open', 'open_register_key' => $w['register'], 'created_at' => now()]);
            $this->fail('a second open session on one register must be refused by the unique key');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // a sale paid 20.00 cash for 12.01: 7.99 change; cash in 10, cash out 50 to the bank
        $o = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '20']]));
        $this->assertSame(['7.99', '12.01'], [$o['changeDue'], $o['payments'][0]['applied']]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/cash-moves", ['direction' => 'out', 'amount' => 1000, 'reason' => 'إيداع في البنك', 'bankAccount' => $w['bank']]), 'POS_CASH_SHORT', [422]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/cash-moves", ['direction' => 'out', 'amount' => 5, 'reason' => 'نفس الدرج', 'bankAccount' => $w['cash']]), 'POS_CASH_MOVE_ACCOUNT', [422]);
        $this->expectOk($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/cash-moves", ['direction' => 'in', 'amount' => 10, 'reason' => 'تعزيز الفكة', 'bankAccount' => $w['bank']]));
        $now = $this->expectOk($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/cash-moves", ['direction' => 'out', 'amount' => '50', 'reason' => 'إيداع في البنك', 'bankAccount' => $w['bank']]));
        $this->assertSame('123.01', $now['expectedCashNow'], '151.00 + 12.01 + 10.00 − 50.00');
        $moves = Journal::where('source_type', 'PosSession')->where('source_id', $s['id'])->where('event_key', 'like', 'pos:move:%')->get();
        $this->assertSame(['10.00', '50.00'], $moves->pluck('total_debit')->sort()->values()->all());

        // closing: another cashier may not; a difference must be explained; then it is posted
        $count = ['counts' => [['value' => 100, 'count' => 1], ['value' => 20, 'count' => 1]]];   // 120.00 counted: 3.01 short
        $this->expectRejected($this->postAs($w['cashier2'], "/api/pos/sessions/{$s['id']}/close", $count), 'POS_NOT_YOUR_SESSION', [403]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/close", $count), 'POS_DIFF_REASON', [400]);
        $closed = $this->expectOk($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/close", $count + ['reason' => 'خطأ في إعطاء الباقي']));
        $this->assertSame(['closed', '123.01', '120.00', '-3.01', 'خطأ في إعطاء الباقي'], [$closed['state'], $closed['expectedCash'], $closed['countedCash'], $closed['difference'], $closed['differenceReason']]);
        $diff = Journal::with('lines.account')->where('event_key', "pos:diff:{$s['id']}")->firstOrFail();
        $this->assertSame('3.01', (string) $diff->total_debit);
        $this->assertSame(['admin_expenses' => '3.01', 'cash' => '-3.01'], $diff->lines->mapWithKeys(fn ($l) => [$l->account->role => number_format((float) $l->debit - (float) $l->credit, 2, '.', '')])->sortKeys()->all());

        // the end-of-day report, frozen
        $report = $this->expectOk($this->getAs('admin', "/api/pos/sessions/{$s['id']}/report"));
        $this->assertTrue($report['frozen']);
        $this->assertSame(['151.00', '12.01', '0.00', '10.00', '50.00', '123.01', '120.00', '-3.01'], [$report['cash']['opening'], $report['cash']['sales'], $report['cash']['refunds'], $report['cash']['in'], $report['cash']['out'],
            $report['cash']['expected'], $report['cash']['counted'], $report['cash']['difference']]);
        $this->assertSame([1, '10.44', '1.57', '12.01'], [$report['sales']['count'], $report['sales']['net'], $report['sales']['vat'], $report['sales']['total']]);
        $this->assertEquals([['ratePct' => '15.00', 'base' => '10.44', 'vat' => '1.57']], $report['vat']);   // a JSON column keeps its own key order
        $this->assertSame([['cash', '12.01', '0.00', '12.01']], array_map(fn ($m) => [$m['kind'], $m['sales'], $m['refunds'], $m['net']], $report['byMethod']));
        $this->assertSame('4.00', $report['margin']['cogs']);
        $this->assertArrayNotHasKey('margin', $this->expectOk($this->getAs($w['cashier'], "/api/pos/sessions/{$s['id']}/report")), 'cost only for those who may see it');

        // closed is closed: no sale, no cash, no second closing; the keys are free again
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '20']]), 'POS_SESSION_CLOSED', [422]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/cash-moves", ['direction' => 'in', 'amount' => 1, 'reason' => 'محاولة بعد الإغلاق', 'bankAccount' => $w['bank']]), 'POS_SESSION_CLOSED', [422]);
        $this->expectRejected($this->postAs('admin', "/api/pos/sessions/{$s['id']}/close", ['reason' => 'مرة ثانية']), 'POS_SESSION_CLOSED', [422]);
        $s2 = $this->openSession($w, $w['cashier'], '0');

        // a refund made later, in another session, does not change the frozen report
        $this->expectOk($this->postAs($w['cashier'], "/api/pos/orders/{$o['id']}/refund", ['session' => $s2['id'], 'clientKey' => "rf-{$w['tag']}", 'reason' => 'العميل غيّر رأيه',
            'lines' => [['lineId' => $o['lines'][0]['id'], 'qty' => 1]], 'payments' => [['method' => $w['mCard'], 'amount' => '12.01', 'reference' => 'RF-1']]]));
        $this->assertSame($report['cash'], $this->expectOk($this->getAs('admin', "/api/pos/sessions/{$s['id']}/report"))['cash']);
        $this->assertSame(1, $this->expectOk($this->getAs('admin', "/api/pos/sessions/{$s2['id']}/report"))['refunds']['count']);

        // a manager may close someone's session; an empty drawer that should hold nothing closes without a difference
        $c2 = $this->expectOk($this->postAs('admin', "/api/pos/sessions/{$s2['id']}/close", ['counts' => []]));
        $this->assertSame(['closed', '0.00', null], [$c2['state'], $c2['difference'], PosSession::find($s2['id'])->difference_journal_id]);

        // the list: filters and a total order
        $list = $this->expectOk($this->getAs($w['cashier'], "/api/pos/sessions?register={$w['register']}&state=closed&sort=number&order=asc"));
        $this->assertSame([$s['number'], $s2['number']], array_column($list['items'], 'number'));
        $this->assertSame(2, $this->expectOk($this->getAs($w['cashier'], "/api/pos/sessions?register={$w['register']}&cashier=me"))['total']);
    }

    public function test_a_register_that_is_not_ready_cannot_be_opened(): void
    {
        $w = $this->world();
        $bare = $this->expectOk($this->postAs('admin', '/api/pos/registers', ['code' => "QPX{$w['tag']}", 'nameAr' => 'صندوق ناقص', 'warehouse' => $w['wh']]));
        $this->assertEqualsCanonicalizing(['customer', 'methods'], $bare['notReady']);
        $res = $this->expectRejected($this->postAs($w['cashier'], '/api/pos/sessions', ['register' => $bare['id']]), 'POS_REGISTER_NOT_READY', [422]);
        $this->assertEqualsCanonicalizing(['customer', 'methods'], $res['details']['missing']);
        $this->expectOk($this->patchAs('admin', "/api/pos/registers/{$bare['id']}", ['active' => false]));
        $this->expectRejected($this->postAs($w['cashier'], '/api/pos/sessions', ['register' => $bare['id']]), 'POS_REGISTER_INACTIVE', [422]);
    }
}
