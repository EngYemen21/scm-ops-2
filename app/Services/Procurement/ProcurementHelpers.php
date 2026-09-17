<?php

namespace App\Services\Procurement;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Shared lookups / small utilities for the procurement module. */
final class ProcurementHelpers
{
    /** Columns of the product projection used by PR / RFQ / quotation documents. */
    public const PRODUCT_PRICE_SEL = 'product:id,sku,name_ar,name_en,purchase_price';

    public static function round2(float|int $n): float
    {
        return round($n * 100) / 100;
    }

    /** The approver must hold the step's role; `super` passes every step. */
    public static function hasRole(AuthUser $user, string $roleKey): bool
    {
        return $user->hasRole('super', $roleKey);
    }

    public static function parseDate(?string $s): ?Carbon
    {
        return $s ? Carbon::parse($s) : null;
    }

    public static function dayStart(?Carbon $d = null): Carbon
    {
        return ($d ? $d->copy() : now())->startOfDay();
    }

    /** YYYY-MM-DD of today + $days. */
    public static function isoDatePlus(int $days): string
    {
        return self::dayStart()->addDays($days)->toDateString();
    }

    /** true when a date has fully elapsed (strictly before today). */
    public static function isPastDate(string|Carbon $s): bool
    {
        $d = is_string($s) ? Carbon::parse(substr($s, 0, 10)) : $s->copy();

        return $d->startOfDay()->lt(self::dayStart());
    }

    /** Split a comma-separated filter ("a,b") into its values. */
    public static function csv(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    public static function findSupplier(string $code, bool $mustBeActive = true): Supplier
    {
        $s = Supplier::where('code', $code)->orWhere('id', $code)->first()
            ?? throw AppError::notFound('SUPPLIER_NOT_FOUND', "المورد {$code} غير موجود", "Supplier {$code} not found");
        if ($mustBeActive && ! $s->active) {
            throw AppError::rule('SUPPLIER_INACTIVE', "المورد {$s->name_ar} غير نشط", "Supplier {$s->name_en} is inactive");
        }

        return $s;
    }

    public static function findWarehouse(string $code): Warehouse
    {
        $w = Warehouse::where('code', strtoupper($code))->orWhere('id', $code)->first()
            ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$code} غير موجود", "Warehouse {$code} not found");
        if (! $w->active) {
            throw AppError::rule('WAREHOUSE_INACTIVE', "المستودع {$w->name_ar} غير نشط", "Warehouse {$w->name_en} is inactive");
        }

        return $w;
    }

    /**
     * Resolves a list of SKUs (or ids) to products; every SKU must exist and be active.
     *
     * @param  string[]  $skus
     * @return array<string, Product> keyed by sku AND by id
     */
    public static function findProducts(array $skus): array
    {
        $uniq = array_values(array_unique(array_map('strval', $skus)));
        $rows = Product::with(['suppliers.supplier', 'category'])->where(fn ($w) => $w->whereIn('sku', $uniq)->orWhereIn('id', $uniq))->get();
        $map = [];
        foreach ($rows as $p) {
            $map[$p->sku] = $p;
            $map[$p->id] = $p;
        }
        // MySQL matches SKUs case-insensitively; keep the caller's spelling resolvable.
        foreach ($uniq as $s) {
            if (! isset($map[$s])) {
                $hit = $rows->first(fn (Product $p) => strcasecmp($p->sku, $s) === 0);
                if ($hit) {
                    $map[$s] = $hit;
                }
            }
        }
        $missing = array_values(array_filter($uniq, fn ($s) => ! isset($map[$s])));
        if ($missing) {
            throw AppError::validation('PRODUCT_NOT_FOUND', 'منتج غير معروف: '.implode('، ', $missing), 'Unknown product: '.implode(', ', $missing));
        }
        $inactive = array_values(array_filter(array_map(fn ($s) => $map[$s], $uniq), fn (Product $p) => ! $p->active));
        if ($inactive) {
            $list = array_map(fn (Product $p) => $p->sku, $inactive);
            throw AppError::rule('PRODUCT_INACTIVE', 'منتج غير نشط: '.implode('، ', $list), 'Inactive product: '.implode(', ', $list));
        }

        return $map;
    }

    /**
     * Preferred supplier for a product: explicit product_suppliers.preferred, else by product.preferred_supplier_name.
     * The product must have `suppliers.supplier` loaded.
     *
     * @param  array<string, Supplier>|null  $suppliersByName
     * @return array{supplier: Supplier, leadDays: ?int, price: ?float}|null
     */
    public static function preferredSupplierOf(Product $p, ?array $suppliersByName = null): ?array
    {
        /** @var Collection $links */
        $links = $p->suppliers;
        $ps = $links->first(fn ($s) => $s->preferred) ?? $links->first();
        if ($ps && $ps->supplier) {
            return ['supplier' => $ps->supplier, 'leadDays' => $ps->lead_days ?? $ps->supplier->lead_days ?? null, 'price' => $ps->price !== null ? (float) $ps->price : null];
        }
        if ($p->preferred_supplier_name && $suppliersByName !== null) {
            $s = $suppliersByName[$p->preferred_supplier_name] ?? null;
            if ($s) {
                return ['supplier' => $s, 'leadDays' => $s->lead_days ?? null, 'price' => null];
            }
        }

        return null;
    }
}
