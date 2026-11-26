<?php

namespace App\Planning\Services;

use App\Planning\Models\Run;
use App\Planning\Support\XlsxWriter;
use App\Support\AppError;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A run to Excel (one sheet per table: items, requirements, forecast periods, customer patterns and — when the run
 * asks for it — history) or one of its tables to CSV (UTF-8 with BOM, opens in Excel with the Arabic intact). Rows are
 * read in chunks and written as they come. Headers are bilingual.
 */
class ExportService
{
    public const SHEETS = ['items', 'requirements', 'periods', 'patterns', 'history'];

    public const MAX_ROWS = 300000;

    public function csv(Run $run, string $sheet): StreamedResponse
    {
        [$header, $rows] = $this->table($run, $sheet);

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows() as $r) {
                fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $r));
            }
            fclose($out);
        }, "{$run->number}-{$sheet}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function xlsx(Run $run, bool $history): BinaryFileResponse
    {
        $x = new XlsxWriter;
        $titles = ['items' => 'البنود Items', 'requirements' => 'المتطلبات Requirements', 'periods' => 'الفترات Periods', 'patterns' => 'أنماط العملاء Patterns', 'history' => 'السجل History'];
        foreach (self::SHEETS as $sheet) {
            if ($sheet === 'history' && ! $history) {
                continue;
            }
            [$header, $rows] = $this->table($run, $sheet);
            $x->sheet($titles[$sheet], $header);
            foreach ($rows() as $r) {
                $x->row($r);
            }
        }

        return response()->download($x->save(), "{$run->number}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    /** @return array{0:string[], 1:callable(): iterable<array>} */
    private function table(Run $run, string $sheet): array
    {
        $num = fn ($v) => $v === null ? null : (float) $v;
        $day = fn ($v) => $v === null ? null : substr((string) $v, 0, 10);
        $chunks = function (string $table, array $order, callable $map, ?callable $scope = null) use ($run) {
            return function () use ($table, $order, $map, $scope, $run) {
                $q = DB::table($table)->where('run_id', $run->id);
                if ($scope) {
                    $scope($q);
                }
                foreach ($order as $o) {
                    $q->orderBy($o);
                }
                $n = 0;
                foreach ($q->lazy(1000) as $r) {
                    if (++$n > self::MAX_ROWS) {
                        break;
                    }
                    yield $map($r);
                }
            };
        };
        $items = DB::table('planning_items')->where('run_id', $run->id)->pluck('sku', 'id')->all();
        $wh = DB::table('planning_items')->where('run_id', $run->id)->pluck('warehouse_code', 'id')->all();

        return match ($sheet) {
            'items' => [
                ['النوع Kind', 'الرمز SKU', 'المنتج Product', 'المستودع Warehouse', 'العميل Customer', 'الحالة Status', 'المتوفر On hand', 'وارد محجوز Booked in', 'صادر محجوز Booked out',
                    'إجمالي السجل History total', 'فترات السجل History periods', 'الطريقة المطلوبة Method asked', 'الطريقة المستخدمة Method used', 'سبب البديل Fallback reason',
                    'التوقع Forecast', 'في اليوم Per day', 'أدنى رصيد Lowest balance', 'أول عجز First shortage', 'العجز Shortage', 'الفائض Surplus', 'أيام التغطية Days of cover',
                    'مخزون الأمان Safety stock', 'مهلة التوريد Lead days', 'مصدر المهلة Lead source', 'الحد الأدنى للمورد Vendor minimum', 'مضاعف الطلب Multiple', 'الكمية المقترحة Suggested qty',
                    'اطلب قبل Order by', 'سعر الوحدة Unit price', 'مصدر السعر Price source', 'القيمة المقترحة Suggested value', 'المورد Vendor', 'الحد الأدنى المقترح Suggested min',
                    'الحد الأقصى المقترح Suggested max', 'الحد الحالي Current min', 'الأقصى الحالي Current max', 'حالة القاعدة Rule state', 'الثقة Confidence'],
                $chunks('planning_items', ['kind', 'sku', 'warehouse_code', 'customer_name', 'id'], fn ($r) => [
                    $r->kind, $r->sku, $r->product_name_ar, $r->warehouse_code, $r->customer_name, $r->status, $num($r->qty_on_hand), $num($r->incoming_qty), $num($r->outgoing_qty),
                    $num($r->history_total_qty), $r->history_periods_used === null ? null : (int) $r->history_periods_used, $run->method, $r->method_used, $r->fallback_reason,
                    $num($r->forecast_total_qty), $num($r->avg_daily_qty), $num($r->min_projected_balance), $day($r->first_shortage_date), $num($r->shortage_qty), $num($r->surplus_qty), $num($r->coverage_days),
                    $num($r->safety_qty), $num($r->lead_days), $r->lead_source, $num($r->moq), $num($r->qty_multiple), $num($r->suggested_purchase_qty), $day($r->suggested_purchase_date),
                    $num($r->price_unit), $r->price_source, $num($r->suggested_purchase_value), $r->vendor_name, $num($r->suggested_min_qty), $num($r->suggested_max_qty),
                    $num($r->current_min_qty), $num($r->current_max_qty), $r->orderpoint_state, $num($r->confidence_score),
                ]),
            ],
            'requirements' => [
                ['الرمز SKU', 'المنتج Product', 'المستودع Warehouse', 'الفترة Period', 'تاريخ الحاجة Need date', 'اطلب قبل Order by', 'متأخر Late', 'طلب الفترة Period demand',
                    'رصيد البداية Opening', 'الرصيد بلا شراء Balance without purchase', 'مخزون الأمان Safety', 'النفاد Stock-out', 'الصافي Net', 'الحد الأدنى للمورد Vendor minimum',
                    'المضاعف Multiple', 'كمية الشراء Purchase qty', 'المورد Vendor', 'سعر الوحدة Unit price', 'مصدر السعر Price source', 'القيمة Value', 'الأهمية Criticality', 'مهلة Lead days'],
                $chunks('planning_requirements', ['trigger_date', 'sku', 'warehouse_code', 'id'], fn ($r) => [
                    $r->sku, $r->product_name_ar, $r->warehouse_code, $r->period_label, $day($r->need_date), $day($r->trigger_date), (bool) $r->late, $num($r->demand_qty), $num($r->opening_balance_qty),
                    $num($r->projected_balance_qty), $num($r->safety_qty), $num($r->shortage_qty), $num($r->net_qty), $num($r->moq), $num($r->qty_multiple), $num($r->qty), $r->vendor_name,
                    $num($r->price_unit), $r->price_source, $num($r->value), $r->product_criticality, $num($r->lead_days),
                ]),
            ],
            'periods' => [
                ['الرمز SKU', 'المستودع Warehouse', 'الفترة Period', 'من From', 'إلى To', 'الأيام Days', 'العام الماضي Same dates last year', 'التوقع Forecast', 'صادر محجوز Booked out',
                    'وارد محجوز Booked in', 'طلب غير مغطى Unmet', 'رصيد البداية Opening', 'الرصيد المتوقع Projected', 'العجز Shortage', 'الفائض Surplus', 'أيام التغطية Days of cover'],
                $chunks('planning_periods', ['item_id', 'period_index', 'id'], fn ($r) => [
                    $items[$r->item_id] ?? null, $wh[$r->item_id] ?? null, $r->period_label, $day($r->date_from), $day($r->date_to), (int) $r->day_count, $num($r->history_qty), $num($r->forecast_demand_qty),
                    $num($r->scheduled_out_qty), $num($r->scheduled_in_qty), $num($r->unmet_demand_qty), $num($r->opening_balance_qty), $num($r->projected_balance_qty), $num($r->shortage_qty),
                    $num($r->surplus_qty), $num($r->coverage_days),
                ]),
            ],
            'patterns' => [
                ['الرمز SKU', 'العميل Customer', 'الطلبات Orders', 'أول طلب First order', 'آخر طلب Last order', 'الفواصل Intervals', 'الكميات Quantities', 'متوسط الفاصل Avg interval',
                    'متوسط الكمية Avg qty', 'الاتجاه Trend', 'الكمية المتوقعة Expected qty', 'الطلب التالي Next expected', 'أيام التأخر Days overdue', 'الحالة State', 'الثقة Confidence',
                    'التوقع في الأفق Forecast in horizon', 'عدد الطلبات المتوقعة Expected orders'],
                $chunks('planning_patterns', ['sku', 'customer_name', 'id'], fn ($r) => [
                    $r->sku, $r->customer_name, (int) $r->order_count, $day($r->first_order_date), $day($r->last_order_date), $r->interval_history, $r->quantity_history, $num($r->avg_interval_days),
                    $num($r->avg_order_qty), $num($r->trend_factor), $num($r->expected_order_qty), $day($r->next_expected_date), (int) $r->overdue_days, $r->state, $num($r->confidence_score),
                    $num($r->forecast_qty), (int) $r->expected_order_count,
                ]),
            ],
            'history' => [
                ['الرمز SKU', 'المستودع Warehouse', 'الفترة Period', 'من From', 'إلى To', 'الأيام Days', 'الكمية Quantity', 'محسوبة Used', 'المصدر Source'],
                $chunks('planning_history', ['item_id', 'period_index', 'id'], fn ($r) => [
                    $items[$r->item_id] ?? null, $wh[$r->item_id] ?? null, $r->period_label, $day($r->date_from), $day($r->date_to), (int) $r->day_count, $num($r->qty), (bool) $r->used, $r->source,
                ]),
            ],
            default => throw AppError::validation('PLANNING_EXPORT_SHEET', 'جدول غير معروف للتصدير', 'Unknown table to export'),
        };
    }
}
