<?php

namespace App\Planning\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('planning')`) a run, a budget scenario or a purchase plan that came from Odoo is Odoo's: OPS
 * shows it and refuses to change it. The day the switch is turned off the section is handed over. A record made in OPS
 * is always OPS's.
 */
final class Owned
{
    public const AREA = 'planning';

    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM && self::following();
    }

    /** Came from Odoo, whoever owns it now: computed there, never recomputed here. */
    public static function fromSource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM;
    }

    /** Refuses a change of what the source system owns. `$entity`: run | budget | plan. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('PLANNING_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — عدّله هناك. يبقى هنا للقراءة إلى أن يُسلَّم القسم',
                'This record is owned by Odoo — change it there. It stays read-only here until the section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => self::bySource($row)];
    }
}
