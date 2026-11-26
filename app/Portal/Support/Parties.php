<?php

namespace App\Portal\Support;

use App\Integration\Services\ExternalRefs;
use App\Models\Customer;
use App\Models\Product;
use App\Portal\Models\PortalBranch;
use App\Support\AppError;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The customers, products and branches the records of the section point at.
 *
 * A customer is named to the API by its OPS id, by its code, or the way another system knows it —
 * `<system>:<its id there>` (`odoo:1234`, `sales:c-77`), resolved through the identity links (`int_external_refs`),
 * never by a name. A record that came from another system may point at a customer or a product OPS does not have yet:
 * it then carries that system's id and name (`customer_ref` / `customer_name`), is shown as such (`linked: false`) and
 * gets its OPS link the day one exists.
 */
final class Parties
{
    /** What a list needs of a customer / a product, for `with()`. */
    public const CUSTOMER = 'customer:id,code,name_ar,name_en,city,vat_no,active';

    public const PRODUCT = 'product:id,sku,name_ar,name_en,active,base_uom_id';

    public const BRANCH = 'branch:id,name,customer_id,active';

    /** The OPS customer a caller names; refused as a field error when nobody answers. */
    public static function customer(?string $key, string $field = 'customerId'): Customer
    {
        return self::findCustomer($key)
            ?? throw AppError::validation('CUSTOMER_NOT_FOUND', 'العميل غير موجود', 'Customer not found', [['path' => $field, 'message' => 'العميل غير موجود']]);
    }

    public static function findCustomer(?string $key): ?Customer
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (preg_match('/^([a-z][a-z0-9_]{1,19}):(.{1,120})$/', $key, $m)) {
            $id = app(ExternalRefs::class)->internalId($m[1], 'customer', $m[2]);

            return $id ? Customer::find($id) : null;
        }

        return Customer::where('id', $key)->orWhere('code', $key)->first();
    }

    /** The OPS product a caller names by id or by its code (SKU). */
    public static function product(?string $key, string $field = 'sku'): Product
    {
        $key = trim((string) $key);
        $p = $key === '' ? null : Product::where('id', $key)->orWhere('sku', $key)->first();

        return $p ?? throw AppError::validation('SKU_NOT_FOUND', "المنتج {$key} غير موجود", "Unknown product {$key}", [['path' => $field, 'message' => 'المنتج غير موجود']]);
    }

    /** A branch a caller names; with `$customer` it must be that customer's. */
    public static function branch(?string $id, ?Customer $customer = null, string $field = 'branchId'): PortalBranch
    {
        $b = PortalBranch::find(trim((string) $id))
            ?? throw AppError::validation('PORTAL_BRANCH_NOT_FOUND', 'الفرع غير موجود', 'Branch not found', [['path' => $field, 'message' => 'الفرع غير موجود']]);
        if ($customer && $b->customer_id !== $customer->id) {
            throw AppError::rule('PORTAL_BRANCH_CUSTOMER', "الفرع «{$b->name}» ليس من فروع العميل {$customer->name_ar} ({$customer->code})", "Branch \"{$b->name}\" does not belong to customer {$customer->code}");
        }

        return $b;
    }

    // ───────────── the JSON of a party ─────────────

    /**
     * The customer of a row: the OPS customer, or the one the source system names. Null when the row names nobody.
     *
     * @return array{id:?string, code:?string, nameAr:?string, nameEn:?string, city:?string, active:?bool, linked:bool, ref:?string}|null
     */
    public static function customerOf(Model $row): ?array
    {
        $c = $row->getAttribute('customer_id') && $row->relationLoaded('customer') ? $row->getRelation('customer') : null;
        $ref = $row->getAttribute('customer_ref');
        $name = $row->getAttribute('customer_name');
        if ($c) {
            return ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'city' => $c->city, 'active' => (bool) $c->active, 'linked' => true, 'ref' => $ref];
        }
        if ($ref === null && $name === null && ! $row->getAttribute('customer_id')) {
            return null;
        }

        return ['id' => $row->getAttribute('customer_id'), 'code' => null, 'nameAr' => $name, 'nameEn' => $name, 'city' => null, 'active' => null, 'linked' => false, 'ref' => $ref];
    }

    /** @return array{id:?string, sku:?string, nameAr:?string, nameEn:?string, active:?bool, linked:bool, ref:?string}|null */
    public static function productOf(Model $row): ?array
    {
        $p = $row->getAttribute('product_id') && $row->relationLoaded('product') ? $row->getRelation('product') : null;
        $ref = $row->getAttribute('product_ref');
        $name = $row->getAttribute('product_name');
        if ($p) {
            return ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'active' => (bool) $p->active, 'linked' => true, 'ref' => $ref];
        }
        if ($ref === null && $name === null) {
            return null;
        }

        return ['id' => null, 'sku' => null, 'nameAr' => $name, 'nameEn' => $name, 'active' => null, 'linked' => false, 'ref' => $ref];
    }

    /** @return array{id:string, name:string, active:bool}|null */
    public static function branchOf(Model $row): ?array
    {
        $b = $row->getAttribute('branch_id') && $row->relationLoaded('branch') ? $row->getRelation('branch') : null;

        return $b ? ['id' => $b->id, 'name' => $b->name, 'active' => (bool) $b->active] : null;
    }

    // ───────────── the filters every list shares ─────────────

    /**
     * `customer=<id | code | system:id>` — the rows of that OPS customer; `customer=ref:<id>` — the rows that name
     * that customer of the source system, linked or not. A value nobody answers to matches nothing.
     */
    public static function filterCustomer(Builder $query, ?string $value, string $table = ''): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }
        $col = fn (string $c) => $table === '' ? $c : "{$table}.{$c}";
        if (str_starts_with($value, 'ref:')) {
            $query->where($col('customer_ref'), substr($value, 4));

            return;
        }
        $c = self::findCustomer($value);
        $c ? $query->where($col('customer_id'), $c->id) : $query->whereRaw('1 = 0');
    }

    /** `linked=yes|no` — rows whose customer is / is not an OPS customer yet. */
    public static function filterLinked(Builder $query, ?string $value): void
    {
        match ($value) {
            'yes' => $query->whereNotNull('customer_id'),
            'no' => $query->whereNull('customer_id'),
            default => null,
        };
    }

    /** `origin=ops|odoo` — where the record was made. */
    public static function filterOrigin(Builder $query, ?string $value): void
    {
        if ($value === 'ops') {
            $query->whereNull('source_system');
        } elseif ($value !== null && $value !== '') {
            $query->where('source_system', $value);
        }
    }

    /**
     * A search word against the customer of a row: the name the source gave it, or the name / code of the OPS
     * customer. Used inside a `where(function ($w) { … })` group of a list's search.
     */
    public static function searchCustomer(Builder $w, string $like): void
    {
        $op = Sql::like();
        $w->orWhere('customer_name', $op, $like)
            ->orWhereIn('customer_id', Customer::query()->where(fn ($c) => $c->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('code', $op, $like))->select('id'));
    }

    /** The same for the product of a row. */
    public static function searchProduct(Builder $w, string $like): void
    {
        $op = Sql::like();
        $w->orWhere('product_name', $op, $like)
            ->orWhereIn('product_id', Product::query()->where(fn ($p) => $p->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('sku', $op, $like))->select('id'));
    }
}
