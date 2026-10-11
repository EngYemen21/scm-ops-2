<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\SupplierDocument;
use App\Services\Core\NotifyService;
use App\Services\Core\SettingsService;
use App\Services\Procurement\ProcurementHelpers as H;
use Illuminate\Support\Facades\Cache;

/**
 * Follow-up alerts that need no person to trigger them: a purchase order past its delivery date, and a supplier
 * document about to expire. Each alert is sent once (stamped on the row). Runs from the integration heartbeat /
 * scheduler at most once an hour (`run()`), or on demand (`runNow()`).
 */
class ProcurementHousekeeping
{
    private const LOCK_KEY = 'procurement:housekeeping:last';

    public function __construct(private readonly NotifyService $notify, private readonly SettingsService $settings) {}

    /** @return array{ran: bool, latePos?: int, expiringDocs?: int} */
    public function run(): array
    {
        if (Cache::has(self::LOCK_KEY)) {
            return ['ran' => false];
        }
        Cache::put(self::LOCK_KEY, now()->toJSON(), now()->addHour());

        return $this->runNow();
    }

    public function runNow(): array
    {
        $late = 0;
        $pos = PurchaseOrder::with(['supplier:id,code,name_ar,name_en', 'warehouse:id,code'])
            ->whereIn('status', PoService::OPEN_FOR_DELIVERY)->whereNotNull('due_date')->where('due_date', '<', H::dayStart())->whereNull('late_alerted_at')->get();
        foreach ($pos as $po) {
            $days = (int) H::dayStart($po->due_date)->diffInDays(H::dayStart());
            $this->notify->activity(null, 'PurchaseOrder', $po->id, $po->number,
                "تأخر توريد {$po->number} من {$po->supplier?->name_ar} — تجاوز موعده بـ {$days} يوم ({$po->receivedPct()}% مستلم). تواصل مع المورد وسجّل المتابعة",
                "{$po->number} from {$po->supplier?->name_en} is {$days} day(s) late — follow up with the supplier", ['proc']);
            $po->update(['late_alerted_at' => now()]);
            $late++;
        }

        $warn = (int) ($this->settings->get('procurement.supplierDocWarnDays') ?? 30);
        $expiring = 0;
        $docs = SupplierDocument::with('supplier:id,code,name_ar,name_en')->whereNotNull('expires_at')->where('expires_at', '<', H::dayStart()->addDays($warn))->whereNull('expiry_alerted_at')->get();
        foreach ($docs as $d) {
            $daysLeft = (int) H::dayStart()->diffInDays($d->expires_at, false);
            $when = $daysLeft < 0 ? 'منتهٍ منذ '.abs($daysLeft).' يوم' : "ينتهي خلال {$daysLeft} يوم";
            $this->notify->activity(null, 'Supplier', $d->supplier_id, $d->supplier?->code,
                "مستند المورد {$d->supplier?->name_ar} ({$d->type}".($d->number ? " {$d->number}" : '').") {$when}".($d->mandatory ? ' — إلزامي: يوقف أوامر الشراء الجديدة بعد انتهائه' : ''),
                "Supplier {$d->supplier?->name_en} document {$d->type} ".($daysLeft < 0 ? 'expired' : "expires in {$daysLeft} day(s)"), ['proc']);
            $d->update(['expiry_alerted_at' => now()]);
            $expiring++;
        }

        return ['ran' => true, 'latePos' => $late, 'expiringDocs' => $expiring];
    }
}
