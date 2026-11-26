<?php

namespace App\Commissions\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('commissions')`) a plan, a statement or a line that came from Odoo is Odoo's: OPS shows it and
 * refuses to change it. The day the switch is turned off the section is handed over and everything is worked here.
 * A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'commissions';

    /** The section follows Odoo right now. */
    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    /** This record is Odoo's right now. */
    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM && self::following();
    }

    /** Refuses a change of what the source system owns. `$entity`: plan | statement | line. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('COMMISSION_OWNED_BY_SOURCE',
                'هذا السجل من أودو ويُدار هناك — يُعرض هنا للاطلاع إلى أن يُسلَّم قسم العمولات لنظام العمليات',
                'This record came from Odoo and is managed there — it is shown here to read until the commissions section is handed over to OPS',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
