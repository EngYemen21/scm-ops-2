<?php

namespace App\Pos\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo (`OdooProjection::owns('pos')`)
 * a register, a payment method, a denomination, a session or an order that came from Odoo is Odoo's: OPS shows it and
 * does not change it. The day the switch is turned off the settings are handed over and are edited here; Odoo's
 * sessions and orders stay history either way (they have no stock or accounting trail in OPS).
 */
final class Owned
{
    public const AREA = 'pos';

    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM && self::following();
    }

    /** Refuses a change of what the source system owns. `$entity`: register | method | denomination. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('POS_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — لا يُعدَّل هنا إلى أن يُسلَّم قسم نقطة البيع',
                'This record is owned by Odoo — it is not changed here until the point-of-sale section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }

    /** A session or an order of Odoo: history, whoever owns the section. */
    public static function refuseHistory(?Model $row, string $entity): void
    {
        if ($row !== null && $row->getAttribute('source_system') !== null) {
            throw AppError::rule('POS_HISTORY_ONLY',
                'هذا السجل من تاريخ أودو — يُعرض للاطلاع فقط؛ لا مخزون ولا قيود له في العمليات',
                'This record is Odoo history — shown for reference only; it has no stock or accounting trail in OPS',
                ['entity' => $entity, 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
