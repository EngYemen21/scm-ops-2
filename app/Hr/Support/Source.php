<?php

namespace App\Hr\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;

/**
 * Who owns a record of this section. A row that came from Odoo (`source_system = odoo`) is Odoo's for as long as the
 * area follows Odoo (config `odoo.project.hr`, read through OdooProjection::owns): OPS shows it and refuses to change
 * what Odoo holds. Rows created in OPS are OPS's, and so is everything once the switch is turned off.
 */
final class Source
{
    public const ODOO = 'odoo';

    public const AREA = 'hr';

    /** The area follows Odoo right now. */
    public static function follows(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    /** This row is Odoo's right now. */
    public static function locked(?string $sourceSystem): bool
    {
        return $sourceSystem === self::ODOO && self::follows();
    }

    /** The refusal of an edit of what Odoo owns: `<ENTITY>_OWNED_BY_SOURCE`, 422. `$what` names the record for a person. */
    public static function refusal(string $entity, string $whatAr, string $whatEn, ?array $fields = null): AppError
    {
        return AppError::rule(
            strtoupper($entity).'_OWNED_BY_SOURCE',
            "{$whatAr} مصدره أودو، والقسم ما زال يتبع أودو: يُعدَّل هناك ويصل هنا تلقائيًا".($fields ? ' (الحقول: '.implode('، ', $fields).')' : ''),
            "{$whatEn} comes from Odoo and this section still follows Odoo: change it there and it arrives here by itself".($fields ? ' (fields: '.implode(', ', $fields).')' : ''),
            $fields ? ['fields' => $fields] : null,
        );
    }

    /** Refuses when the row is Odoo's right now. */
    public static function assertFree(?string $sourceSystem, string $entity, string $whatAr, string $whatEn): void
    {
        if (self::locked($sourceSystem)) {
            throw self::refusal($entity, $whatAr, $whatEn);
        }
    }
}
