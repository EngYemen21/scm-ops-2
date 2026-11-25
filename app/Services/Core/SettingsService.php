<?php

namespace App\Services\Core;

use App\Models\SystemSetting;

/**
 * Configurable business policies. Defaults are the values validated with the business; an administrator can
 * override any of them from the Settings page (stored in system_settings).
 */
class SettingsService
{
    public const DEFAULTS = [
        'procurement.approvalTiers' => ['value' => [['max' => 5000, 'roles' => ['proc']], ['max' => 25000, 'roles' => ['proc', 'finance']], ['max' => null, 'roles' => ['proc', 'finance', 'gm']]], 'group' => 'procurement', 'description' => 'PO approval chain by total (SAR)'],
        'procurement.minSupplierScore' => ['value' => 65, 'group' => 'procurement', 'description' => 'Suppliers below this score need a procurement-manager exception for new POs'],
        'procurement.newSupplierScore' => ['value' => 70, 'group' => 'procurement', 'description' => 'Initial score for a new supplier'],
        'procurement.prApprovalTiers' => ['value' => [['max' => null, 'roles' => ['proc']]], 'group' => 'procurement', 'description' => 'Requisition approval chain by estimated value: first tier whose max is null or ≥ the estimate'],
        'procurement.allowSelfApproval' => ['value' => false, 'group' => 'procurement', 'description' => 'Allow the person who created a requisition / purchase order to approve it (segregation of duties off)'],
        'procurement.minQuotes' => ['value' => 3, 'group' => 'procurement', 'description' => 'Quotations required before an RFQ can be awarded; fewer needs a documented exception'],
        'procurement.invoiceTolerancePct' => ['value' => 2, 'group' => 'procurement', 'description' => 'Supplier invoice price / total may differ from the PO by this percentage before it is disputed'],
        'finance.fleetExpensesIncludeVat' => ['value' => false, 'group' => 'finance', 'description' => 'Fuel / maintenance amounts include 15% VAT: split VAT input out of them (CFO decision)'],
        'finance.customerTermsDays' => ['value' => 0, 'group' => 'finance', 'description' => 'Default credit days for customer invoices when the customer has none (0 = due on delivery)'],
        'procurement.supplierDocWarnDays' => ['value' => 30, 'group' => 'procurement', 'description' => 'Warn when a supplier document expires within N days'],
        'receiving.allowExcess' => ['value' => false, 'group' => 'receiving', 'description' => 'Allow receiving more than the open PO quantity'],
        'inventory.autoQuarantineDays' => ['value' => 7, 'group' => 'inventory', 'description' => 'Batches expiring within N days are auto-quarantined by the daily job'],
        'inventory.expiringSoonDays' => ['value' => 30, 'group' => 'inventory', 'description' => 'Dashboard "expiring soon" window'],
        'sales.vatPct' => ['value' => 15, 'group' => 'sales', 'description' => 'VAT percentage'],
        'sales.maxDiscountPct' => ['value' => 30, 'group' => 'sales', 'description' => 'A discount above this needs the approval of a holder of discount.approve (general manager)'],
        'sales.enforceCreditLimit' => ['value' => true, 'group' => 'sales', 'description' => 'Reject SO when customer balance + order exceeds credit limit'],
        'delivery.autoReturnOnPartial' => ['value' => true, 'group' => 'delivery', 'description' => 'Partial delivery creates a customer return for the remainder'],
        'exceptions.sla' => ['value' => ['damage' => 48, 'rejected' => 48, 'shortage' => 24, 'wrongloc' => 8, 'wrongveh' => 2, 'capacity' => 1, 'faildel' => 4, 'partial' => 8, 'temp' => 2, 'transfer' => 24, 'other' => 24], 'group' => 'exceptions', 'description' => 'SLA hours per exception kind'],
        'fleet.docExpiryWarnDays' => ['value' => 30, 'group' => 'fleet', 'description' => 'Warn when vehicle/driver documents expire within N days'],
        'fleet.driverLicenseMinDays' => ['value' => 30, 'group' => 'fleet', 'description' => 'A driver whose license expires within N days cannot be activated/assigned'],
        'fleet.fuelMaxPricePerLiter' => ['value' => 4, 'group' => 'fleet', 'description' => 'Fuel record validation ceiling (SAR/L)'],
        'fleet.fuelAnomalyRatio' => ['value' => 0.75, 'group' => 'fleet', 'description' => 'km/L below this fraction of vehicle average is flagged'],
        'fleet.maintenanceSoonKm' => ['value' => 3000, 'group' => 'fleet', 'description' => 'Vehicle recommendation penalty when maintenance is due within N km'],
        'loading.cbmPerCarton' => ['value' => 0.06, 'group' => 'loading', 'description' => 'Fallback CBM per carton when an order has no volume'],
        'company' => ['value' => ['nameAr' => 'B2B', 'nameEn' => 'B2B', 'currency' => 'SAR'], 'group' => 'general', 'description' => 'Company profile'],
        // ── operational hardening 2026-11 (docs/ops-hardening/DECISIONS.ar.md)
        'sales.allowBackorder' => ['value' => true, 'group' => 'sales', 'description' => 'An order with a shortage may be accepted: what is available ships, the rest becomes a linked backorder order'],
        'sales.zeroCreditLimitUnlimited' => ['value' => true, 'group' => 'sales', 'description' => 'A customer with credit limit 0 is not limited (off = a zero limit means no credit: orders are refused while anything is unpaid)'],
        'fulfillment.backorderShortPicks' => ['value' => true, 'group' => 'fulfillment', 'description' => 'The quantity of a short pick becomes a backorder order for the customer (off = it is cancelled and Sales is notified)'],
        'fulfillment.blockBinOnShortPick' => ['value' => true, 'group' => 'fulfillment', 'description' => 'A short pick freezes the stock row of that bin until it is counted, so the same empty bin is not allocated again'],
        'loading.enforceSequence' => ['value' => true, 'group' => 'loading', 'description' => 'An order cannot be loaded while an order of a later stop is still being prepared (last stop loads first)'],
        'loading.requireOrderScan' => ['value' => true, 'group' => 'loading', 'description' => 'Loading needs the scan of the order, its sales order, its package or one of its cartons (a supervisor may override)'],
        'delivery.requireScan' => ['value' => true, 'group' => 'delivery', 'description' => 'Delivery needs the scan of one carton / label of the order at the customer, or a written reason'],
        'delivery.requireSignature' => ['value' => true, 'group' => 'delivery', 'description' => 'A delivery needs the receiver signature or a photo (dispatch recording on behalf of the driver gives a reason instead)'],
        'delivery.serviceMinutes' => ['value' => 15, 'group' => 'delivery', 'description' => 'Minutes spent at each stop when planned arrival times are computed'],
        'fleet.avgSpeedKph' => ['value' => 40, 'group' => 'fleet', 'description' => 'Average speed used for planned arrival times when no maps provider answers'],
        'fleet.driverHourlyCost' => ['value' => 0, 'group' => 'fleet', 'description' => 'Driver cost per hour for trip costing when the driver has none of his own (0 = not configured)'],
        'fleet.overtimeAfterHours' => ['value' => 8, 'group' => 'fleet', 'description' => 'Trip hours after which driver time is costed as overtime'],
        'fleet.overtimeMultiplier' => ['value' => 1.5, 'group' => 'fleet', 'description' => 'Overtime rate as a multiple of the hourly cost'],
        'fleet.maintCostPerKm' => ['value' => 0, 'group' => 'fleet', 'description' => 'Maintenance cost per km for trip costing when the vehicle has no history of its own (0 = not configured)'],
        'fleet.depreciationPerKm' => ['value' => 0, 'group' => 'fleet', 'description' => 'Depreciation per km for trip costing when the vehicle has no purchase cost / life (0 = not configured)'],
        'returns.requireBatchOnRestock' => ['value' => true, 'group' => 'returns', 'description' => 'A product with expiry tracking is restocked from a return only with its batch and expiry date'],
        'transfers.requireCarrier' => ['value' => false, 'group' => 'transfers', 'description' => 'A transfer cannot ship without a vehicle and driver (a trip) or a named carrier'],
        'procurement.rejectedStaysOpen' => ['value' => true, 'group' => 'procurement', 'description' => 'Quantity rejected at receiving stays open on the purchase order for redelivery (off = it counts as fulfilled)'],
        'procurement.prPriceTolerancePct' => ['value' => 10, 'group' => 'procurement', 'description' => 'A purchase order price above the requisition estimate by more than this percentage needs a written reason and finance approval'],
        // ── the sections built for what Odoo has (docs/odoo/COVERAGE.ar.md)
        'preq.allowSelfApproval' => ['value' => false, 'group' => 'catalogue', 'description' => 'Allow the person who raised a new-product request to approve it (segregation of duties off)'],
    ];

    /** Arabic text of each policy for the settings page (`descriptionAr`); `description` stays the English one. */
    public const DESCRIPTIONS_AR = [
        'procurement.approvalTiers' => 'سلسلة اعتماد أمر الشراء حسب الإجمالي (ر.س)',
        'procurement.minSupplierScore' => 'المورد الذي يقل تقييمه عن هذا الحد يحتاج استثناءً من مدير المشتريات لإصدار أمر شراء جديد',
        'procurement.newSupplierScore' => 'التقييم الابتدائي للمورد الجديد',
        'procurement.prApprovalTiers' => 'سلسلة اعتماد طلب الشراء حسب القيمة التقديرية: تُطبَّق أول شريحة حدّها مفتوح أو لا يقل عن القيمة',
        'procurement.allowSelfApproval' => 'السماح لمنشئ طلب الشراء أو أمر الشراء باعتماده بنفسه (يُعطّل فصل المهام)',
        'procurement.minQuotes' => 'عدد عروض الأسعار المطلوب قبل ترسية طلب العروض؛ الأقل يتطلب استثناءً موثقًا',
        'procurement.invoiceTolerancePct' => 'نسبة الفرق المسموح بها بين فاتورة المورد وأمر الشراء (سعرًا أو إجماليًا) قبل اعتبارها محل خلاف',
        'finance.fleetExpensesIncludeVat' => 'مبالغ الوقود والصيانة تشمل ضريبة 15%: تُفصل ضريبة المدخلات منها (قرار المدير المالي)',
        'finance.customerTermsDays' => 'أيام الائتمان الافتراضية لفواتير العملاء عند عدم تحديدها للعميل (0 = تستحق عند التسليم)',
        'procurement.supplierDocWarnDays' => 'التنبيه عند انتهاء مستند المورد خلال N يومًا',
        'receiving.allowExcess' => 'السماح باستلام كمية تزيد على المفتوح في أمر الشراء',
        'inventory.autoQuarantineDays' => 'الدفعات التي تنتهي صلاحيتها خلال N يومًا تُحجر آليًا بالمهمة اليومية',
        'inventory.expiringSoonDays' => 'نافذة «قريب الانتهاء» في لوحة المتابعة (بالأيام)',
        'sales.vatPct' => 'نسبة ضريبة القيمة المضافة',
        'sales.maxDiscountPct' => 'الخصم الأعلى من هذه النسبة يحتاج اعتماد صاحب صلاحية اعتماد الخصم (المدير العام)',
        'sales.enforceCreditLimit' => 'رفض أمر البيع إذا تجاوز رصيد العميل مع الطلب حدّه الائتماني',
        'delivery.autoReturnOnPartial' => 'التسليم الجزئي يُنشئ مرتجع عميل بالكمية المتبقية',
        'exceptions.sla' => 'ساعات اتفاقية مستوى الخدمة (SLA) لكل نوع استثناء',
        'fleet.docExpiryWarnDays' => 'التنبيه عند انتهاء مستندات المركبة أو السائق خلال N يومًا',
        'fleet.driverLicenseMinDays' => 'السائق الذي تنتهي رخصته خلال N يومًا لا يُفعَّل ولا يُسند إليه',
        'fleet.fuelMaxPricePerLiter' => 'الحد الأعلى لسعر اللتر المقبول في سجل الوقود (ر.س/لتر)',
        'fleet.fuelAnomalyRatio' => 'يُعلَّم الاستهلاك إذا قلّ معدل كم/لتر عن هذه النسبة من متوسط المركبة',
        'fleet.maintenanceSoonKm' => 'خفض ترشيح المركبة عند استحقاق صيانتها خلال N كم',
        'loading.cbmPerCarton' => 'الحجم الافتراضي للكرتون (م³) عند غياب حجم الطلب',
        'company' => 'بيانات الشركة',
        'sales.allowBackorder' => 'قبول الطلب مع نقص: يُشحن المتاح ويصبح الباقي طلبًا معلّقًا مرتبطًا بالأصل',
        'sales.zeroCreditLimitUnlimited' => 'العميل بحد ائتماني 0 غير مقيَّد (عند التعطيل: الصفر يعني بلا ائتمان، فيُرفض الطلب ما دام عليه مبلغ غير مسدد)',
        'fulfillment.backorderShortPicks' => 'كمية نقص التجهيز تصبح طلبًا معلّقًا للعميل (عند التعطيل: تُلغى ويُبلَّغ المبيعات)',
        'fulfillment.blockBinOnShortPick' => 'نقص التجهيز يجمّد رصيد ذلك الموقع حتى جرده، فلا يُخصَّص الموقع الفارغ نفسه مرة أخرى',
        'loading.enforceSequence' => 'لا يُحمَّل طلب ما دام طلب محطة لاحقة قيد التجهيز (آخر محطة تُحمَّل أولًا)',
        'loading.requireOrderScan' => 'التحميل يتطلب مسح الطلب أو أمر البيع أو الطرد أو أحد كراتينه (يستطيع المشرف التجاوز)',
        'delivery.requireScan' => 'التسليم يتطلب مسح كرتون أو ملصق من الطلب عند العميل، أو كتابة سبب',
        'delivery.requireSignature' => 'التسليم يتطلب توقيع المستلم أو صورة (التسجيل بالنيابة عن السائق يكتب سببًا بدلًا منهما)',
        'delivery.serviceMinutes' => 'الدقائق المحتسبة لكل محطة عند حساب أوقات الوصول المخططة',
        'fleet.avgSpeedKph' => 'متوسط السرعة لحساب أوقات الوصول المخططة عند غياب مزوّد الخرائط',
        'fleet.driverHourlyCost' => 'تكلفة ساعة السائق في تكلفة الرحلة إذا لم تُحدَّد للسائق نفسه (0 = غير مُعدّة)',
        'fleet.overtimeAfterHours' => 'ساعات الرحلة التي يبدأ بعدها احتساب الوقت الإضافي للسائق',
        'fleet.overtimeMultiplier' => 'معامل أجر الوقت الإضافي نسبةً إلى تكلفة الساعة',
        'fleet.maintCostPerKm' => 'تكلفة الصيانة لكل كم في تكلفة الرحلة إذا لم يكن للمركبة سجل صيانة كافٍ (0 = غير مُعدّة)',
        'fleet.depreciationPerKm' => 'الإهلاك لكل كم في تكلفة الرحلة إذا لم تُسجَّل تكلفة شراء المركبة وعمرها (0 = غير مُعدّ)',
        'returns.requireBatchOnRestock' => 'المنتج المتتبَّع بالصلاحية لا يُعاد للمخزون من مرتجع إلا بدفعته وتاريخ انتهائه',
        'transfers.requireCarrier' => 'لا يُشحن التحويل بلا مركبة وسائق (رحلة) أو ناقل مسمّى',
        'procurement.rejectedStaysOpen' => 'الكمية المرفوضة عند الاستلام تبقى مفتوحة على أمر الشراء لإعادة توريدها (عند التعطيل: تُحتسب منفَّذة)',
        'procurement.prPriceTolerancePct' => 'سعر أمر الشراء الذي يزيد على تقدير طلب الشراء بأكثر من هذه النسبة يتطلب سببًا مكتوبًا واعتماد المالية',
        'preq.allowSelfApproval' => 'السماح لمن رفع طلب صنف جديد باعتماده بنفسه (يُعطّل فصل المهام)',
    ];

    /** @var array<string, mixed> per-process cache; cleared on set() and by flush() (tests). */
    private array $cache = [];

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $row = SystemSetting::find($key);

        return $this->cache[$key] = $row ? $row->value : (self::DEFAULTS[$key]['value'] ?? null);
    }

    public function set(string $key, mixed $value, ?string $userId = null): void
    {
        $default = self::DEFAULTS[$key] ?? null;
        SystemSetting::updateOrCreate(['key' => $key], [
            'value' => $value,
            'group' => $default['group'] ?? 'general',
            'description' => $default['description'] ?? null,
            'updated_by_id' => $userId,
        ]);
        unset($this->cache[$key]);
    }

    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $d) {
            $out[$key] = ['value' => $d['value'], 'group' => $d['group'], 'description' => $d['description'], 'isDefault' => true, 'descriptionAr' => self::DESCRIPTIONS_AR[$key] ?? null];
        }
        foreach (SystemSetting::all() as $row) {
            $out[$row->key] = ['value' => $row->value, 'group' => $row->group, 'description' => $row->description, 'isDefault' => false, 'updatedAt' => $row->updated_at, 'descriptionAr' => self::DESCRIPTIONS_AR[$row->key] ?? null];
        }

        return $out;
    }

    public function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $key => $d) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => $d['value'], 'group' => $d['group'], 'description' => $d['description']]);
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
