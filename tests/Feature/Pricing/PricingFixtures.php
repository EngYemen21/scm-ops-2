<?php

namespace Tests\Feature\Pricing;

use App\Integration\Odoo\Models\OdooChange;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Pricing\Models\PriceList;
use App\Pricing\Models\ProductListPrice;
use Illuminate\Support\Carbon;

/**
 * What the stories of the price lists section share: master data of their own (never the demo data's), the switch
 * "the section follows Odoo", and a copy of Odoo built by hand — rows of `odoo_models` / `odoo_records` shaped like the
 * ones the mirror writes, with the change feed. Odoo itself is never called.
 */
trait PricingFixtures
{
    /** The section follows Odoo (records that came from Odoo are read-only) or was handed over. */
    protected function followOdoo(bool $on): void
    {
        config(['odoo.enabled' => $on, 'odoo.project.pricing' => $on]);
    }

    protected function product(string $name, array $more = []): Product
    {
        $sku = 'PRC-'.self::uid().'-'.substr(md5($name.microtime()), 0, 4);

        return Product::create($more + ['sku' => strtoupper($sku), 'name_ar' => $name, 'name_en' => $name, 'active' => true]);
    }

    protected function customer(string $name, array $more = []): Customer
    {
        return Customer::create($more + ['code' => 'CUS-P'.self::uid().substr(md5($name.microtime()), 0, 3), 'name_ar' => $name, 'name_en' => $name, 'zone' => 'الرياض', 'contact' => '0550000000', 'terms' => 'نقدي / محفظة', 'active' => true]);
    }

    protected function supplier(string $name, array $more = []): Supplier
    {
        return Supplier::create($more + ['code' => 'SUP-P'.self::uid().substr(md5($name.microtime()), 0, 3), 'name_ar' => $name, 'name_en' => $name, 'lead_days' => 5, 'score' => 80, 'status' => 'active', 'active' => true]);
    }

    protected function category(string $name, ?string $parentId = null): ProductCategory
    {
        return ProductCategory::create(['code' => 'pc-'.self::uid().substr(md5($name.microtime()), 0, 4), 'name_ar' => $name, 'name_en' => $name, 'parent_id' => $parentId, 'active' => true]);
    }

    /** The list price of a product, written the way the service would. */
    protected function listPriceOf(Product $p, float $price, string $currency = 'SAR'): void
    {
        ProductListPrice::updateOrCreate(['product_id' => $p->id], ['price' => $price, 'currency' => $currency, 'created_by' => 'test']);
    }

    /** No list is the default: a story that needs one makes its own. */
    protected function noDefaultList(): void
    {
        PriceList::where('is_default', true)->update(['is_default' => false]);
    }

    // ───────────── the copy of Odoo ─────────────

    /** A model the copy holds completely. */
    protected function odooModel(string $model, bool $hasActive = false, string $state = 'done'): void
    {
        OdooModel::updateOrCreate(['model' => $model], ['status' => 'active', 'full_state' => $state, 'has_active' => $hasActive]);
    }

    /** One record of the copy, as the mirror would have written it `$ago` seconds ago. */
    protected function rec(string $model, int $id, array $data, ?bool $active = null, int $ago = 7200): void
    {
        $data = ($active === null ? [] : ['active' => $active]) + $data + ['write_date' => '2026-09-01 08:00:00'];
        $at = Carbon::now()->subSeconds($ago);
        OdooRecord::updateOrCreate(['model' => $model, 'odoo_id' => $id], ['display_name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'write_date' => $data['write_date'], 'active' => $active,
            'data' => $data, 'hash' => sha1(json_encode($data)), 'first_seen_at' => $at, 'changed_at' => $at, 'deleted_at' => null]);
    }

    /** What changes in Odoo: the record of the copy is rewritten now and — for a model the section follows by the feed — the feed says so. */
    protected function odooWrites(string $model, int $id, array $values, bool $feed = true, ?bool $active = null): void
    {
        $current = OdooRecord::where('model', $model)->where('odoo_id', $id)->first();
        $this->rec($model, $id, $values + ['write_date' => '2026-09-10 11:00:00'] + (array) ($current?->data ?? []), $active ?? $current?->active, 0);
        if ($feed) {
            OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => $current ? 'updated' : 'created', 'seen_at' => now()]);
        }
    }

    /** A record disappears from Odoo: the copy keeps it, marked deleted. */
    protected function odooDeletes(string $model, int $id, bool $feed = true): void
    {
        OdooRecord::where('model', $model)->where('odoo_id', $id)->update(['deleted_at' => now(), 'changed_at' => now()]);
        if ($feed) {
            OdooChange::create(['model' => $model, 'odoo_id' => $id, 'kind' => 'deleted', 'seen_at' => now()]);
        }
    }
}
