<?php

namespace App\Pricing\Support;

use App\Integration\Odoo\Projection\OdooProjection;
use App\Support\AppError;
use Illuminate\Database\Eloquent\Model;

/**
 * Who owns a record of the section (docs/odoo/WAVE2.md §1). While the section follows Odoo
 * (`OdooProjection::owns('pricing')`) a list, a rule, a list price or a supplier price that came from Odoo is Odoo's:
 * OPS shows it, prices with it and refuses to change it. What is made in OPS — a list of its own, a copy of an Odoo
 * list, a supplier price a buyer typed — is OPS's. The day the switch is turned off the section is handed over and
 * everything is edited here.
 */
final class Owned
{
    public const AREA = 'pricing';

    public const CODE = 'PRICING_OWNED_BY_SOURCE';

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

    /** Refuses a change of what the source system owns. `$entity`: list | rule | assignment | listPrice | supplierPrice. */
    public static function refuse(?Model $row, string $entity): void
    {
        if (self::bySource($row)) {
            throw self::refusal($entity, ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref')]);
        }
    }

    /** The price list of a customer that Odoo names itself (the partner's own field): changed there, not here. */
    public static function assignmentRefusal(array $details = []): AppError
    {
        return AppError::rule(self::CODE,
            'قائمة أسعار هذا العميل تُدار من أودو (حقل قائمة الأسعار في بطاقة العميل) والقسم ما زال يتبع أودو — غيّرها هناك وتصل هنا تلقائيًا',
            'The price list of this customer is set in Odoo (the price list on the partner) and the section still follows Odoo — change it there and it arrives here by itself',
            ['entity' => 'assignment'] + $details);
    }

    public static function refusal(string $entity, array $details = []): AppError
    {
        return AppError::rule(self::CODE,
            'هذا السجل يُدار من أودو والقسم ما زال يتبع أودو — عدّله هناك ويصل هنا تلقائيًا. لتسعير مختلف أنشئ قائمة خاصة بالنظام أو انسخ هذه القائمة',
            'This record is owned by Odoo and the section still follows Odoo — change it there and it arrives here by itself. For a different price, create a list of your own or copy this one',
            ['entity' => $entity] + $details);
    }
}
