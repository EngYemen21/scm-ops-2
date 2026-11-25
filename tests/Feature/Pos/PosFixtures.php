<?php

namespace Tests\Feature\Pos;

use App\Finance\Models\Account;
use App\Finance\Models\Journal;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Pos\Support\Checkpoint;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The world of the point-of-sale stories — all of it made here, never the demo data (docs/odoo/TEAM-BRIEFS.md rule 13):
 * a warehouse with one shelf bin and a returns bin, products with a fixed price on a price list of their own, stock
 * at a known cost, a walk-in and a named customer, a cash box and a bank account, two payment methods, a register,
 * and cashiers of their own (one open session per cashier). Each story calls `world()` for a fresh one.
 */
trait PosFixtures
{
    private static int $posSeq = 0;

    protected static function tag(): string
    {
        return self::uid().(++self::$posSeq);
    }

    protected function tearDown(): void
    {
        Checkpoint::failAt(null);
        parent::tearDown();
    }

    /**
     * @return array{tag:string, wh:string, shelf:string, ret:string, p1:string, p2:string, p3:string, walkin:string, named:string, cash:string, bank:string, mCash:string, mCard:string, mAcct:string, register:string, cashier:string, cashier2:string, list:string}
     */
    protected function world(array $register = []): array
    {
        $t = self::tag();
        $wh = Warehouse::create(['code' => "QP{$t}", 'name_ar' => "مستودع نقطة بيع {$t}", 'name_en' => "POS WH {$t}", 'active' => true])->id;
        $zone = (string) Str::ulid();
        DB::table('zones')->insert(['id' => $zone, 'warehouse_id' => $wh, 'code' => 'A', 'name_ar' => 'عادي', 'name_en' => 'Ambient', 'type' => 'ambient', 'active' => true]);
        $retZone = (string) Str::ulid();
        DB::table('zones')->insert(['id' => $retZone, 'warehouse_id' => $wh, 'code' => 'RET', 'name_ar' => 'مرتجعات', 'name_en' => 'Returns', 'type' => 'returns', 'active' => true]);
        $shelf = (string) Str::ulid();
        DB::table('bins')->insert(['id' => $shelf, 'warehouse_id' => $wh, 'zone_id' => $zone, 'code' => 'A-01', 'type' => 'shelf', 'status' => 'active']);
        $shelf2 = (string) Str::ulid();
        DB::table('bins')->insert(['id' => $shelf2, 'warehouse_id' => $wh, 'zone_id' => $zone, 'code' => 'A-02', 'type' => 'shelf', 'status' => 'active']);
        $ret = (string) Str::ulid();
        DB::table('bins')->insert(['id' => $ret, 'warehouse_id' => $wh, 'zone_id' => $retZone, 'code' => 'RET-01', 'type' => 'shelf', 'status' => 'active']);

        // three products: prices with four decimals on purpose (rounded once, to the halala, by the sale)
        $product = fn (string $n, string $price) => tap(Product::create(['sku' => "QP{$t}-{$n}", 'name_ar' => "صنف نقطة بيع {$n} {$t}", 'name_en' => "POS item {$n} {$t}", 'storage_class' => 'ambient', 'active' => true]),
            fn ($p) => DB::table('product_barcodes')->insert(['id' => (string) Str::ulid(), 'product_id' => $p->id, 'barcode' => "629{$t}{$n}", 'is_primary' => true]))->id;
        $p1 = $product('1', '10.4350');
        $p2 = $product('2', '3.3333');
        $p3 = $product('3', '7.0000');
        $list = (string) Str::ulid();
        DB::table('price_lists')->insert(['id' => $list, 'code' => "PRL-QP{$t}", 'name' => "قائمة نقطة البيع {$t}", 'currency' => 'SAR', 'is_default' => false, 'active' => true, 'created_at' => now()]);
        foreach ([$p1 => '10.4350', $p2 => '3.3333', $p3 => '7.0000'] as $pid => $price) {
            DB::table('price_list_rules')->insert(['id' => (string) Str::ulid(), 'price_list_id' => $list, 'applies_to' => 'product', 'product_id' => $pid, 'compute' => 'fixed', 'fixed_price' => $price, 'active' => true, 'created_at' => now()]);
        }
        $customer = function (string $name) use ($t, $list) {
            $c = Customer::create(['code' => "QPC{$t}{$name}", 'name_ar' => "عميل {$name} {$t}", 'name_en' => "Customer {$name} {$t}", 'zone' => 'الرياض', 'terms' => 'نقدي', 'credit_limit' => $name === 'N' ? 1000 : 0, 'active' => true]);
            DB::table('price_list_customers')->insert(['id' => (string) Str::ulid(), 'customer_id' => $c->id, 'price_list_id' => $list, 'basis' => 'manual', 'created_at' => now()]);

            return $c->id;
        };
        $walkin = $customer('W');
        $named = $customer('N');

        // stock at a known cost: 50 + 20 on two shelves of product 1, 30 of product 2, none of product 3
        $this->stockIn($p1, $wh, $shelf, 50, 4.0);
        $this->stockIn($p1, $wh, $shelf2, 20, 4.0);
        $this->stockIn($p2, $wh, $shelf, 30, 1.5);

        $bankAccount = fn (string $code, string $kind, string $role) => tap((string) Str::ulid(), fn ($id) => DB::table('fin_bank_accounts')->insert(['id' => $id, 'code' => $code, 'name_ar' => "{$kind} {$code}", 'name_en' => "{$kind} {$code}",
            'kind' => $kind, 'account_id' => Account::where('role', $role)->value('id'), 'currency' => 'SAR', 'active' => true, 'created_at' => now()]));
        $cash = $bankAccount("QPC{$t}", 'cash', 'cash');
        $bank = $bankAccount("QPB{$t}", 'bank', 'bank');

        $mCash = $this->expectOk($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPCASH{$t}", 'nameAr' => 'نقدي', 'nameEn' => 'Cash', 'kind' => 'cash', 'bankAccount' => $cash]))['id'];
        $mCard = $this->expectOk($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPCARD{$t}", 'nameAr' => 'شبكة', 'nameEn' => 'Card', 'kind' => 'card', 'bankAccount' => $bank]))['id'];
        $mAcct = $this->expectOk($this->postAs('admin', '/api/pos/payment-methods', ['code' => "QPACC{$t}", 'nameAr' => 'حساب العميل', 'nameEn' => 'Customer account', 'kind' => 'account']))['id'];
        $reg = $this->expectOk($this->postAs('admin', '/api/pos/registers', $register + [
            'code' => "QPR{$t}", 'nameAr' => "صندوق {$t}", 'warehouse' => $wh, 'customer' => $walkin, 'maxDiscountPct' => 10, 'methods' => [$mCash, $mCard, $mAcct],
            'sellerNameAr' => 'شركة الاختبار', 'sellerNameEn' => 'Test Co', 'sellerVatNo' => '300000000000003', 'refundRestock' => 'shelf', 'refundNeedsManager' => false,
        ]));

        return ['tag' => $t, 'wh' => $wh, 'shelf' => $shelf, 'shelf2' => $shelf2, 'ret' => $ret, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'walkin' => $walkin, 'named' => $named, 'cash' => $cash, 'bank' => $bank,
            'mCash' => $mCash, 'mCard' => $mCard, 'mAcct' => $mAcct, 'register' => $reg['id'], 'cashier' => $this->cashier("qpa{$t}"), 'cashier2' => $this->cashier("qpb{$t}"), 'list' => $list];
    }

    protected function stockIn(string $productId, string $wh, string $bin, int $qty, float $cost): void
    {
        DB::transaction(fn () => app(InventoryService::class)->post(null, (string) Str::uuid(), ['type' => 'opening', 'productId' => $productId, 'qty' => $qty, 'unitCost' => $cost,
            'to' => ['warehouseId' => $wh, 'binId' => $bin], 'reference' => ['type' => 'Opening', 'number' => 'QA-POS']]));
    }

    /** A cashier of its own: the `sales` role (page.pos + pos.sell), signed in with the seed password. */
    protected function cashier(string $username): string
    {
        $id = (string) Str::ulid();
        DB::table('users')->insert(['id' => $id, 'username' => $username, 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => "كاشير {$username}", 'name_en' => "Cashier {$username}",
            'initials' => 'CS', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => DB::table('roles')->where('key', 'sales')->value('id')]);

        return $username;
    }

    /** A point-of-sale manager of its own: the roles `sales` (pos.sell) and `gm` (pos.manage), no warehouse scope. */
    protected function manager(string $username): string
    {
        $this->cashier($username);
        DB::table('user_roles')->insert(['user_id' => DB::table('users')->where('username', $username)->value('id'), 'role_id' => DB::table('roles')->where('key', 'gm')->value('id')]);

        return $username;
    }

    /** Opens a session for `$who` with the given float. @return array the session */
    protected function openSession(array $w, string $who, string $float = '100.00'): array
    {
        return $this->expectOk($this->postAs($who, '/api/pos/sessions', ['register' => $w['register'], 'openingCash' => $float]));
    }

    protected function sell(string $who, string $session, array $lines, array $payments, array $more = []): TestResponse
    {
        return $this->postAs($who, '/api/pos/orders', $more + ['session' => $session, 'clientKey' => 'k-'.Str::random(20), 'lines' => $lines, 'payments' => $payments]);
    }

    protected function onHand(string $productId, string $binId): int
    {
        return (int) DB::table('inventory_balances')->where('product_id', $productId)->where('bin_id', $binId)->sum('on_hand');
    }

    /** Every journal the order caused (by source) is balanced; returns them with their lines. */
    protected function journalsOf(array $order): array
    {
        $ids = array_column($order['journals'], 'id');
        $out = [];
        foreach (Journal::with('lines.account')->whereIn('id', $ids)->get() as $j) {
            $this->assertSame((string) $j->total_debit, (string) $j->total_credit, "journal {$j->number} balances");
            $this->assertEqualsWithDelta((float) $j->lines->sum('debit'), (float) $j->lines->sum('credit'), 0.001);
            $out[] = $j;
        }

        return $out;
    }

    /** Debit − credit of one account role over the given journals. */
    protected function net(array $journals, string $role): string
    {
        $n = 0;
        foreach ($journals as $j) {
            foreach ($j->lines as $l) {
                if ($l->account->role === $role) {
                    $n += (int) round(((float) $l->debit - (float) $l->credit) * 100);
                }
            }
        }

        return number_format($n / 100, 2, '.', '');
    }

    /** What a failed sale must not leave behind — counts of everything a sale writes, and our stock. */
    protected function footprint(array $w): array
    {
        return [
            'orders' => DB::table('pos_orders')->count(), 'lines' => DB::table('pos_order_lines')->count(), 'lots' => DB::table('pos_order_line_lots')->count(), 'payments' => DB::table('pos_order_payments')->count(),
            'invoices' => DB::table('fin_customer_invoices')->count(), 'receipts' => DB::table('fin_payments')->count(), 'journals' => DB::table('fin_journals')->count(),
            'movements' => DB::table('inventory_movements')->count(), 'audit' => DB::table('audit_logs')->where('action', 'like', 'POS.%')->count(), 'activity' => DB::table('activity_logs')->count(),
            'stock' => [$this->onHand($w['p1'], $w['shelf']), $this->onHand($w['p1'], $w['shelf2']), $this->onHand($w['p2'], $w['shelf']), $this->onHand($w['p1'], $w['ret'])],
            'numbers' => DB::table('number_sequences')->whereIn('key', ['POS', 'POSR', 'CI', 'CN', 'RCT', 'JV', 'TX'])->orderBy('key')->pluck('next', 'key')->all(),
            'avg' => Product::whereIn('id', [$w['p1'], $w['p2']])->orderBy('id')->pluck('avg_cost')->all(),
        ];
    }
}
