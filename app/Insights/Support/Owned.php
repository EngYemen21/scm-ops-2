<?php

namespace App\Insights\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('insights')`) a brand owner, franchisee, expiry level or alert that came from Odoo is Odoo's:
 * OPS shows it and refuses to edit what Odoo holds (422 INSIGHTS_OWNED_BY_SOURCE). What OPS adds beside it stays
 * open: a franchisee or a product added here, and the read mark of an alert. A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'insights';

    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM && self::following();
    }

    /** `$entity`: owner | franchisee | level */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('INSIGHTS_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — عدّله هناك إلى أن يُسلَّم القسم',
                'This record is owned by Odoo — change it there until the section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
