<?php

namespace App\Http\Controllers\Api\Sales;

/** Validation rules shared by the sales controllers (the Zod schemas of the reference: QuotationSchema, SalesOrderSchema, Note). */
final class SalesRules
{
    /** DateStr: a string that starts with YYYY-MM-DD. */
    public const DATE = 'regex:/^\d{4}-\d{2}-\d{2}/';

    /** Body of the approve / reject / cancel action buttons — always optional. */
    public const NOTE = ['note' => 'nullable|string', 'reason' => 'nullable|string'];

    /**
     * LineSchema; numeric strings are accepted (z.coerce). A price, when sent, is positive. A line may come without one:
     * the service then takes the price of the customer's price list, or refuses the line (PRICE_REQUIRED).
     */
    public static function lines(): array
    {
        return [
            'lines' => 'required|array|min:1', 'lines.*.sku' => 'required|string|min:1', 'lines.*.qty' => 'required|integer|min:1',
            'lines.*.price' => 'nullable|numeric|gt:0', 'lines.*.discPct' => 'nullable|numeric|min:0|max:100', 'lines.*.notes' => 'nullable|string',
        ];
    }
}
