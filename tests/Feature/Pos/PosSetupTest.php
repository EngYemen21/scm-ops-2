<?php

namespace Tests\Feature\Pos;

use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\PosBootstrap;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** What the counter is made of: payment methods bound to the right finance account, registers, denominations. */
class PosSetupTest extends ApiTestCase
{
    use PosFixtures;

    public function test_methods_registers_and_denominations_follow_their_rules(): void
    {
        $w = $this->world();
        // a cash method sits on a cash box, a card method on a bank account
        $this->expectRejected($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPBAD{$w['tag']}", 'nameAr' => 'نقد خطأ', 'kind' => 'cash', 'bankAccount' => $w['bank']]), 'POS_METHOD_ACCOUNT_KIND', [422]);
        $this->expectRejected($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPBAD{$w['tag']}", 'nameAr' => 'شبكة خطأ', 'kind' => 'card', 'bankAccount' => $w['cash']]), 'POS_METHOD_ACCOUNT_KIND', [422]);
        $this->expectRejected($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPCASH{$w['tag']}", 'nameAr' => 'مكرر', 'kind' => 'transfer', 'bankAccount' => $w['bank']]), 'POS_METHOD_CODE', [409]);
        $this->expectRejected($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPNB{$w['tag']}", 'nameAr' => 'بلا حساب', 'kind' => 'card']), null, [400]);
        $card = PosPaymentMethod::find($w['mCard']);
        $this->assertTrue($card->requires_reference, 'a card payment is recorded with its reference');
        $this->assertNull(PosPaymentMethod::find($w['mAcct'])->bank_account_id);

        // the cashier sets nothing up — and nothing is created by trying
        $before = PosRegister::count();
        $this->expectRejected($this->postAs($w['cashier'], '/api/pos/registers', ['code' => "QPN{$w['tag']}", 'nameAr' => 'صندوق', 'warehouse' => $w['wh']]), null, [403]);
        $this->expectRejected($this->patchAs($w['cashier'], "/api/pos/registers/{$w['register']}", ['maxDiscountPct' => 100]), null, [403]);
        $this->assertSame($before, PosRegister::count());
        $this->assertSame('10.00', PosRegister::find($w['register'])->max_discount_pct);

        // one drawer per register
        $cash2 = $this->expectOk($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPC2{$w['tag']}", 'nameAr' => 'نقد 2', 'kind' => 'cash', 'bankAccount' => $w['cash']]));
        $this->expectRejected($this->patchAs('admin', "/api/pos/registers/{$w['register']}", ['methods' => [$w['mCash'], $cash2['id']]]), 'POS_ONE_CASH_METHOD', [422]);
        $this->expectRejected($this->postAs('admin', '/api/pos/registers', ['code' => "QPR{$w['tag']}", 'nameAr' => 'مكرر', 'warehouse' => $w['wh']]), 'POS_REGISTER_CODE', [409]);
        $this->expectRejected($this->patchAs('admin', "/api/pos/registers/{$w['register']}", ['sellerVatNo' => '12345']), null, [400]);

        // a method that took money keeps its kind
        $s = $this->openSession($w, $w['cashier']);
        $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1]], [['method' => $w['mCard'], 'amount' => '12.01', 'reference' => 'A1']]));
        $this->expectRejected($this->patchAs('admin', "/api/pos/payment-methods/{$w['mCard']}", ['kind' => 'transfer']), 'POS_METHOD_IN_USE', [422]);
        // a method switched off is refused at the counter
        $this->expectOk($this->patchAs('admin', "/api/pos/payment-methods/{$w['mCard']}", ['active' => false]));
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1]], [['method' => $w['mCard'], 'amount' => '12.01', 'reference' => 'A2']]), 'POS_METHOD_INACTIVE', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1]], [['method' => $cash2['id'], 'amount' => '12.01']]), 'POS_METHOD_NOT_ON_REGISTER', [422]);

        // the lists and the lookups
        $lk = $this->expectOk($this->getAs($w['cashier'], '/api/pos/lookups'));
        $this->assertSame([$s['id'], true, false], [$lk['session']['id'], $lk['can']['sell'], $lk['can']['manage']]);
        $this->assertSame('15.00', $lk['vatPct']);
        $list = $this->expectOk($this->getAs($w['cashier'], "/api/pos/registers?q=QPR{$w['tag']}"));
        $this->assertSame([$w['register']], array_column($list['items'], 'id'));
        $this->assertSame($s['number'], $list['items'][0]['openSession']['number']);
        $this->assertSame([], $this->expectOk($this->getAs($w['cashier'], "/api/pos/registers/{$w['register']}"))['notReady']);

        // denominations: the riyal coins and notes are there; a value is there once
        PosBootstrap::ensure();
        $values = PosDenomination::where('active', true)->pluck('value')->all();
        foreach (['0.50', '1.00', '100.00', '500.00'] as $v) {
            $this->assertContains($v, $values);
        }
        $this->expectRejected($this->postAs('admin', '/api/pos/denominations', ['value' => 500, 'kind' => 'note']), 'POS_DENOMINATION_EXISTS', [409]);
        $this->expectRejected($this->postAs($w['cashier'], '/api/pos/denominations', ['value' => 1000, 'kind' => 'note']), null, [403]);
        $this->assertSame(0, DB::table('pos_denominations')->where('value', '1000.00')->count());
    }
}
