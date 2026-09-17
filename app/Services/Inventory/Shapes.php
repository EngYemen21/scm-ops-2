<?php

namespace App\Services\Inventory;

use App\Models\Batch;
use App\Models\Bin;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/**
 * The reference API returns narrow `select` projections of related rows (product / batch / bin …). These helpers
 * build exactly those camelCase shapes so the inventory responses stay byte-compatible with the contract.
 */
final class Shapes
{
    /** Columns of the movement list embedded in a transfer / count detail. */
    public const DOC_MOVEMENT_COLUMNS = ['number', 'type', 'product_id', 'batch_no', 'qty', 'before_qty', 'after_qty', 'src_bin_id', 'dst_bin_id', 'username', 'created_at'];

    /** @param  string[]  $extra  extra snake_case columns to expose (camelCased) */
    public static function product(?Product $p, array $extra = ['storage_class']): ?array
    {
        if (! $p) {
            return null;
        }
        $out = ['id' => $p->id, 'sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en];
        foreach ($extra as $column) {
            $out[Str::camel($column)] = $p->getAttribute($column);
        }

        return $out;
    }

    public static function batch(?Batch $b): ?array
    {
        return $b ? ['id' => $b->id, 'batchNo' => $b->batch_no, 'expiryDate' => $b->expiry_date?->toJSON()] : null;
    }

    /** { id, code, zone: { code, type } } */
    public static function bin(?Bin $b): ?array
    {
        return $b ? ['id' => $b->id, 'code' => $b->code, 'zone' => ['code' => $b->zone?->code, 'type' => $b->zone?->type]] : null;
    }

    /** { id, code, zone: { code, type }, warehouse: { code } } — the bin projection of a ledger row. */
    public static function ledgerBin(?Bin $b): ?array
    {
        return $b ? self::bin($b) + ['warehouse' => ['code' => $b->warehouse?->code]] : null;
    }

    public static function warehouse(?Warehouse $w): ?array
    {
        return $w ? ['id' => $w->id, 'code' => $w->code, 'nameAr' => $w->name_ar, 'nameEn' => $w->name_en] : null;
    }

    /** Short movement row listed under a transfer / count. */
    public static function docMovement(InventoryMovement $m): array
    {
        return [
            'number' => $m->number, 'type' => $m->type, 'productId' => $m->product_id, 'batchNo' => $m->batch_no, 'qty' => $m->qty,
            'beforeQty' => $m->before_qty, 'afterQty' => $m->after_qty, 'srcBinId' => $m->src_bin_id, 'dstBinId' => $m->dst_bin_id,
            'username' => $m->username, 'createdAt' => $m->created_at?->toJSON(),
        ];
    }
}
