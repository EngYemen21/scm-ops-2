<?php

namespace Tests\Feature\Portal;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Services\LedgerService;
use App\Integration\Models\IntExternalRef;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Sales\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures of the customer-portal tests. Customers, products and receivables are built through the models (always
 * with unique codes); a small copy of Odoo is built by inserting rows into `odoo_models` / `odoo_records` /
 * `odoo_changes` — no Odoo is ever called.
 */
trait PortalFixtures
{
    private static int $portalSeq = 0;

    /** A short unique tag (two calls in one millisecond still differ). */
    protected static function tag(): string
    {
        return self::uid().'-'.(++self::$portalSeq);
    }

    protected function makeCustomer(array $more = []): Customer
    {
        $code = 'CUS-P-'.self::tag();

        return Customer::create($more + ['code' => $code, 'name_ar' => "عميل البوابة {$code}", 'name_en' => "Portal customer {$code}", 'city' => 'الرياض', 'zone' => 'شمال الرياض',
            'terms' => 'نقدي / محفظة', 'credit_limit' => 0, 'balance' => 0, 'contact' => '0500000000']);
    }

    protected function makeProduct(array $more = []): Product
    {
        $sku = 'SKU-P-'.self::tag();

        return Product::create($more + ['sku' => $sku, 'name_ar' => "منتج البوابة {$sku}", 'name_en' => "Portal product {$sku}", 'storage_class' => 'ambient', 'weight_kg' => 1, 'tracks_expiry' => false, 'purchase_price' => 10]);
    }

    protected function makeSite(Customer $c, string $name = 'موقع الفرع'): CustomerSite
    {
        return CustomerSite::create(['customer_id' => $c->id, 'external_key' => 'site-'.self::tag(), 'name' => $name, 'city' => 'الرياض', 'active' => true]);
    }

    /** A sales order header of a customer (optionally delivered to a site) — only what an invoice needs to point at. */
    protected function makeOrder(Customer $c, ?CustomerSite $site = null): SalesOrder
    {
        return SalesOrder::create(['number' => 'SO-P-'.self::tag(), 'customer_id' => $c->id, 'warehouse_id' => Warehouse::where('code', 'RYD')->value('id'), 'date' => now(), 'priority' => 'normal',
            'status' => 'delivered', 'kg' => 0, 'cbm' => 0, 'site_id' => $site?->id]);
    }

    /**
     * What a customer really owes: an issued, unpaid customer invoice with its journal (Dr receivables / Cr revenue),
     * so the receivables sub-ledger still equals the general ledger. Settle it with POST /api/finance/receipts.
     */
    protected function receivable(Customer $customer, float $amount, ?SalesOrder $order = null, ?string $date = null): CustomerInvoice
    {
        return DB::transaction(function () use ($customer, $amount, $order, $date) {
            $invoice = CustomerInvoice::create([
                'number' => 'CI-P-'.self::tag(), 'kind' => 'invoice', 'customer_id' => $customer->id, 'so_id' => $order?->id, 'invoice_date' => $date ?? now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(), 'status' => 'issued', 'subtotal' => $amount, 'vat_pct' => 0, 'vat_amount' => 0, 'total' => $amount, 'paid_amount' => 0, 'created_by' => 'test',
            ]);
            $ref = ['referenceType' => 'CustomerInvoice', 'referenceId' => $invoice->id];
            $journal = $this->app->make(LedgerService::class)->post(null, [
                'eventKey' => "ci:{$invoice->id}", 'date' => $date, 'source' => ['type' => 'CustomerInvoice', 'id' => $invoice->id, 'number' => $invoice->number], 'memo' => "فاتورة اختبار {$invoice->number}",
                'lines' => [['role' => 'ar', 'debit' => $amount, 'partnerType' => 'customer', 'partnerId' => $customer->id] + $ref, ['role' => 'revenue', 'credit' => $amount] + $ref],
            ]);
            $invoice->update(['journal_id' => $journal->id]);
            $this->app->make(CreditService::class)->sync($customer->id);

            return $invoice->refresh();
        });
    }

    /** The section follows Odoo (records that came from Odoo are Odoo's) or is handed over to OPS. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.portal' => $on]);
    }

    /**
     * A signed-in user who works the portal and nothing else: page.portal + portal.manage, without customer.manage,
     * sales.manage or report.export. @return string the username
     */
    protected function portalOnlyUser(): string
    {
        $username = 'portalonly';
        if (! DB::table('users')->where('username', $username)->exists()) {
            $role = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $role, 'key' => 'portalonly', 'name_ar' => 'بوابة العملاء فقط', 'name_en' => 'Portal only', 'is_system' => false]);
            foreach (DB::table('permissions')->whereIn('key', ['page.portal', 'portal.manage'])->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            $user = (string) Str::ulid();
            DB::table('users')->insert(['id' => $user, 'username' => $username, 'password_hash' => Hash::make((string) env('SEED_PASSWORD')), 'name_ar' => 'موظف البوابة', 'name_en' => 'Portal clerk',
                'initials' => 'PC', 'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['user_id' => $user, 'role_id' => $role]);
        }

        return $username;
    }

    // ───────────── a small copy of Odoo ─────────────

    /** Empties the copy and the portal tables, so a story starts from nothing. */
    protected function emptyMirror(): void
    {
        foreach (['odoo_changes', 'odoo_records', 'odoo_models'] as $table) {
            DB::table($table)->delete();
        }
        foreach (['portal_events', 'portal_statement_lines', 'portal_statements', 'portal_line_changes', 'portal_order_request_lines', 'portal_order_requests',
            'portal_application_documents', 'portal_applications', 'portal_favorite_items', 'portal_favorite_lists', 'portal_branches'] as $table) {
            DB::table($table)->delete();
        }
        IntExternalRef::where('system', 'odoo')->delete();
    }

    /** Registers models as copied completely (what `ready()` asks for). @param  string[]  $models */
    protected function mirrorModels(array $models): void
    {
        foreach ($models as $model) {
            if (! DB::table('odoo_models')->where('model', $model)->exists()) {
                DB::table('odoo_models')->insert(['model' => $model, 'label' => $model, 'status' => 'active', 'full_state' => 'done', 'has_write_date' => true, 'has_active' => false,
                    'fields' => '{}', 'priority' => 1000, 'page_size' => 500, 'mirrored_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * One record of the copy, as Odoo returned it. Written again it is the same record with new values (and a line of
     * the change feed when `$feed` is true — what a synchronisation run would have written).
     *
     * @param  array{active?:?bool, deleted?:bool, name?:?string, write_date?:string}  $opts
     */
    protected function mirror(string $model, int $id, array $data, array $opts = [], bool $feed = false): void
    {
        $this->mirrorModels([$model]);
        $row = [
            'display_name' => $opts['name'] ?? (is_string($data['name'] ?? null) ? $data['name'] : "#{$id}"), 'write_date' => $opts['write_date'] ?? now()->utc()->format('Y-m-d H:i:s'),
            'active' => $opts['active'] ?? null, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'hash' => sha1(json_encode($data)), 'changed_at' => now(),
            'deleted_at' => ! empty($opts['deleted']) ? now() : null,
        ];
        $exists = DB::table('odoo_records')->where('model', $model)->where('odoo_id', $id)->exists();
        $exists ? DB::table('odoo_records')->where('model', $model)->where('odoo_id', $id)->update($row)
            : DB::table('odoo_records')->insert($row + ['model' => $model, 'odoo_id' => $id, 'version' => 1, 'first_seen_at' => now()]);
        if ($feed) {
            DB::table('odoo_changes')->insert(['model' => $model, 'odoo_id' => $id, 'kind' => ! empty($opts['deleted']) ? 'deleted' : ($exists ? 'updated' : 'created'), 'seen_at' => now()]);
        }
    }

    /** Odoo's partner `$odooId` IS this OPS customer (what the master-data projection records). */
    protected function linkCustomer(int $odooId, Customer $c): void
    {
        IntExternalRef::create(['system' => 'odoo', 'entity' => 'customer', 'external_id' => (string) $odooId, 'internal_id' => $c->id, 'internal_code' => $c->code]);
    }

    protected function linkProduct(int $odooId, Product $p): void
    {
        IntExternalRef::create(['system' => 'odoo', 'entity' => 'product', 'external_id' => (string) $odooId, 'internal_id' => $p->id, 'internal_code' => $p->sku]);
    }
}
