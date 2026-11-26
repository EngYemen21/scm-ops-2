<?php

namespace Tests\Feature\Pos;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Product;
use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderLine;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\OdooPosProjection;
use App\Pos\PosBootstrap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's Point of Sale to the section (OdooPosProjection). The copy is built here by hand in the
 * shapes the real copy holds (pos.config, pos.payment.method, pos.session, pos.order, pos.order.line, pos.payment,
 * pos.bill — read from `scm_ops`); Odoo is never called. Proven: the plan writes nothing; the apply makes every record
 * once, linked by identity; a second pass changes nothing; Odoo's records are history and settings Odoo owns.
 */
class OdooPosProjectionTest extends ApiTestCase
{
    use PosFixtures;

    private const MODELS = ['pos.bill', 'pos.payment.method', 'pos.config', 'pos.session', 'pos.order', 'pos.order.line', 'pos.payment'];

    private string $t;

    private string $productId;

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        // what an earlier story left of this section's copy and of its projection (Odoo's records only)
        OdooRecord::whereIn('model', self::MODELS)->delete();
        OdooChange::whereIn('model', self::MODELS)->delete();
        DB::table('odoo_projections')->where('key', 'pos')->delete();
        $orders = PosOrder::where('source_system', 'odoo')->pluck('id');
        PosOrderLine::whereIn('order_id', $orders)->delete();
        PosOrderPayment::whereIn('order_id', $orders)->delete();
        PosOrder::whereIn('id', $orders)->delete();
        PosSession::where('source_system', 'odoo')->delete();
        DB::table('pos_register_methods')->whereIn('register_id', PosRegister::where('source_system', 'odoo')->pluck('id'))->delete();
        PosRegister::where('source_system', 'odoo')->delete();
        PosPaymentMethod::where('source_system', 'odoo')->delete();
        // the riyal denominations an earlier pass adopted go back to being OPS's own; the ones Odoo alone had go
        PosDenomination::where('source_system', 'odoo')->whereIn('value', array_keys(PosBootstrap::SAR))->update(['source_system' => null, 'external_ref' => null]);
        PosDenomination::where('source_system', 'odoo')->delete();
        PosBootstrap::ensure();
        config(['odoo.enabled' => true, 'odoo.project.pos' => true]);

        $this->t = $t = self::tag();
        foreach (self::MODELS as $model) {
            OdooModel::updateOrCreate(['model' => $model], ['status' => 'active', 'full_state' => 'done', 'has_active' => in_array($model, ['pos.config', 'pos.payment.method'], true)]);
        }
        $this->productId = Product::create(['sku' => "QPO{$t}", 'name_ar' => "طحين {$t}", 'name_en' => "Flour {$t}", 'storage_class' => 'ambient', 'active' => true])->id;
        $this->customerId = Customer::create(['code' => "QPOC{$t}", 'name_ar' => "شاهي نجد {$t}", 'name_en' => 'Najd tea', 'zone' => 'الرياض', 'terms' => 'نقدي', 'active' => true, 'source_system' => 'odoo'])->id;
        $refs = $this->app->make(ExternalRefs::class);
        DB::table('int_external_refs')->where('system', 'odoo')->whereIn('external_id', ['910568', '9695'])->delete();
        $refs->link('odoo', 'product', '910568', $this->productId, "QPO{$t}");
        $refs->link('odoo', 'customer', '9695', $this->customerId, "QPOC{$t}");
        $this->cashier("qpo{$t}");

        // ── people
        $this->rec('res.users', 9106, ['login' => "QPO{$t}", 'partner_id' => [99106, 'مندوب أول']]);
        $this->rec('res.users', 9107, ['login' => "nobody{$t}@corp.example", 'partner_id' => [99107, 'مندوب ثان']]);
        // ── settings (the real shapes)
        $this->rec('pos.bill', 1, ['name' => '0.01', 'value' => 0.01]);
        $this->rec('pos.bill', 2, ['name' => '1.00', 'value' => 1.0]);
        $this->rec('pos.payment.method', 1, ['name' => 'نقدي', 'sequence' => 0, 'journal_id' => [21, 'نقدي - نقطة البيع'], 'is_cash_count' => true, 'split_transactions' => false], true);
        $this->rec('pos.payment.method', 2, ['name' => 'شبكة', 'sequence' => 1, 'journal_id' => [22, 'شبكة نقطة البيع'], 'is_cash_count' => false, 'split_transactions' => false], true);
        $this->rec('pos.payment.method', 3, ['name' => 'حساب العميل', 'sequence' => 2, 'journal_id' => false, 'is_cash_count' => true, 'split_transactions' => true], true);
        $this->rec('pos.config', 1, ['name' => 'Shop', 'warehouse_id' => [1, 'Riyadh warehouse'], 'journal_id' => [20, 'نقطة البيع'], 'manual_discount' => true, 'payment_method_ids' => [1, 2, 3],
            'iface_tax_included' => 'total', '_nf_enable_a4_size_receipt' => true, 'picking_type_id' => [6, 'Riyadh warehouse: PoS Orders']], true);
        // ── history
        $this->rec('pos.session', 1, ['name' => "POS/T{$t}1", 'state' => 'closed', 'config_id' => [1, 'Shop'], 'user_id' => [9106, 'مندوب أول'], 'start_at' => '2025-02-18 10:22:55', 'stop_at' => '2025-02-23 10:46:46',
            'cash_register_balance_start' => 0, 'cash_register_balance_end_real' => 23.0, 'cash_journal_id' => [21, 'نقدي - نقطة البيع']]);
        $this->rec('pos.session', 2, ['name' => "POS/T{$t}2", 'state' => 'closed', 'config_id' => [1, 'Shop'], 'user_id' => [9107, 'مندوب ثان'], 'start_at' => '2025-03-01 08:00:00', 'stop_at' => '2025-03-01 18:00:00',
            'cash_register_balance_start' => 50.0, 'cash_register_balance_end_real' => 38.5]);
        $order = fn (int $id, int $session, array $more) => $this->rec('pos.order', $id, $more + ['name' => "Shop/T{$t}{$id}", 'state' => 'done', 'config_id' => [1, 'Shop'], 'session_id' => [$session, 'POS'],
            'user_id' => [$session === 1 ? 9106 : 9107, 'x'], 'cashier' => 'مندوب أول', 'date_order' => '2025-02-24 13:17:50', 'pos_reference' => "طلب {$id}", 'ticket_code' => "tc{$id}", 'amount_return' => 0]);
        $order(1, 1, ['partner_id' => [9695, 'شاهي نجد'], 'amount_total' => 115.0, 'amount_tax' => 15.0, 'amount_paid' => 115.0]);
        $order(2, 1, ['partner_id' => [9696, 'عميل كاش'], 'amount_total' => 23.0, 'amount_tax' => 3.0, 'amount_paid' => 30.0, 'amount_return' => 7.0]);
        $order(3, 2, ['partner_id' => false, 'amount_total' => -11.5, 'amount_tax' => -1.5, 'amount_paid' => -11.5]);
        $line = fn (int $id, int $order, array $more) => $this->rec('pos.order.line', $id, $more + ['order_id' => [$order, 'Shop'], 'discount' => 0, 'tax_ids' => [1]]);
        $line(1, 1, ['product_id' => [910568, "[P00713] طحين {$t}"], 'full_product_name' => "طحين {$t}", 'qty' => 10.0, 'price_unit' => 10.0, 'price_subtotal' => 100.0, 'price_subtotal_incl' => 115.0, 'total_cost' => 40.0]);
        $line(2, 2, ['product_id' => [910568, "[P00713] طحين {$t}"], 'full_product_name' => "طحين {$t}", 'qty' => 1.0, 'price_unit' => 10.0, 'price_subtotal' => 10.0, 'price_subtotal_incl' => 11.5, 'total_cost' => 4.0]);
        $line(3, 2, ['product_id' => [999999, '[P99999] سكر غير مربوط'], 'full_product_name' => 'سكر غير مربوط', 'qty' => 2.0, 'price_unit' => 5.0, 'price_subtotal' => 10.0, 'price_subtotal_incl' => 11.5, 'total_cost' => 6.0]);
        $line(4, 3, ['product_id' => [910568, "[P00713] طحين {$t}"], 'full_product_name' => "طحين {$t}", 'qty' => -1.0, 'price_unit' => 10.0, 'price_subtotal' => -10.0, 'price_subtotal_incl' => -11.5, 'total_cost' => -4.0]);
        $pay = fn (int $id, int $order, int $method, float $amount, bool $change = false) => $this->rec('pos.payment', $id, ['pos_order_id' => [$order, 'Shop'], 'payment_method_id' => [$method, 'm'], 'amount' => $amount, 'is_change' => $change, 'payment_date' => '2025-02-24 13:17:50']);
        $pay(1, 1, 2, 115.0);
        $pay(2, 2, 1, 30.0);
        $pay(3, 2, 1, -7.0, true);
        $pay(4, 3, 1, -11.5);
    }

    private function rec(string $model, int $id, array $data, ?bool $active = null): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2025-03-02 08:00:00'];
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => now(), 'changed_at' => now(), 'deleted_at' => null]);
    }

    private function projection(): OdooPosProjection
    {
        return $this->app->make(OdooPosProjection::class);
    }

    private function tables(): array
    {
        return [PosDenomination::count(), PosPaymentMethod::count(), PosRegister::count(), PosSession::count(), PosOrder::count(), PosOrderLine::count(), PosOrderPayment::count(),
            DB::table('audit_logs')->count(), DB::table('users')->count()];
    }

    public function test_the_plan_writes_nothing_the_apply_loads_the_history_once_and_odoo_owns_what_came_from_it(): void
    {
        $p = $this->projection();
        $this->assertSame(['pos', true], [$p->key(), $p->ready()]);
        $this->assertSame(self::MODELS, $p->models());
        $before = $this->tables();
        $expected = [
            'denominations.created' => 1, 'denominations.linked' => 1, 'methods.created' => 3, 'registers.created' => 1, 'registers.noWarehouse' => 1, 'sessions.created' => 2,
            'people.matched' => 1, 'people.byName' => 1, 'orders.created' => 3, 'lines.created' => 4, 'lines.withProduct' => 3, 'lines.productNotLinked' => 1, 'payments.created' => 4,
        ];
        $plan = $p->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertEquals($expected, $plan['counts']);
        $this->assertSame($before, $this->tables(), 'a plan writes nothing');

        $applied = $p->apply();
        $this->assertEquals($expected, $applied['counts']);

        // settings
        $reg = PosRegister::with('methods')->where('source_system', 'odoo')->where('external_ref', '1')->firstOrFail();
        $this->assertSame(['ODOO-1', 'Shop', null, '100.00', 'a4', 3, 'Riyadh warehouse'], [$reg->code, $reg->name_en, $reg->warehouse_id, $reg->max_discount_pct, $reg->receipt_format, $reg->methods->count(), $reg->extra['warehouse']]);
        $this->assertSame(['cash', 'card', 'account'], PosPaymentMethod::where('source_system', 'odoo')->orderBy('external_ref')->pluck('kind')->all());
        $this->assertSame(1, PosDenomination::where('value', '1.00')->count(), 'the riyal coin OPS already had is adopted, not doubled');
        $this->assertSame('odoo', PosDenomination::where('value', '1.00')->value('source_system'));

        // history, value by value
        $s1 = PosSession::where('source_system', 'odoo')->where('external_ref', '1')->firstOrFail();
        $this->assertSame(["POS/T{$this->t}1", 'closed', DB::table('users')->where('username', "qpo{$this->t}")->value('id'), '23.00', $reg->id], [$s1->number, $s1->state, $s1->cashier_id, $s1->counted_cash, $s1->register_id]);
        $s2 = PosSession::where('source_system', 'odoo')->where('external_ref', '2')->firstOrFail();
        $this->assertSame([null, 'مندوب ثان', '50.00'], [$s2->cashier_id, $s2->cashier_name, $s2->opening_cash], 'no OPS user answers: the name Odoo gives is kept');
        $o1 = PosOrder::where('source_system', 'odoo')->where('external_ref', '1')->firstOrFail();
        $this->assertSame(['sale', '100.00', '15.00', '115.00', '15.00', $this->customerId, $s1->id], [$o1->kind, $o1->subtotal, $o1->vat_amount, $o1->total, $o1->vat_pct, $o1->customer_id, $o1->session_id]);
        $o2 = PosOrder::where('source_system', 'odoo')->where('external_ref', '2')->firstOrFail();
        $this->assertSame([null, 'عميل كاش', '30.00', '7.00'], [$o2->customer_id, $o2->customer_name, $o2->tendered, $o2->change_due]);
        $o3 = PosOrder::where('source_system', 'odoo')->where('external_ref', '3')->firstOrFail();
        $this->assertSame(['refund', '11.50', '1.50'], [$o3->kind, $o3->total, $o3->vat_amount]);
        $lines = PosOrderLine::where('order_id', $o2->id)->orderBy('line_no')->get();
        $this->assertSame([[$this->productId, 'P00713', '1.000', '10.00'], [null, 'P99999', '2.000', '10.00']], $lines->map(fn ($l) => [$l->product_id, $l->sku, $l->qty, $l->line_total])->all());
        $this->assertSame([['cash', '30.00'], ['cash', '-7.00']], PosOrderPayment::where('order_id', $o2->id)->orderBy('line_no')->get()->map(fn ($p) => [$p->kind, $p->amount])->all());
        $report = $this->expectOk($this->getAs('admin', "/api/pos/sessions/{$s1->id}/report"));
        $this->assertSame([2, '138.00', true], [$report['sales']['count'], $report['sales']['total'], $report['session']['history']]);
        $this->assertSame(3, $this->expectOk($this->getAs('admin', '/api/pos/orders?source=odoo'))['total']);

        // a second pass changes nothing
        $again = $p->apply()['counts'];
        $this->assertSame([], array_filter($again, fn ($n, $k) => ! str_ends_with($k, '.unchanged') && ! in_array($k, ['registers.noWarehouse', 'lines.withProduct', 'lines.productNotLinked', 'people.matched', 'people.byName'], true), ARRAY_FILTER_USE_BOTH));
        $this->assertSame($this->tables()[0], PosDenomination::count());

        // Odoo's records: settings are Odoo's while the section follows it; history is never sold in or refunded
        $this->expectRejected($this->patchAs('admin', "/api/pos/registers/{$reg->id}", ['maxDiscountPct' => 5]), 'POS_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', '/api/pos/sessions', ['register' => $reg->id]), 'POS_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/pos/orders/{$o1->id}/refund", ['session' => $s1->id, 'clientKey' => 'r-'.Str::random(20), 'reason' => 'استرجاع تاريخي',
            'lines' => [['lineId' => PosOrderLine::where('order_id', $o1->id)->value('id'), 'qty' => 1]], 'payments' => [['method' => 'x', 'amount' => 1]]]), 'POS_HISTORY_ONLY', [422]);
        $this->assertFalse($this->expectOk($this->getAs('admin', "/api/pos/orders/{$o1->id}"))['refundable']);
        config(['odoo.project.pos' => false]);   // handed over: the settings are OPS's to change
        $this->expectOk($this->patchAs('admin', "/api/pos/registers/{$reg->id}", ['maxDiscountPct' => 5]));
        config(['odoo.project.pos' => true]);

        // what changes in Odoo reaches the section through the feed: a method deleted there is switched off here
        OdooRecord::where('model', 'pos.payment.method')->where('odoo_id', 3)->update(['deleted_at' => now()]);
        OdooChange::create(['model' => 'pos.payment.method', 'odoo_id' => 3, 'kind' => 'deleted', 'seen_at' => now()]);
        $fed = $p->consume();
        $this->assertSame(['methods.archived' => 1], $fed['counts']);
        $this->assertFalse(PosPaymentMethod::where('source_system', 'odoo')->where('external_ref', '3')->value('active'));
    }
}
