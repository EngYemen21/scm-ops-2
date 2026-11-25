<?php

namespace App\Planning\Services;

use App\Planning\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Which products a run is about (its "product selection"):
 *   moving  the products that have demand in the history window (read from the run's own demand table);
 *   manual  the products chosen + every product of the categories chosen and of their sub-categories;
 *   all     every active product.
 * Always in the order of the SKU, so a computation walks them the same way every time.
 */
final class RunScope
{
    /** @return string[] product ids of a manual selection */
    public static function manualProducts(Run $run): array
    {
        $ids = array_map('strval', (array) $run->product_ids);
        $categories = self::withChildren(array_map('strval', (array) $run->category_ids));
        if ($categories) {
            $ids = array_merge($ids, DB::table('products')->whereIn('category_id', $categories)->pluck('id')->map(fn ($v) => (string) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    /** @return string[] the categories and all their descendants */
    public static function withChildren(array $categories): array
    {
        if (! $categories) {
            return [];
        }
        $parents = DB::table('product_categories')->pluck('parent_id', 'id')->all();
        $out = array_fill_keys($categories, true);
        $grew = true;
        while ($grew) {
            $grew = false;
            foreach ($parents as $id => $parent) {
                if ($parent !== null && isset($out[(string) $parent]) && ! isset($out[(string) $id])) {
                    $out[(string) $id] = true;
                    $grew = true;
                }
            }
        }

        return array_keys($out);
    }

    /** @return string[] the products the items of the run are made for, ordered by SKU */
    public static function products(Run $run): array
    {
        $q = DB::table('products');
        if ($run->product_selection === 'manual') {
            $q->whereIn('id', self::manualProducts($run) ?: ['-']);
        } elseif ($run->product_selection === 'all') {
            $q->where('active', true);
        } else {
            $q->whereIn('id', DB::table('planning_demand')->where('run_id', $run->id)->select('product_id')->distinct());
        }

        return $q->orderBy('sku')->orderBy('id')->pluck('id')->map(fn ($v) => (string) $v)->all();
    }
}
