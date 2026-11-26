<?php

namespace App\Portal\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('portal')`) a record that came from Odoo is Odoo's: OPS shows it, adds what is its own — a
 * branch's address, contact and delivery window, the branch a list is kept for, notes, documents, a quotation made
 * from a list — and refuses to change what Odoo holds. The day the switch is turned off the section is handed over and
 * every record is edited here. A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'portal';

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

    /**
     * Refuses a change of what the source system owns. `$entity`: branch | list | application | request | statement.
     *
     * @param  string[]|null  $fields  the fields of the request that are the source's (when only some of them are)
     */
    public static function refuse(?Model $row, string $entity, ?array $fields = null): void
    {
        if (! self::bySource($row)) {
            return;
        }
        throw AppError::rule('PORTAL_OWNED_BY_SOURCE',
            'هذا السجل يُدار من أودو، والقسم ما زال يتبع أودو: يُعدَّل هناك ويصل هنا تلقائيًا. في نظام العمليات يُضاف إليه ما يخصه فقط إلى أن يُسلَّم القسم'
                .($fields ? ' (الحقول: '.implode('، ', $fields).')' : ''),
            'This record is owned by Odoo and the section still follows Odoo: change it there and it arrives here by itself. OPS only adds what is its own until the section is handed over'
                .($fields ? ' (fields: '.implode(', ', $fields).')' : ''),
            ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')] + ($fields ? ['fields' => $fields] : []));
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} for the JSON of a record */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => self::bySource($row)];
    }
}
