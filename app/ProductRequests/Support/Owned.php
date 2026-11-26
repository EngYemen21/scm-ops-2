<?php

namespace App\ProductRequests\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestPicture;
use App\Support\AppError;

/**
 * Who owns a request (docs/odoo/WAVE2.md §1). While the section follows Odoo (`OdooProjection::owns('preq')`) a
 * request that came from Odoo is Odoo's: OPS shows it and adds what is its own — comments, pictures, the OPS product
 * it corresponds to — and refuses to edit or decide what Odoo holds. The day the switch is turned off the section is
 * handed over and every request is worked here. A request raised in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'preq';

    /** The section follows Odoo right now. */
    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    /** This request is Odoo's right now. */
    public static function bySource(?ProductRequest $r): bool
    {
        return $r !== null && $r->source_system === OdooProjection::SYSTEM && self::following();
    }

    /** Refuses a change of what the source system owns (422 PREQ_OWNED_BY_SOURCE). */
    public static function refuse(?ProductRequest $r): void
    {
        if (self::bySource($r)) {
            throw self::error(['sourceSystem' => $r->source_system, 'sourceModel' => $r->source_model, 'externalRef' => $r->external_ref, 'sourceNumber' => $r->source_number]);
        }
    }

    /** A picture that was attached in Odoo is Odoo's for as long as its request is. */
    public static function refusePicture(ProductRequest $r, ProductRequestPicture $p): void
    {
        if ($p->source_system === OdooProjection::SYSTEM && self::bySource($r)) {
            throw self::error(['sourceSystem' => $p->source_system, 'externalRef' => $p->external_ref, 'entity' => 'picture']);
        }
    }

    private static function error(array $details): AppError
    {
        return AppError::rule('PREQ_OWNED_BY_SOURCE',
            'هذا الطلب يُدار من أودو — يُعدَّل ويُعتمد هناك. في نظام العمليات تُضاف إليه الملاحظات والصور وربط الصنف فقط إلى أن يُسلَّم القسم',
            'This request is owned by Odoo — it is changed and decided there. OPS only adds comments, pictures and the product link to it until the section is handed over',
            $details);
    }
}
