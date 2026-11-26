<?php

namespace App\Pricing\Services;

use App\Models\Product;
use App\Pricing\Models\ProductListPrice;
use App\Pricing\Support\Cost;
use App\Pricing\Support\Find;
use App\Pricing\Support\Owned;
use App\Pricing\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * The list price of each product — «السعر المعلن»: what a discount rule starts from, and what a customer pays for a
 * product no rule of its list covers. One price per product, audited on the product. A product without one simply has
 * no list price (rules that start from it cannot price the product, and the price check says so).
 */
class ListPricesService
{
    public const BULK_LIMIT = 500;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Products with their list price.
     *
     * @param  array{has?:?string, source?:?string, active?:?string}  $f  has: yes | no · source: odoo | ops · active: all | false (default: active only)
     */
    public function list(AuthUser $viewer, Paging $page, array $f): array
    {
        $query = Product::query()->with('category:id,code,name_ar,name_en')->leftJoin('product_list_prices as lp', 'lp.product_id', '=', 'products.id')->select('products.*');
        match ($f['active'] ?? '') {
            'all' => null,
            'false' => $query->where('products.active', false),
            default => $query->where('products.active', true),
        };
        if (($f['has'] ?? null) === 'yes') {
            $query->whereNotNull('lp.id')->where('lp.price', '>', 0);
        } elseif (($f['has'] ?? null) === 'no') {
            $query->where(fn ($w) => $w->whereNull('lp.id')->orWhere('lp.price', '<=', 0));
        }
        if (($f['source'] ?? null) === 'odoo') {
            $query->where('lp.source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $query->whereNotNull('lp.id')->whereNull('lp.source_system');
        }
        if ($page->q) {
            $like = '%'.$page->q.'%';
            $query->where(fn ($w) => $w->where('products.sku', 'like', $like)->orWhere('products.name_ar', 'like', $like)->orWhere('products.name_en', 'like', $like));
        }
        $sortable = ['sku' => 'products.sku', 'nameAr' => 'products.name_ar', 'price' => 'lp.price', 'updatedAt' => 'lp.updated_at'];
        if (isset($sortable[$page->sort ?? ''])) {
            if (str_starts_with($sortable[$page->sort], 'lp.')) {
                $query->orderByRaw('lp.id IS NULL'); // products without a price last, whatever the direction
            }
            $query->orderBy($sortable[$page->sort], $page->order);
        }
        $query->orderBy('products.sku')->orderBy('products.id');

        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->forPage($page->page, $page->pageSize)->get();
        $prices = ProductListPrice::whereIn('product_id', $rows->pluck('id')->all())->get()->keyBy('product_id');
        $cost = Cost::visible($viewer);

        return $page->wrap($rows->map(function (Product $p) use ($prices, $cost) {
            $lp = $prices[$p->id] ?? null;
            $unitCost = $p->avg_cost !== null ? (float) $p->avg_cost : ($p->purchase_price !== null ? (float) $p->purchase_price : null);

            return Shape::product($p) + ['category' => Shape::category($p->category), 'listPrice' => Shape::listPrice($lp)]
                // what the company pays is shown beside what it asks only to those who may see cost
                + ($cost ? ['cost' => Shape::dec($unitCost), 'marginPct' => $lp && $lp->price > 0 && $unitCost ? round((1 - $unitCost / $lp->price) * 100, 1) : null] : []);
        })->all(), $total) + ['costVisible' => $cost, 'follows' => Owned::following()];
    }

    public function set(AuthUser $user, string $productIdOrSku, float $price, ?string $currency = null): array
    {
        $p = Find::product($productIdOrSku);
        $row = DB::transaction(fn () => $this->write($user, $p, $price, $currency));

        return Shape::product($p) + ['listPrice' => Shape::listPrice($row->refresh())];
    }

    /**
     * Many list prices at once — a pasted sheet of "code, price". All or nothing: an unknown code, a repeated line or a
     * price Odoo owns refuses the whole call and names every faulty line.
     *
     * @param  array<int, array{sku:string, price:float|int|string}>  $items
     * @return array{created:int, updated:int, unchanged:int}
     */
    public function setMany(AuthUser $user, array $items): array
    {
        return DB::transaction(function () use ($user, $items) {
            $products = Product::query()->whereIn('sku', array_values(array_unique(array_map(fn ($i) => (string) $i['sku'], $items))))->get()->keyBy(fn (Product $p) => mb_strtolower($p->sku));
            $existing = ProductListPrice::whereIn('product_id', $products->pluck('id')->all())->lockForUpdate()->get()->keyBy('product_id');
            $errors = [];
            $seen = [];
            foreach ($items as $i => $item) {
                $p = $products[mb_strtolower(trim((string) $item['sku']))] ?? null;
                if (! $p) {
                    $errors[] = ['path' => "items.{$i}.sku", 'message' => "منتج غير معروف: {$item['sku']}"];
                } elseif (isset($seen[$p->id])) {
                    $errors[] = ['path' => "items.{$i}.sku", 'message' => "السطر مكرر: {$item['sku']}"];
                } elseif (Owned::bySource($existing[$p->id] ?? null)) {
                    $errors[] = ['path' => "items.{$i}.sku", 'message' => "السعر المعلن لـ {$item['sku']} يُدار من أودو"];
                }
                if ($p) {
                    $seen[$p->id] = true;
                }
            }
            if ($errors) {
                throw AppError::validation('PRICE_LINES_INVALID', 'في الأسطر أخطاء — لم يُحفظ شيء', 'Some lines are faulty — nothing was saved', $errors);
            }
            $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($items as $item) {
                $p = $products[mb_strtolower(trim((string) $item['sku']))];
                $was = $existing[$p->id] ?? null;
                $price = round((float) $item['price'], 4);
                if ($was && abs($was->price - $price) < 0.00005) {
                    $out['unchanged']++;

                    continue;
                }
                $this->write($user, $p, $price, null, $was);
                $out[$was ? 'updated' : 'created']++;
            }

            return $out;
        });
    }

    /** The product has no list price any more. */
    public function clear(AuthUser $user, string $productIdOrSku): array
    {
        $p = Find::product($productIdOrSku);
        DB::transaction(function () use ($user, $p) {
            $row = ProductListPrice::where('product_id', $p->id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            Owned::refuse($row, 'listPrice');
            $old = ['price' => $row->price, 'currency' => $row->currency];
            $row->delete();
            $this->audit->log($user, ['action' => 'PRICING.LISTPRICE.CLEAR', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'listPrice', 'oldValue' => $old, 'newValue' => null]);
        });

        return Shape::product($p) + ['listPrice' => null];
    }

    private function write(AuthUser $user, Product $p, float $price, ?string $currency, ?ProductListPrice $row = null): ProductListPrice
    {
        if ($price <= 0) {
            throw AppError::validation('PRICE_INVALID', 'السعر يجب أن يكون أكبر من صفر', 'The price must be greater than zero', [['path' => 'price', 'message' => 'أكبر من صفر']]);
        }
        $row ??= ProductListPrice::where('product_id', $p->id)->lockForUpdate()->first();
        Owned::refuse($row, 'listPrice');
        $price = round($price, 4);
        $currency = strtoupper(trim((string) $currency)) ?: ($row?->currency ?? 'SAR');
        $old = $row ? ['price' => $row->price, 'currency' => $row->currency] : null;
        if ($row) {
            if (abs($row->price - $price) < 0.00005 && $row->currency === $currency) {
                return $row;
            }
            $row->update(['price' => $price, 'currency' => $currency, 'updated_by' => $user->username]);
        } else {
            $row = ProductListPrice::create(['product_id' => $p->id, 'price' => $price, 'currency' => $currency, 'created_by' => $user->username]);
        }
        $this->audit->log($user, ['action' => 'PRICING.LISTPRICE.SET', 'entityType' => 'Product', 'entityId' => $p->id, 'entityNumber' => $p->sku, 'field' => 'listPrice', 'oldValue' => $old, 'newValue' => ['price' => $price, 'currency' => $currency]]);

        return $row;
    }
}
