<?php

namespace App\Payroll\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While payroll follows Odoo (`odoo.project.payroll`) a
 * record that came from Odoo is Odoo's: OPS shows it and refuses to change what Odoo holds
 * (`<ENTITY>_OWNED_BY_SOURCE`, 422). A record made in OPS is always OPS's, and so is everything the day the switch is
 * turned off.
 */
final class Owned
{
    public const AREA = 'payroll';

    public const ODOO = OdooProjection::SYSTEM;

    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    /** This row is Odoo's right now. */
    public static function locked(Model|string|null $rowOrSource): bool
    {
        $source = $rowOrSource instanceof Model ? $rowOrSource->getAttribute('source_system') : $rowOrSource;

        return $source === self::ODOO && self::following();
    }

    /** Refuses a change of what the source system owns. `$entity` names the kind of record in the error code. */
    public static function refuse(?Model $row, string $entity, string $whatAr, string $whatEn): void
    {
        if ($row !== null && self::locked($row)) {
            throw AppError::rule(strtoupper($entity).'_OWNED_BY_SOURCE',
                "{$whatAr} مصدره أودو، والقسم ما زال يتبع أودو: يُعدَّل هناك ويصل هنا تلقائيًا",
                "{$whatEn} comes from Odoo and this section still follows Odoo: change it there and it arrives here by itself",
                ['entity' => $entity, 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
