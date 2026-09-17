<?php

namespace App\Http\Controllers\Api\Procurement;

/** Validation fragments shared by the procurement controllers (mirrors of the reference Zod schemas). */
final class Rules
{
    /** DateStr: a string that starts with YYYY-MM-DD and is a real date. */
    public const DATE = ['string', 'regex:/^\d{4}-\d{2}-\d{2}/', 'date'];

    public const INVITE_RULE = 'in:cat,top3,pref,manual';

    public static function date(bool $required = false): array
    {
        return array_merge([$required ? 'required' : 'nullable'], self::DATE);
    }

    /** LineSchema: { sku, qty > 0, price? ≥ 0, discPct?, notes? }. */
    public static function lines(bool $priceRequired = false): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.sku' => 'required|string|min:1',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.price' => $priceRequired ? 'required|numeric|gt:0' : 'nullable|numeric|min:0',
            'lines.*.discPct' => 'nullable|numeric|min:0|max:100',
            'lines.*.notes' => 'nullable|string',
        ];
    }

    public static function supplierCodes(): array
    {
        return ['supplierCodes' => 'nullable|array', 'supplierCodes.*' => 'string'];
    }

    public static function scoreOverride(): array
    {
        return ['overrideSupplierScore' => 'nullable|boolean', 'overrideReason' => 'nullable|string'];
    }
}
