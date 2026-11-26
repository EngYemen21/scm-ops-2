<?php

namespace App\Insights\Support;

/**
 * The eight alert types of a brand owner's dashboard (Odoo `brand.owner.dashboard.alert.alert_type`): the rule of
 * each, written once here and shown beside the alert. "Purchases" are always the franchisee's NET purchases of the
 * brand's products (invoices − credit notes, before VAT, App\Insights\Services\SalesFacts). "Last month" is the last
 * COMPLETE calendar month before the day the rules run. Each alert is raised once per occurrence (`key`).
 *
 * `threshold` and `window` are configurable per type (table `ins_alert_rules`, PUT /insights/alert-rules/{type});
 * `unit` says what the window counts.
 */
final class AlertRules
{
    /** type → default severity, threshold, window, window unit, occurrence, bilingual rule */
    public const DEFAULTS = [
        'no_purchase' => [
            'severity' => 'danger', 'threshold' => 0, 'window' => 30, 'unit' => 'days',
            'ar' => 'فرع نشط (نشط أو ضعيف) لم يشترِ أي منتج من العلامة منذ أكثر من {window} يومًا. يُرفع مرة لكل فترة انقطاع (تبدأ من تاريخ آخر شراء).',
            'en' => 'An active (or weak) franchisee bought none of the brand\'s products for more than {window} days. Raised once per silence (keyed by the date of the last purchase).',
        ],
        'purchase_drop' => [
            'severity' => 'warning', 'threshold' => 30, 'window' => 3, 'unit' => 'months',
            'ar' => 'مشتريات الشهر الماضي أقل من متوسط الأشهر الـ{window} السابقة له بنسبة {threshold}% أو أكثر (والمتوسط أكبر من صفر). مرة لكل فرع وشهر.',
            'en' => 'Last month\'s purchases are {threshold}% or more below the average of the {window} months before it (and that average is above zero). Once per franchisee and month.',
        ],
        'target_exceeded' => [
            'severity' => 'success', 'threshold' => 100, 'window' => 1, 'unit' => 'months',
            'ar' => 'مشتريات الشهر الماضي بلغت {threshold}% أو أكثر من الهدف الشهري للفرع (والهدف أكبر من صفر). مرة لكل فرع وشهر.',
            'en' => 'Last month\'s purchases reached {threshold}% or more of the franchisee\'s monthly target (target above zero). Once per franchisee and month.',
        ],
        'target_not_met' => [
            'severity' => 'warning', 'threshold' => 80, 'window' => 1, 'unit' => 'months',
            'ar' => 'مشتريات الشهر الماضي أقل من {threshold}% من الهدف الشهري للفرع (والهدف أكبر من صفر والفرع بدأ قبل ذلك الشهر). مرة لكل فرع وشهر.',
            'en' => 'Last month\'s purchases were below {threshold}% of the franchisee\'s monthly target (target above zero, franchisee started before that month). Once per franchisee and month.',
        ],
        'cancelled_orders' => [
            'severity' => 'warning', 'threshold' => 2, 'window' => 30, 'unit' => 'days',
            'ar' => 'ألغى الفرع {threshold} طلبات بيع أو أكثر فيها منتجات العلامة خلال آخر {window} يومًا (طلبات العمليات). مرة لكل فرع وشهر.',
            'en' => 'The franchisee had {threshold} or more sales orders carrying the brand\'s products cancelled in the last {window} days (OPS orders). Once per franchisee and month.',
        ],
        'product_drop' => [
            'severity' => 'info', 'threshold' => 50, 'window' => 3, 'unit' => 'months',
            'ar' => 'منتج اشتراه الفرع في كل شهر من الأشهر الـ{window} السابقة للشهر الماضي، وكميته في الشهر الماضي أقل من متوسطها بنسبة {threshold}% أو أكثر. مرة لكل فرع ومنتج وشهر.',
            'en' => 'A product the franchisee bought in each of the {window} months before last month whose quantity last month is {threshold}% or more below that average. Once per franchisee, product and month.',
        ],
        'overdue_invoice' => [
            'severity' => 'danger', 'threshold' => 0, 'window' => 0, 'unit' => 'days',
            'ar' => 'فاتورة مفتوحة للفرع فيها منتجات العلامة تجاوزت تاريخ استحقاقها بأكثر من {window} يومًا. مرة لكل فاتورة.',
            'en' => 'An open invoice of the franchisee carrying the brand\'s products is more than {window} days past its due date. Once per invoice.',
        ],
        'high_returns' => [
            'severity' => 'warning', 'threshold' => 10, 'window' => 1, 'unit' => 'months',
            'ar' => 'إشعارات الدائن (المرتجعات) لمنتجات العلامة في الشهر الماضي بلغت {threshold}% أو أكثر من فواتيرها في الشهر نفسه. مرة لكل فرع وشهر.',
            'en' => 'Credit notes (returns) of the brand\'s products last month reached {threshold}% or more of its invoices of that month. Once per franchisee and month.',
        ],
    ];

    public const LABELS = [
        'no_purchase' => ['ar' => 'انقطاع الشراء', 'en' => 'No purchase'],
        'purchase_drop' => ['ar' => 'انخفاض المشتريات', 'en' => 'Purchase drop'],
        'target_exceeded' => ['ar' => 'تجاوز الهدف', 'en' => 'Target exceeded'],
        'target_not_met' => ['ar' => 'الهدف لم يتحقق', 'en' => 'Target not met'],
        'cancelled_orders' => ['ar' => 'طلبات ملغاة', 'en' => 'Cancelled orders'],
        'product_drop' => ['ar' => 'انخفاض منتج', 'en' => 'Product drop'],
        'overdue_invoice' => ['ar' => 'فاتورة متأخرة', 'en' => 'Overdue invoice'],
        'high_returns' => ['ar' => 'مرتجعات مرتفعة', 'en' => 'High returns'],
    ];

    /** The rule as people read it, with the configured numbers in place. @return array{ar:string, en:string} */
    public static function text(string $type, float $threshold, int $window): array
    {
        $d = self::DEFAULTS[$type];
        $fill = fn (string $s) => strtr($s, ['{threshold}' => rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.'), '{window}' => (string) $window]);

        return ['ar' => $fill($d['ar']), 'en' => $fill($d['en'])];
    }
}
