<?php

namespace App\Crm\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('crm')`) a record that came from Odoo is Odoo's: OPS shows it and adds what is its own — notes,
 * to-dos, the customer a won opportunity became — and refuses to edit what Odoo holds. The day the switch is turned off
 * the section is handed over and every record is edited here. A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'crm';

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

    /** Refuses a change of what the source system owns. `$entity`: lead | stage | team | reason | tag. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('CRM_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — عدّله هناك. في العمليات تُضاف إليه الملاحظات والأنشطة فقط إلى أن يُسلَّم القسم',
                'This record is owned by Odoo — change it there. OPS only adds notes and activities to it until the section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
