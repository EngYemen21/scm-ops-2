<?php

namespace App\Services\Platform;

use App\Support\AppError;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Helpers shared by the dashboard, the tower and the reports: date ranges, number normalisation (MySQL returns
 * SUM/DECIMAL as strings — the API always answers plain numbers), report columns, the cost gate and the CSV writer.
 */
final class ReportSupport
{
    /** Storage zones whose stock counts as sellable on the dashboard. */
    public const STORAGE_ZONES = ['ambient', 'chilled', 'frozen', 'hazmat'];

    public const REPORT_NAMES = [
        'inventory', 'stock-movement', 'inventory-aging', 'expiry', 'receiving', 'grn', 'supplier-performance', 'purchase-orders',
        'picking-performance', 'fulfillment', 'delivery-performance', 'fleet-utilization', 'trip-performance', 'returns', 'exceptions',
    ];

    /** Catalogue shown by GET /reports: bilingual titles, whether the report is a live snapshot (no date range) and its default range. */
    public const REPORT_META = [
        'inventory' => ['titleAr' => 'أرصدة المخزون', 'titleEn' => 'Inventory balances', 'group' => 'inventory', 'snapshot' => true, 'defaultDays' => 0],
        'stock-movement' => ['titleAr' => 'حركات المخزون', 'titleEn' => 'Stock movement', 'group' => 'inventory', 'defaultDays' => 30],
        'inventory-aging' => ['titleAr' => 'تقادم المخزون', 'titleEn' => 'Inventory aging', 'group' => 'inventory', 'snapshot' => true, 'defaultDays' => 0],
        'expiry' => ['titleAr' => 'الصلاحية', 'titleEn' => 'Expiry', 'group' => 'inventory', 'snapshot' => true, 'defaultDays' => 0],
        'receiving' => ['titleAr' => 'الاستلام', 'titleEn' => 'Receiving', 'group' => 'inbound', 'defaultDays' => 30],
        'grn' => ['titleAr' => 'إشعارات الاستلام GRN', 'titleEn' => 'GRN list', 'group' => 'inbound', 'defaultDays' => 90],
        'supplier-performance' => ['titleAr' => 'أداء الموردين', 'titleEn' => 'Supplier performance', 'group' => 'procurement', 'defaultDays' => 90],
        'purchase-orders' => ['titleAr' => 'أوامر الشراء', 'titleEn' => 'Purchase orders', 'group' => 'procurement', 'defaultDays' => 90],
        'picking-performance' => ['titleAr' => 'أداء التجهيز', 'titleEn' => 'Picking performance', 'group' => 'fulfillment', 'defaultDays' => 30],
        'fulfillment' => ['titleAr' => 'أوامر التنفيذ', 'titleEn' => 'Fulfillment', 'group' => 'fulfillment', 'defaultDays' => 30],
        'delivery-performance' => ['titleAr' => 'أداء التوصيل', 'titleEn' => 'Delivery performance', 'group' => 'delivery', 'defaultDays' => 30],
        'fleet-utilization' => ['titleAr' => 'استغلال الأسطول', 'titleEn' => 'Fleet utilization', 'group' => 'fleet', 'defaultDays' => 30],
        'trip-performance' => ['titleAr' => 'أداء الرحلات', 'titleEn' => 'Trip performance', 'group' => 'fleet', 'defaultDays' => 30],
        'returns' => ['titleAr' => 'المرتجعات', 'titleEn' => 'Returns', 'group' => 'returns', 'defaultDays' => 90],
        'exceptions' => ['titleAr' => 'الاستثناءات', 'titleEn' => 'Exceptions', 'group' => 'platform', 'defaultDays' => 90],
    ];

    // ───────────── dates ─────────────
    public static function startOfToday(): Carbon
    {
        return now()->startOfDay();
    }

    /** @return array{from:Carbon, to:Carbon} */
    public static function todayRange(): array
    {
        $from = self::startOfToday();

        return ['from' => $from, 'to' => $from->copy()->addDay()];
    }

    /** Database literal of an instant (columns are UTC DATETIME(3)). */
    public static function db(Carbon $d): string
    {
        return $d->format('Y-m-d H:i:s.v');
    }

    /** ISO-8601 with milliseconds, the way the reference serialises every date. */
    public static function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return ($v instanceof Carbon ? $v : Carbon::parse((string) $v, 'UTC'))->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function parseDate(?string $s, bool $endOfDay = false): ?Carbon
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        try {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
                throw new \InvalidArgumentException('not a date');
            }
            $d = Carbon::parse($s, 'UTC')->utc();
        } catch (Throwable) {
            throw AppError::validation('INVALID_DATE', "تاريخ غير صالح: {$s}", "Invalid date: {$s}");
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $d = $d->endOfDay();
        }

        return $d;
    }

    /**
     * Report date range — defaults to the last $defaultDays days so no report is ever unbounded.
     *
     * @return array{from:Carbon, to:Carbon}
     */
    public static function dateRange(?string $from, ?string $to, int $defaultDays = 30): array
    {
        $t = self::parseDate($to, true) ?? now();
        $f = self::parseDate($from) ?? self::startOfToday()->subDays($defaultDays);
        if ($f->gt($t)) {
            throw AppError::validation('INVALID_RANGE', 'بداية الفترة بعد نهايتها', 'Range start is after its end');
        }

        return ['from' => $f, 'to' => $t];
    }

    // ───────────── numbers ─────────────
    /** null → 0; numeric strings → int or float (whatever the value is). */
    public static function num(mixed $v): int|float
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return (int) $v;
        }
        $n = is_numeric($v) ? $v + 0 : 0;

        return is_float($n) && floor($n) === $n && abs($n) < 9e15 ? (int) $n : $n;
    }

    public static function round(int|float $v, int $d = 1): int|float
    {
        return self::num(round($v, $d));
    }

    public static function pct(int|float $n, int|float $d): int|float|null
    {
        return $d > 0 ? self::round(($n / $d) * 100, 1) : null;
    }

    /** 12500.5 → "12,500.5" (en-US grouping, up to 3 decimals, no trailing zeros). */
    public static function fmt(mixed $v): string
    {
        $s = number_format((float) self::num($v), 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    // ───────────── report pages ─────────────
    /** @return array{key:string, labelAr:string, labelEn:string, type:string} */
    public static function col(string $key, string $labelAr, string $labelEn, string $type = 'text'): array
    {
        return ['key' => $key, 'labelAr' => $labelAr, 'labelEn' => $labelEn, 'type' => $type];
    }

    /** Normalises one raw SQL row by the declared column types (numbers, booleans, ISO dates). */
    public static function castRow(array $columns, array $row): array
    {
        $out = [];
        $types = array_column($columns, 'type', 'key');
        foreach ($row as $key => $value) {
            $out[$key] = match (true) {
                $value === null => null,
                in_array($types[$key] ?? 'text', ['number', 'money', 'pct'], true) => self::num($value),
                ($types[$key] ?? null) === 'bool' => (bool) $value,
                in_array($types[$key] ?? null, ['date', 'datetime'], true) => self::iso($value),
                default => $value,
            };
        }

        return $out;
    }

    /** Removes money columns (and their row values) for users without inventory.view_cost. */
    public static function stripCost(array $page, bool $canCost): array
    {
        if ($canCost) {
            return $page;
        }
        $moneyKeys = array_column(array_filter($page['columns'], fn ($c) => $c['type'] === 'money'), 'key');
        if (! $moneyKeys) {
            return $page;
        }
        $drop = array_flip($moneyKeys);
        $page['rows'] = array_map(fn ($r) => array_diff_key($r, $drop), $page['rows']);
        if (isset($page['totals'])) {
            $page['totals'] = array_diff_key($page['totals'], $drop);
        }
        $page['columns'] = array_values(array_filter($page['columns'], fn ($c) => $c['type'] !== 'money'));

        return $page;
    }

    /** CSV with UTF-8 BOM (Excel-friendly for Arabic) and Arabic headers. */
    public static function toCsv(array $columns, array $rows): string
    {
        $esc = function (mixed $v): string {
            if ($v === null) {
                return '';
            }
            $s = match (true) {
                is_bool($v) => $v ? 'true' : 'false',
                is_array($v), is_object($v) => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                default => (string) $v,
            };

            return preg_match('/[",\r\n]/', $s) ? '"'.str_replace('"', '""', $s).'"' : $s;
        };
        $lines = [implode(',', array_map(fn ($c) => $esc($c['labelAr']), $columns))];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($c) => $esc($r[$c['key']] ?? null), $columns));
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /** LIKE pattern with the user's wildcards escaped. */
    public static function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }
}
