<?php

namespace App\Workspace\Support;

use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A link from a ticket, a task or a document to another record of OPS, by what people call it: its kind and its number
 * (a sales order, a purchase order, a trip, a customer code, a product SKU…). The pair is checked when it is saved —
 * a link to something that does not exist is refused — and kept as people know it, so the link survives whatever
 * happens to internal ids. The keys are the ones the client's `entityPath(type, number)` opens.
 */
final class RecordLinks
{
    /** kind → [table, the column people know it by, Arabic, English, the column that names it (or null)] */
    private const TYPES = [
        'so' => ['sales_orders', 'number', 'أمر بيع', 'Sales order', null],
        'quotation' => ['quotations', 'number', 'عرض سعر', 'Quotation', null],
        'po' => ['purchase_orders', 'number', 'أمر شراء', 'Purchase order', null],
        'shipment' => ['inbound_shipments', 'number', 'شحنة واردة', 'Inbound shipment', null],
        'grn' => ['goods_receipts', 'number', 'إذن استلام', 'Goods receipt', null],
        'fo' => ['fulfillment_orders', 'number', 'أمر تجهيز', 'Fulfilment order', null],
        'trip' => ['trips', 'number', 'رحلة', 'Trip', null],
        'rtn' => ['returns', 'number', 'مرتجع', 'Return', null],
        'trf' => ['warehouse_transfers', 'number', 'تحويل مخزني', 'Stock transfer', null],
        'customerinvoice' => ['fin_customer_invoices', 'number', 'فاتورة عميل', 'Customer invoice', null],
        'jv' => ['fin_journals', 'number', 'قيد محاسبي', 'Journal entry', null],
        'exc' => ['exceptions', 'number', 'استثناء', 'Exception', null],
        'customer' => ['customers', 'code', 'عميل', 'Customer', 'name_ar'],
        'supplier' => ['suppliers', 'code', 'مورد', 'Supplier', 'name_ar'],
        'product' => ['products', 'sku', 'منتج', 'Product', 'name_ar'],
        'vehicle' => ['vehicles', 'code', 'مركبة', 'Vehicle', 'plate_ar'],
    ];

    /** @var array<string, bool> */
    private static array $installed = [];

    /** The kinds a link may name, for the picker. @return array<int, array{key:string, ar:string, en:string}> */
    public static function types(): array
    {
        $out = [];
        foreach (self::TYPES as $key => [$table, , $ar, $en]) {
            if (self::installed($table)) {
                $out[] = ['key' => $key, 'ar' => $ar, 'en' => $en];
            }
        }

        return $out;
    }

    /**
     * Does such a record exist? Never throws: this is what a form asks while a number is being typed.
     *
     * @return array{type:string, number:string, found:bool, labelAr:?string, labelEn:?string, title:?string}
     */
    public static function resolve(?string $type, ?string $number): array
    {
        $type = mb_strtolower(trim((string) $type));
        $number = trim((string) $number);
        $def = self::TYPES[$type] ?? null;
        $out = ['type' => $type, 'number' => $number, 'found' => false, 'labelAr' => $def[2] ?? null, 'labelEn' => $def[3] ?? null, 'title' => null];
        if (! $def || $number === '' || ! self::installed($def[0])) {
            return $out;
        }
        $row = DB::table($def[0])->where($def[1], $number)->first(array_values(array_filter([$def[1], $def[4]])));
        if (! $row) {
            return $out;
        }

        // the number as the record itself writes it (people type «so-2026-00012»)
        return array_merge($out, ['number' => (string) $row->{$def[1]}, 'found' => true, 'title' => $def[4] ? Ws::text($row->{$def[4]} ?? null, 190) : null]);
    }

    /**
     * The pair to store, checked. Both empty = no link. @return array{0:?string, 1:?string} [kind, number as the record writes it]
     */
    public static function check(mixed $type, mixed $number): array
    {
        $type = mb_strtolower(trim((string) $type));
        $number = trim((string) $number);
        if ($type === '' && $number === '') {
            return [null, null];
        }
        if (! isset(self::TYPES[$type]) || ! self::installed(self::TYPES[$type][0])) {
            throw AppError::validation('WS_RECORD_TYPE_UNKNOWN', 'نوع السجل المرتبط غير معروف — اختر النوع واكتب الرقم معًا', 'Unknown kind of linked record — pick the kind and type the number together', [['path' => 'refType', 'message' => 'نوع غير معروف']]);
        }
        $found = self::resolve($type, $number);
        if (! $found['found']) {
            throw AppError::validation('WS_RECORD_NOT_FOUND', "لا يوجد {$found['labelAr']} بالرقم «{$number}»", "No {$found['labelEn']} has the number “{$number}”", [['path' => 'refNumber', 'message' => 'السجل غير موجود']]);
        }

        return [$type, $found['number']];
    }

    /** @return array{type:string, number:string, labelAr:string, labelEn:string}|null */
    public static function shape(?string $type, ?string $number): ?array
    {
        if (! $type || ! $number) {
            return null;
        }
        $def = self::TYPES[$type] ?? null;

        return ['type' => $type, 'number' => $number, 'labelAr' => $def[2] ?? $type, 'labelEn' => $def[3] ?? $type];
    }

    private static function installed(string $table): bool
    {
        return self::$installed[$table] ??= Schema::hasTable($table);
    }
}
