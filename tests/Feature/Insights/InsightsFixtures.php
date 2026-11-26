<?php

namespace Tests\Feature\Insights;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures of the insights stories. Every story builds its own brand, products, customers, users and documents (the
 * tables are shared with every other section's stories — docs/odoo/TEAM-BRIEFS.md rule 13) and asserts on those only.
 */
trait InsightsFixtures
{
    private static int $insSeq = 0;

    protected static function tag(): string
    {
        return self::uid().'-'.(++self::$insSeq);
    }

    /** A signed-in user holding exactly these permissions (a role of its own). */
    protected function makeUser(array $permissions, ?string $username = null): User
    {
        $tag = self::tag();
        $username ??= "ins{$tag}";
        $roleId = (string) Str::ulid();
        DB::table('roles')->insert(['id' => $roleId, 'key' => "qa_ins_{$tag}", 'name_ar' => "دور اختبار {$tag}", 'name_en' => "QA role {$tag}", 'is_system' => false]);
        foreach (DB::table('permissions')->whereIn('key', $permissions)->pluck('id') as $pid) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
        }
        $u = User::create(['username' => $username, 'email' => "{$username}@qa.example", 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => "مستخدم {$tag}",
            'name_en' => "User {$tag}", 'initials' => 'QA', 'active' => true, 'must_change_password' => false]);
        DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => $roleId]);

        return $u;
    }

    protected function makeCustomer(string $name = 'فرع'): Customer
    {
        $tag = self::tag();

        return Customer::create(['code' => "QC-{$tag}", 'name_ar' => "{$name} {$tag}", 'name_en' => "Franchisee {$tag}", 'city' => 'الرياض', 'zone' => 'الوسطى']);
    }

    protected function makeBrandProduct(?string $brand, float $price = 10): Product
    {
        $tag = self::tag();

        return Product::create(['sku' => "QP-{$tag}", 'name_ar' => "منتج {$tag}", 'name_en' => "Product {$tag}", 'brand' => $brand, 'storage_class' => 'ambient', 'weight_kg' => 1,
            'purchase_price' => $price, 'tracks_expiry' => true]);
    }

    /**
     * An OPS customer invoice (or credit note) written straight to the finance tables — the documents the finance
     * reports read. Lines: [product, qty, price, unit cost].
     */
    protected function invoice(Customer $c, string $date, array $lines, string $kind = 'invoice', ?string $due = null, float $paid = 0, string $status = 'issued'): CustomerInvoice
    {
        $tag = self::tag();
        $subtotal = round(array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines)), 2);
        $vat = round($subtotal * 0.15, 2);
        $inv = CustomerInvoice::create(['number' => ($kind === 'invoice' ? 'QCI-' : 'QCN-').$tag, 'kind' => $kind, 'customer_id' => $c->id, 'invoice_date' => $date, 'due_date' => $due ?? $date,
            'status' => $status, 'subtotal' => $subtotal, 'discount' => 0, 'vat_pct' => 15, 'vat_amount' => $vat, 'total' => $subtotal + $vat, 'paid_amount' => $paid,
            'cogs_total' => round(array_sum(array_map(fn ($l) => $l[1] * $l[3], $lines)), 2), 'currency' => 'SAR', 'created_by' => 'qa']);
        foreach (array_values($lines) as $i => [$p, $qty, $price, $cost]) {
            CustomerInvoiceLine::create(['invoice_id' => $inv->id, 'line_no' => $i + 1, 'product_id' => $p->id, 'qty' => $qty, 'price' => $price, 'disc_pct' => 0,
                'line_total' => round($qty * $price, 2), 'unit_cost' => $cost, 'cogs' => round($qty * $cost, 2)]);
        }

        return $inv;
    }

    /** Links an OPS record to an Odoo id, as the master-data projection does. */
    protected function linkOdoo(string $entity, int $odooId, string $opsId): void
    {
        DB::table('int_external_refs')->insert(['id' => (string) Str::ulid(), 'system' => 'odoo', 'entity' => $entity, 'external_id' => (string) $odooId, 'internal_id' => $opsId,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private static int $odooSeq = 0;

    /** A posted Odoo invoice / credit note in the history tables (as OdooHistory writes them). Lines: [productOdooId, qty, amount]. */
    protected function odooInvoice(int $partner, string $date, array $lines, string $type = 'out_invoice', ?string $due = null, float $residual = 0, string $state = 'posted'): int
    {
        $id = 7_000_000 + (int) (self::uid() % 100000) * 10 + (++self::$odooSeq);
        while (DB::table('ins_odoo_invoices')->where('odoo_id', $id)->exists()) {
            $id += 100;
        }
        $sign = $type === 'out_refund' ? -1 : 1;
        $untaxed = round(array_sum(array_column($lines, 2)), 2);
        DB::table('ins_odoo_invoices')->insert(['odoo_id' => $id, 'number' => "INV/QA/{$id}", 'move_type' => $type, 'state' => $state, 'payment_state' => $residual > 0 ? 'not_paid' : 'paid',
            'partner_ref' => (string) $partner, 'invoice_date' => $date, 'due_date' => $due ?? $date, 'amount_untaxed' => $untaxed, 'amount_total' => round($untaxed * 1.15, 2), 'residual' => $residual, 'updated_at' => now()]);
        foreach ($lines as $i => [$product, $qty, $amount]) {
            DB::table('ins_odoo_sale_lines')->insert(['odoo_id' => $id * 10 + $i, 'move_id' => $id, 'move_type' => $type, 'state' => $state, 'invoice_date' => $date, 'partner_ref' => (string) $partner,
                'product_ref' => (string) $product, 'product_name' => "Odoo product {$product}", 'qty' => $sign * $qty, 'amount' => $sign * $amount]);
        }

        return $id;
    }

    protected function setCutover(?string $date): void
    {
        $this->expectOk($this->putAs('admin', '/api/insights/history/cutover', ['date' => $date]));
    }

    /** A brand owner made through the API by `admin` (brandowner.manage), activated, with franchisees. @return array the owner as shown */
    protected function makeOwner(User $user, string $brand, array $franchisees = [], array $flags = []): array
    {
        $o = $this->expectOk($this->postAs('admin', '/api/insights/brands', ['name' => "مالك {$brand}", 'brandName' => $brand, 'userId' => $user->id] + $flags));
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$o['id']}/state", ['to' => 'active']));
        foreach ($franchisees as [$customer, $target, $start]) {
            $this->expectOk($this->postAs('admin', "/api/insights/brands/{$o['id']}/franchisees", ['customerId' => $customer->id, 'monthlyTarget' => $target, 'dateStart' => $start]));
        }

        return $this->expectOk($this->getAs('admin', "/api/insights/brands/{$o['id']}"));
    }
}
