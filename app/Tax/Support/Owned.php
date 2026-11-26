<?php

namespace App\Tax\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('tax')`) a tax code, a tax group or a fiscal position that came from Odoo is Odoo's: OPS shows
 * it and refuses to change what Odoo holds. What is OPS's own on such a record stays open — the short code people
 * type, which code is the default of OPS documents, and the exemption reason or the line of the return where Odoo
 * holds none. The day the switch is turned off the section is handed over and everything is edited here.
 * The e-invoice history that came from Odoo is a record of what happened: it is never edited.
 */
final class Owned
{
    public const AREA = 'tax';

    public static function following(): bool
    {
        return OdooProjection::owns(self::AREA);
    }

    public static function bySource(?Model $row): bool
    {
        return $row !== null && $row->getAttribute('source_system') === OdooProjection::SYSTEM && self::following();
    }

    /** Refuses a change of what the source system owns. `$entity`: code | group | position. */
    public static function refuse(?Model $row, string $entity, ?string $field = null): void
    {
        if (self::bySource($row)) {
            throw AppError::rule('TAX_OWNED_BY_SOURCE',
                'هذا السجل يُدار من أودو — عدّله هناك. في العمليات يُعدَّل ما يخصها فقط (الرمز المختصر، الرمز الافتراضي، وسبب الإعفاء أو بند الإقرار إن لم يحدّده أودو) إلى أن يُسلَّم القسم',
                'This record is owned by Odoo — change it there. OPS only edits what is its own (the short code, the default flag, and the exemption reason or return line Odoo does not give) until the section is handed over',
                ['entity' => $entity, 'field' => $field, 'sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => self::bySource($row)];
    }
}
