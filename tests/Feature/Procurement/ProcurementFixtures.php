<?php

namespace Tests\Feature\Procurement;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\AuthUser;

/** Own master data for the procurement tests (the supplier / product APIs belong to other domains). */
trait ProcurementFixtures
{
    private static int $fixtureSeq = 0;

    protected static function code(string $prefix): string
    {
        return $prefix.'-'.self::uid().'-'.(++self::$fixtureSeq);
    }

    protected static function future(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }

    protected function makeSupplier(float $score = 90, array $extra = []): Supplier
    {
        $code = self::code('SUPT');

        return Supplier::create($extra + [
            'code' => $code, 'name_ar' => "مورد اختبار {$code}", 'name_en' => "Test supplier {$code}", 'category' => 'اختبار '.$code, 'category_en' => 'test',
            'lead_days' => 5, 'otif' => 95, 'fill_rate' => 97, 'score' => $score, 'email' => strtolower($code).'@example.test', 'terms' => 'آجل 30 يومًا', 'active' => true,
        ])->refresh();
    }

    protected function makeProduct(float $price = 40, int $reorderMin = 0, array $extra = []): Product
    {
        $sku = self::code('PRC');

        return Product::create($extra + [
            'sku' => $sku, 'name_ar' => "صنف مشتريات {$sku}", 'name_en' => "Procurement item {$sku}", 'storage_class' => 'ambient', 'purchase_price' => $price,
            'reorder_min' => $reorderMin, 'home_warehouse_id' => Warehouse::where('code', 'RYD')->value('id'), 'active' => true,
        ])->refresh();
    }

    protected function linkPreferred(Product $product, Supplier $supplier, float $price, int $leadDays): void
    {
        ProductSupplier::create(['product_id' => $product->id, 'supplier_id' => $supplier->id, 'price' => $price, 'lead_days' => $leadDays, 'preferred' => true]);
    }

    /** A caller that does not exist among the demo users (e.g. can create POs but not approve them). */
    protected function fakeUser(string $username, array $roles, array $permissions): AuthUser
    {
        $u = User::where('username', $username)->firstOrFail();

        return new AuthUser($u->id, $u->username, $u->name_ar, $u->name_en, $roles, $permissions, [], null, 'test-request');
    }

    /** Creates a pending PO through the API and returns its body. */
    protected function makePo(string $supplierCode, string $sku, int $qty, float $price, array $extra = [], string $as = 'proc'): array
    {
        return $this->expectOk($this->postAs($as, '/api/procurement/po', $extra + [
            'supplierCode' => $supplierCode, 'warehouseCode' => 'RYD', 'dueDate' => self::future(7), 'lines' => [['sku' => $sku, 'qty' => $qty, 'price' => $price]],
        ]));
    }
}
