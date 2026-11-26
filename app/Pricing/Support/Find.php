<?php

namespace App\Pricing\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Pricing\Models\PriceList;
use App\Support\AppError;

/** Looks records up by id or by the code people type (customer code, SKU, supplier code, list code); 404 with a typed code otherwise. */
final class Find
{
    public static function customer(string $idOrCode): Customer
    {
        return Customer::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', "العميل {$idOrCode} غير موجود", "Customer {$idOrCode} not found");
    }

    public static function product(string $idOrSku): Product
    {
        return Product::where('id', $idOrSku)->orWhere('sku', $idOrSku)->first()
            ?? throw AppError::notFound('PRODUCT_NOT_FOUND', "المنتج {$idOrSku} غير موجود", "Product {$idOrSku} not found");
    }

    public static function supplier(string $idOrCode): Supplier
    {
        return Supplier::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', "المورد {$idOrCode} غير موجود", "Supplier {$idOrCode} not found");
    }

    public static function category(string $idOrCode): ProductCategory
    {
        return ProductCategory::where('id', $idOrCode)->orWhere('code', $idOrCode)->first()
            ?? throw AppError::notFound('CATEGORY_NOT_FOUND', "الفئة {$idOrCode} غير موجودة", "Category {$idOrCode} not found");
    }

    public static function list(string $idOrCode, bool $lock = false): PriceList
    {
        return PriceList::where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('PRICE_LIST_NOT_FOUND', 'قائمة الأسعار غير موجودة', 'Price list not found');
    }
}
