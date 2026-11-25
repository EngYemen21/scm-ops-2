<?php

namespace App\Insights\Services;

use App\Insights\Models\BrandOwner;
use App\Insights\Models\Franchisee;
use Illuminate\Support\Facades\DB;

/**
 * What belongs to one brand owner — computed the same way for every figure, every list and every alert:
 *   products    = the products named on the owner ∪ the products of the categories named on him ∪ the OPS products
 *                 whose `brand` is his brand name (letter case aside);
 *   franchisees = his franchisee rows (every status for the history; the buying ones — active, weak — for the
 *                 targets and the alerts).
 */
class BrandScope
{
    /** @return string[] OPS product ids */
    public function productIds(BrandOwner $o): array
    {
        $explicit = DB::table('ins_brand_owner_products')->where('owner_id', $o->id)->pluck('product_id')->all();
        $categories = DB::table('ins_brand_owner_categories')->where('owner_id', $o->id)->pluck('category_id')->all();
        $ids = $explicit;
        if ($categories) {
            $ids = array_merge($ids, DB::table('products')->whereIn('category_id', $categories)->pluck('id')->all());
        }
        $brand = trim((string) $o->brand_name);
        if ($brand !== '') {
            $ids = array_merge($ids, DB::table('products')->where('brand', $brand)->pluck('id')->all());
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /** @return array<string, Franchisee> by customer id */
    public function franchisees(BrandOwner $o, bool $buyingOnly = false): array
    {
        return Franchisee::with('customer:id,code,name_ar,name_en,city')->where('owner_id', $o->id)
            ->when($buyingOnly, fn ($q) => $q->whereIn('status', Franchisee::BUYING))
            ->orderBy('created_at')->orderBy('id')->get()->keyBy('customer_id')->all();
    }
}
