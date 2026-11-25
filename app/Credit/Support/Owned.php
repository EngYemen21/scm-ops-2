<?php

namespace App\Credit\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('credit')`) a record that came from Odoo is Odoo's: OPS shows it, lets people add what is
 * OPS's own (a comment, a paper in the "other" slot, the OPS role a stage's Odoo group answers to) and refuses to
 * change what Odoo holds. The day the switch is turned off the section is handed over and every record is worked
 * here. A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'credit';

    public const SYSTEM = OdooProjection::SYSTEM;

    /** The section follows Odoo right now. */
    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    /** This record is Odoo's right now. */
    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === self::SYSTEM && self::following();
    }

    public static function error(Model $row, string $entity): AppError
    {
        return AppError::rule('CREDIT_OWNED_BY_SOURCE',
            'هذا السجل مصدره أودو وما زال يُدار هناك — يُعرض هنا ولا يُغيَّر. يمكن إضافة تعليق أو مرفق إضافي عليه إلى أن يُسلَّم القسم لنظام العمليات',
            'This record comes from Odoo and is still managed there — it is shown here and not changed. A comment or an extra attachment can be added until the section is handed over to OPS',
            ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
    }

    /** Refuses a change of what the source system owns. `$entity`: request | stage | termsVersion | paymentMethod | envelope | file. */
    public static function refuse(?Model $row, string $entity): void
    {
        if ($row !== null && self::bySource($row)) {
            throw self::error($row, $entity);
        }
    }
}
