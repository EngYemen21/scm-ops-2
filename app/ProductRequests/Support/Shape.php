<?php

namespace App\ProductRequests\Support;

use App\Models\BaseModel;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestEvent;
use App\ProductRequests\Models\ProductRequestPicture;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How a request leaves the server (camelCase, the same in the lists, on the board and in the drawer). A category, a
 * supplier, a unit and a customer are an object when OPS holds the record and a plain name beside it otherwise; a
 * person is `{ id, nameAr, nameEn, username, external }`.
 */
final class Shape
{
    /** The Odoo archive kinds (config/odoo_archive.php) that show the same record with everything Odoo holds of it. */
    private const ARCHIVE_KIND = [ProductRequest::STAFF_MODEL => 'new_product_request', ProductRequest::PORTAL_MODEL => 'portal_product_request'];

    /**
     * One row of a list / one card of the board.
     *
     * @param  array<string, true>  $archived  Odoo attachment ids whose content OPS keeps
     */
    public static function card(ProductRequest $r, People $people, array $archived, Carbon $now, bool $mine, bool $owned): array
    {
        $pictures = $r->relationLoaded('pictures') ? $r->pictures : collect();
        $age = $r->open() && $r->status_at ? max(0, $now->getTimestamp() - $r->status_at->getTimestamp()) : null;

        return [
            'id' => $r->id, 'number' => $r->number, 'sourceNumber' => $r->source_number, 'requestType' => $r->request_type,
            'status' => $r->status, 'statusAt' => self::at($r->status_at), 'ageDays' => $age === null ? null : intdiv($age, 86400), 'ageHours' => $age === null ? null : intdiv($age, 3600),
            'nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'brand' => $r->brand, 'packText' => $r->pack_text,
            'category' => self::ref($r->category), 'categoryName' => $r->category_name,
            'supplier' => self::ref($r->supplier), 'supplierName' => $r->supplier_name,
            'costPrice' => self::money($r->cost_price), 'salePrice' => self::money($r->sale_price), 'currency' => $r->currency,
            'barcode' => $r->barcode, 'proposedSku' => $r->proposed_sku, 'storageClass' => $r->storage_class,
            'requestedBy' => $people->person($r->requested_by_id, $r->requested_by_name, $r->requested_by_login),
            'customer' => self::ref($r->customer), 'customerName' => $r->customer_name,
            'product' => $r->product ? ['id' => $r->product->id, 'sku' => $r->product->sku, 'nameAr' => $r->product->name_ar, 'nameEn' => $r->product->name_en, 'active' => (bool) $r->product->active] : null,
            'productSku' => $r->product?->sku ?? $r->product_sku, 'productLink' => $r->product_link,
            'picture' => ($first = $pictures->first()) ? self::picture($r, $first, $archived) : null, 'pictureCount' => $pictures->count(),
            'returnsCount' => $r->returns_count, 'mine' => $mine,
            'sourceSystem' => $r->source_system, 'sourceModel' => $r->source_model, 'externalRef' => $r->external_ref, 'sourceState' => Flow::sourceState($r->source_state), 'owned' => $owned,
            'createdAt' => self::at($r->created_at), 'submittedAt' => self::at($r->submitted_at), 'decidedAt' => self::at($r->decided_at),
        ];
    }

    /** Everything the drawer shows on top of the card (the service adds `can`, `missing`, `estimate`). */
    public static function detail(ProductRequest $r, People $people, array $archived): array
    {
        $kind = self::ARCHIVE_KIND[$r->source_model] ?? null;

        return [
            'uom' => self::ref($r->uom), 'uomName' => $r->uom_name, 'packUom' => self::ref($r->packUom), 'packQty' => $r->pack_qty,
            'shelfLifeDays' => $r->shelf_life_days, 'tracksExpiry' => (bool) $r->tracks_expiry,
            'weightKg' => $r->weight_kg, 'lengthCm' => $r->length_cm, 'widthCm' => $r->width_cm, 'heightCm' => $r->height_cm,
            'reason' => $r->reason,
            'reviewer' => $people->person($r->reviewer_id, $r->reviewer_name), 'decidedBy' => $people->person($r->decided_by_id, $r->decided_by_name),
            'reviewStartedAt' => self::at($r->review_started_at),
            'returnReason' => $r->return_reason, 'rejectReason' => $r->reject_reason, 'cancelReason' => $r->cancel_reason,
            'pictures' => $r->pictures->map(fn (ProductRequestPicture $p) => self::picture($r, $p, $archived))->values()->all(),
            'events' => $r->events->map(fn (ProductRequestEvent $e) => self::event($e))->values()->all(),
            'sourceMeta' => $r->source_meta ?: null, 'sourceUpdatedAt' => self::at($r->source_updated_at), 'sourceDeletedAt' => self::at($r->source_deleted_at),
            // the Odoo product it became, when that product is not an OPS product yet (the link is made by itself the day it is)
            'sourceProductRef' => $r->product_id ? null : $r->source_product_ref,
            // the same record in the Odoo archive, with its conversation and every field Odoo holds
            'archive' => $kind && $r->fromOdoo() && ctype_digit((string) $r->external_ref) ? ['kind' => $kind, 'odooId' => (int) $r->external_ref] : null,
        ];
    }

    /** @param  array<string, true>  $archived */
    public static function picture(ProductRequest $r, ProductRequestPicture $p, array $archived): array
    {
        $odoo = $p->status === ProductRequestPicture::ODOO;

        return [
            'id' => $p->id, 'fileName' => $p->file_name, 'mime' => $p->mime, 'size' => $p->size, 'origin' => $odoo ? 'odoo' : 'ops',
            // a picture of Odoo is listed before its content reaches the OPS file archive: until then there is nothing to show
            'available' => ! $odoo || isset($archived[(string) $p->external_ref]),
            'url' => "/product-requests/{$r->id}/pictures/{$p->id}",
            'uploadedBy' => $p->uploaded_by, 'uploadedById' => $p->uploaded_by_id, 'createdAt' => self::at($p->created_at),
        ];
    }

    public static function event(ProductRequestEvent $e): array
    {
        return [
            'id' => $e->id, 'kind' => $e->kind, 'fromStatus' => $e->from_status, 'toStatus' => $e->to_status, 'by' => $e->user_name, 'byId' => $e->user_id,
            'note' => $e->note, 'meta' => $e->meta ?: null, 'origin' => $e->source_system, 'at' => self::at($e->at),
        ];
    }

    /** @return array{id:string, code:?string, nameAr:string, nameEn:?string}|null */
    public static function ref(?BaseModel $m): ?array
    {
        return $m ? ['id' => (string) $m->getKey(), 'code' => $m->getAttribute('code'), 'nameAr' => (string) $m->getAttribute('name_ar'), 'nameEn' => $m->getAttribute('name_en')] : null;
    }

    /** A price as text with two to four decimals ("12.50", "6.3043"): the figure as it was given, never rounded away. */
    public static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = number_format((float) $value, 4, '.', '');

        return (string) preg_replace('/0{1,2}$/', '', $text);
    }

    private static function at(?DateTimeInterface $at): ?string
    {
        return $at ? Carbon::instance($at)->toJSON() : null;
    }
}
