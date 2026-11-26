<?php

namespace App\Banking\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('banking')`) a record that came from Odoo is Odoo's: OPS shows it and adds what is its own —
 * the OPS bank account a journal is, the OPS ledger account a rule writes off to, a note — and refuses to edit what
 * Odoo holds. The day the switch is turned off the section is handed over and those records are edited here.
 * A record made in OPS is always OPS's.
 */
final class Owned
{
    public const AREA = 'banking';

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

    /** Refuses a change of what the source system owns. `$entity`: statement | line | rule | journal | term. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('BANKING_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — عدّله هناك. في العمليات يُضاف إليه ما يخصها فقط (الربط بحساب بنكي، حساب التسوية، ملاحظة) إلى أن يُسلَّم القسم',
                'This record is owned by Odoo — change it there. OPS only adds what is its own (the bank account link, the write-off account, a note) until the section is handed over',
                ['entity' => $entity, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }
}
