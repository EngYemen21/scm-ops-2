<?php

namespace App\Pos\Services;

use App\Models\Product;
use App\Pos\Models\PosRegister;
use App\Pos\Support\Money;
use App\Pricing\Services\PriceService;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * What the selling screen looks up: products by name, SKU or barcode, each with the price the customer pays (price
 * service) and what the register's warehouse can sell now (allocatable stock — the same rows a sale takes from).
 * A scan is an exact match: a barcode, else a SKU; one product or a clear "unknown code".
 */
class CatalogService
{
    public function __construct(private readonly PriceService $prices, private readonly InventoryService $inventory, private readonly SaleService $sales) {}

    public function search(Paging $page, string $registerId, ?string $customerId): array
    {
        $register = $this->register($registerId);
        $customer = $this->sales->customer($register, $customerId);
        $q = Product::query()->where('active', true);
        if ($page->q) {
            $like = "%{$page->q}%";
            $codes = DB::table('product_barcodes')->where('barcode', $page->q)->pluck('product_id')->all();
            $q->where(fn ($w) => $w->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('brand', 'like', $like)->orWhereIn('id', $codes ?: ['-']));
        }
        $column = match ($page->sort) {
            'name' => 'name_ar', default => 'sku'
        };
        $q->orderBy($column, $page->order === 'desc' && $page->sort ? 'desc' : 'asc')->orderBy('id');
        $result = $page->paginate($q);
        $result['items'] = $this->enrich($register, $customer->id, $result['items']);

        return $result + ['customer' => ['id' => $customer->id, 'code' => $customer->code, 'nameAr' => $customer->name_ar, 'nameEn' => $customer->name_en]];
    }

    public function scan(string $registerId, string $code, ?string $customerId): array
    {
        $register = $this->register($registerId);
        $customer = $this->sales->customer($register, $customerId);
        $code = trim($code);
        $ids = DB::table('product_barcodes')->where('barcode', $code)->pluck('product_id')->unique()->values()->all();
        if (! $ids) {
            $ids = Product::where('sku', $code)->pluck('id')->all();
        }
        if (count($ids) !== 1) {
            throw AppError::notFound('POS_SCAN_UNKNOWN', "الرمز {$code} لا يطابق صنفًا واحدًا", "Code {$code} does not match exactly one product", ['code' => $code, 'matches' => count($ids)]);
        }
        $p = Product::find($ids[0]);
        if (! $p || ! $p->active) {
            throw AppError::rule('POS_PRODUCT_INACTIVE', "الصنف {$code} موقوف — لا يُباع", "Product {$code} is inactive");
        }

        return $this->enrich($register, $customer->id, [$p])[0];
    }

    /** @param  iterable<Product>  $products */
    private function enrich(PosRegister $register, string $customerId, iterable $products): array
    {
        $list = collect($products)->values();
        if ($list->isEmpty()) {
            return [];
        }
        $quotes = $this->prices->customerPrices($customerId, $list->map(fn (Product $p) => ['productId' => $p->id, 'qty' => 1])->all());
        $barcodes = DB::table('product_barcodes')->whereIn('product_id', $list->pluck('id'))->orderByDesc('is_primary')->get(['product_id', 'barcode'])->groupBy('product_id');

        return $list->map(function (Product $p, int $i) use ($quotes, $register, $barcodes) {
            $q = $quotes[$i] ?? [];
            // the same rounding as the sale (PosPricing): the screen never shows a price the sale would not charge
            $price = ($q['found'] ?? false) && $q['price'] !== null ? Money::str(Money::cents($q['price'])) : null;

            return [
                'id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'brand' => $p->brand,
                'barcode' => $barcodes[$p->id][0]->barcode ?? null,
                'price' => $price, 'priceReason' => $q['reason'] ?? null,
                'available' => $register->warehouse_id ? $this->inventory->availability($p->id, $register->warehouse_id)['total'] : 0,
            ];
        })->all();
    }

    private function register(string $id): PosRegister
    {
        return PosRegister::where('id', $id)->orWhere('code', $id)->first() ?? throw AppError::notFound('POS_REGISTER_NOT_FOUND', 'الصندوق غير موجود', 'Register not found');
    }
}
