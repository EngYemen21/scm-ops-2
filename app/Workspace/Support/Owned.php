<?php

namespace App\Workspace\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the workspace (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('workspace')`) a record that came from Odoo is Odoo's: OPS shows it, adds what is its own —
 * replies, notes and comments with their files — and refuses to change what Odoo holds. The day the switch is turned
 * off the section is handed over and every record is changed here. A record made in OPS is always OPS's, also when it
 * stands in a team, a project or a folder that came from Odoo.
 */
final class Owned
{
    public const AREA = 'workspace';

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

    /** Refuses a change of what the source system owns. `$entity`: ticket | team | stage | type | project | task | tag | folder | document | file. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('WORKSPACE_OWNED_BY_SOURCE',
                'هذا السجل مصدره أودو وما زال القسم يتبع أودو — يُعدَّل هناك. في نظام العمليات تُضاف إليه الردود والتعليقات فقط إلى أن يُسلَّم القسم',
                'This record comes from Odoo and the section still follows Odoo — change it there. OPS only adds replies and comments to it until the section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => self::bySource($row)];
    }
}
