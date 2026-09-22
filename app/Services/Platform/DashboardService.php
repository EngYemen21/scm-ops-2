<?php

namespace App\Services\Platform;

use App\Models\ActivityLog;
use App\Services\Core\SettingsService;
use App\Services\Platform\ReportSupport as R;
use App\Support\AuthUser;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard + Control Tower aggregates. Every figure is computed from the real tables with count / grouped SQL —
 * nothing is cached, estimated or hard-coded.
 */
class DashboardService
{
    /** Integration adapters listed on the tower; a key is "connected" only when its environment variable is configured. */
    private const INTEGRATIONS = [
        ['key' => 'b2b', 'labelAr' => 'منصة B2B', 'labelEn' => 'B2B platform', 'env' => 'B2B_WEBHOOK_URL'],
        ['key' => 'whatsapp', 'labelAr' => 'واتساب', 'labelEn' => 'WhatsApp', 'env' => 'WHATSAPP_API_URL'],
        ['key' => 'gps', 'labelAr' => 'تتبع GPS', 'labelEn' => 'GPS tracking', 'env' => 'WIALON_TOKEN', 'alt' => 'GPS_PROVIDER_URL'],
        ['key' => 'maps', 'labelAr' => 'الخرائط', 'labelEn' => 'Maps', 'env' => 'MAPBOX_PUBLIC_TOKEN', 'alt' => 'MAPS_API_KEY'],
        ['key' => 'storage', 'labelAr' => 'تخزين الملفات', 'labelEn' => 'Object storage', 'env' => 'OBJECT_STORAGE_ENDPOINT'],
        ['key' => 'erp', 'labelAr' => 'نظام ERP', 'labelEn' => 'ERP', 'env' => 'ERP_BASE_URL'],
    ];

    private const ACTIVE_TRIP = ['dispatched', 'onroute', 'partial', 'returning'];

    private const VEHICLE_ON_ROUTE = ['onroute', 'returning', 'ready', 'loading', 'assigned'];

    private const VEHICLE_MAINT = ['maintenance', 'breakdown'];

    /** An exception's SLA deadline (sla_hours may be fractional). */
    /** SQL for the moment an exception's SLA runs out. */
    private static function slaDue(): string
    {
        return Sql::addHours('e.created_at', 'e.sla_hours');
    }

    public function __construct(private readonly SettingsService $settings) {}

    private function expiringDays(): int
    {
        return (int) $this->settings->get('inventory.expiringSoonDays') ?: 30;
    }

    private function storageIn(string $column): string
    {
        return $column." IN ('".implode("','", R::STORAGE_ZONES)."')";
    }

    /** Σ available (onHand − reserved on unblocked rows in storage zones) and its purchase value. */
    private function availableTotals(): array
    {
        $row = DB::table('inventory_balances as b')
            ->join('bins as bn', 'bn.id', '=', 'b.bin_id')->join('zones as z', 'z.id', '=', 'bn.zone_id')->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.quarantine', false)->where('b.blocked', false)->whereIn('z.type', R::STORAGE_ZONES)->where('b.on_hand', '>', 0)
            ->selectRaw('COALESCE(SUM(GREATEST(b.on_hand - b.reserved, 0)), 0) AS units, COALESCE(SUM(GREATEST(b.on_hand - b.reserved, 0) * COALESCE(p.purchase_price, 0)), 0) AS value')
            ->first();

        return ['units' => R::num($row->units ?? 0), 'value' => R::num($row->value ?? 0)];
    }

    /** Products whose sellable stock is under their reorder minimum. */
    private function lowStockCount(): int
    {
        $avail = DB::table('inventory_balances as b')
            ->join('bins as bn', 'bn.id', '=', 'b.bin_id')->join('zones as z', 'z.id', '=', 'bn.zone_id')
            ->where('b.quarantine', false)->where('b.blocked', false)->whereIn('z.type', R::STORAGE_ZONES)
            ->groupBy('b.product_id')->selectRaw('b.product_id, SUM(GREATEST(b.on_hand - b.reserved, 0)) AS avail');

        return DB::table('products as p')->leftJoinSub($avail, 'a', 'a.product_id', '=', 'p.id')
            ->where('p.active', true)->where('p.reorder_min', '>', 0)->whereRaw('COALESCE(a.avail, 0) < p.reorder_min')->count();
    }

    /** Batches that still hold stock, split into expired / expiring within N days. */
    private function expiryCounts(Carbon $today, int $days): array
    {
        $t = R::db($today);
        $until = R::db($today->copy()->addDays($days + 1));
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(CASE WHEN t.exp < ? THEN 1 ELSE 0 END), 0) AS expired,
                    COALESCE(SUM(CASE WHEN t.exp >= ? AND t.exp < ? THEN 1 ELSE 0 END), 0) AS expiring,
                    COALESCE(SUM(CASE WHEN t.exp < ? THEN t.qty ELSE 0 END), 0) AS `expiredQty`,
                    COALESCE(SUM(CASE WHEN t.exp >= ? AND t.exp < ? THEN t.qty ELSE 0 END), 0) AS `expiringQty`
             FROM (SELECT bt.id, bt.expiry_date AS exp, SUM(b.on_hand) AS qty FROM batches bt JOIN inventory_balances b ON b.batch_id = bt.id
                   WHERE bt.expiry_date IS NOT NULL GROUP BY bt.id, bt.expiry_date HAVING SUM(b.on_hand) > 0) t',
            [$t, $t, $until, $t, $t, $until],
        );

        return array_map(R::num(...), (array) $row);
    }

    private function slaBreachedCount(): int
    {
        return DB::table('exceptions as e')->where('e.status', '<>', 'resolved')->whereRaw(self::slaDue().' < ?', [R::db(now())])->count();
    }

    private function canCost(AuthUser $user): bool
    {
        return $user->can('inventory.view_cost');
    }

    /** status/state → count */
    private function grouped(string $table, string $column, callable $scope): array
    {
        $query = DB::table($table);
        $scope($query);

        return $query->groupBy($column)->selectRaw("{$column} AS k, COUNT(*) AS n")->orderByDesc('n')->orderBy('k')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
    }

    public function dashboard(AuthUser $user): array
    {
        $today = R::startOfToday();
        $from = R::db($today);
        $to = R::db($today->copy()->addDay());
        $expDays = $this->expiringDays();
        $canCost = $this->canCost($user);
        $inDay = fn ($q, string $col) => $q->where($col, '>=', $from)->where($col, '<', $to);

        $expectedInbounds = DB::table('inbound_shipments')->whereIn('status', ['expected', 'arrived'])->count();
        $inspecting = DB::table('inbound_shipments')->where('status', 'inspecting')->count();
        $grnsToday = $inDay(DB::table('goods_receipts'), 'posted_at')->count();
        $putaway = DB::table('putaway_tasks')->where('status', 'open')->selectRaw('COALESCE(SUM(qty), 0) AS qty, COUNT(*) AS n')->first();
        $avail = $this->availableTotals();
        $lowStock = $this->lowStockCount();
        $expiry = $this->expiryCounts($today, $expDays);
        $soConfirmed = DB::table('sales_orders')->where('status', 'confirmed')->count();
        $foByStatus = $this->grouped('fulfillment_orders', 'status', fn ($q) => $q->whereIn('status', ['alloc', 'picking', 'picked', 'packed']));
        $tripsToday = $this->grouped('trips', 'status', fn ($q) => $inDay($q, 'date'));
        $vehiclesByState = $this->grouped('vehicles', 'state', fn ($q) => $q->where('active', true));
        $delivered = $inDay(DB::table('delivery_records')->where('result', 'delivered'), 'completed_at')->count();
        $failed = $inDay(DB::table('delivery_records')->whereIn('result', ['failed', 'rejected']), 'completed_at')->count();
        $returnsOpen = DB::table('returns')->whereNotIn('status', ['closed', 'rejected'])->count();
        $excOpen = DB::table('exceptions')->where('status', '<>', 'resolved')->count();
        $excCritical = DB::table('exceptions')->where('status', '<>', 'resolved')->where('severity', 'c')->count();
        $slaBreached = $this->slaBreachedCount();

        $sum = fn (array $map, array $keys) => array_sum(array_intersect_key($map, array_flip($keys)));
        $putawayTasks = (int) ($putaway->n ?? 0);

        $kpis = [
            ['key' => 'expectedInbounds', 'labelAr' => 'شحنات واردة متوقعة', 'labelEn' => 'Expected inbounds', 'value' => $expectedInbounds, 'link' => '/receiving'],
            ['key' => 'receivingToday', 'labelAr' => 'استلام اليوم', 'labelEn' => 'Receiving today', 'value' => $inspecting + $grnsToday, 'hint' => "{$grnsToday} GRN", 'link' => '/receiving'],
            ['key' => 'waitingQc', 'labelAr' => 'بانتظار الفحص QC', 'labelEn' => 'Waiting QC', 'value' => $inspecting, 'link' => '/receiving?status=inspecting'],
            ['key' => 'waitingPutaway', 'labelAr' => 'بانتظار Putaway (وحدة)', 'labelEn' => 'Waiting putaway (units)', 'value' => R::num($putaway->qty ?? 0), 'hint' => "{$putawayTasks} مهمة / tasks", 'link' => '/receiving?tab=putaway'],
            ['key' => 'availableUnits', 'labelAr' => 'وحدات متاحة للبيع', 'labelEn' => 'Available units', 'value' => $avail['units'], 'link' => '/inv'],
            ...($canCost ? [['key' => 'availableValue', 'labelAr' => 'قيمة المخزون المتاح (ر.س)', 'labelEn' => 'Available value (SAR)', 'value' => R::round($avail['value'], 2), 'link' => '/inv']] : []),
            ['key' => 'lowStock', 'labelAr' => 'منخفض عن حد الطلب', 'labelEn' => 'Below reorder point', 'value' => $lowStock, 'link' => '/inv?filter=low'],
            ['key' => 'expiringSoon', 'labelAr' => "صلاحية قريبة ≤ {$expDays} يوم", 'labelEn' => "Expiring ≤ {$expDays}d", 'value' => $expiry['expiring'], 'hint' => "{$expiry['expiringQty']} وحدة / units", 'link' => '/batches?status=expiring'],
            ['key' => 'expired', 'labelAr' => 'منتهية الصلاحية', 'labelEn' => 'Expired', 'value' => $expiry['expired'], 'hint' => "{$expiry['expiredQty']} وحدة / units", 'link' => '/batches?status=expired'],
            ['key' => 'ordersWaitingAllocation', 'labelAr' => 'طلبات بانتظار التخصيص', 'labelEn' => 'Orders waiting allocation', 'value' => $soConfirmed, 'link' => '/sales?status=confirmed'],
            ['key' => 'picking', 'labelAr' => 'قيد التجهيز', 'labelEn' => 'Picking', 'value' => $sum($foByStatus, ['alloc', 'picking']), 'link' => '/picking'],
            ['key' => 'packing', 'labelAr' => 'قيد التعبئة', 'labelEn' => 'Packing', 'value' => $sum($foByStatus, ['picked']), 'link' => '/picking?tab=pack'],
            ['key' => 'readyForDispatch', 'labelAr' => 'جاهز للشحن', 'labelEn' => 'Ready for dispatch', 'value' => $sum($foByStatus, ['packed']), 'link' => '/dispatch'],
            ['key' => 'tripsToday', 'labelAr' => 'رحلات اليوم', 'labelEn' => 'Trips today', 'value' => array_sum($tripsToday), 'detail' => (object) $tripsToday, 'link' => '/trips'],
            ['key' => 'vehiclesAvailable', 'labelAr' => 'مركبات متاحة', 'labelEn' => 'Vehicles available', 'value' => $sum($vehiclesByState, ['available', 'atwh']), 'link' => '/fleet?state=available'],
            ['key' => 'vehiclesOnRoute', 'labelAr' => 'مركبات في الطريق', 'labelEn' => 'Vehicles on route', 'value' => $sum($vehiclesByState, self::VEHICLE_ON_ROUTE), 'link' => '/fleet?state=onroute'],
            ['key' => 'vehiclesMaintenance', 'labelAr' => 'مركبات في الصيانة', 'labelEn' => 'Vehicles in maintenance', 'value' => $sum($vehiclesByState, self::VEHICLE_MAINT), 'link' => '/fleet?state=maintenance'],
            ['key' => 'deliveriesCompletedToday', 'labelAr' => 'تسليمات ناجحة اليوم', 'labelEn' => 'Deliveries completed today', 'value' => $delivered, 'link' => '/trips'],
            ['key' => 'deliveriesFailedToday', 'labelAr' => 'تسليمات فاشلة اليوم', 'labelEn' => 'Deliveries failed today', 'value' => $failed, 'link' => '/trips?stops=failed'],
            ['key' => 'returnsOpen', 'labelAr' => 'مرتجعات مفتوحة', 'labelEn' => 'Open returns', 'value' => $returnsOpen, 'link' => '/returns'],
            ['key' => 'exceptionsOpen', 'labelAr' => 'استثناءات مفتوحة', 'labelEn' => 'Open exceptions', 'value' => $excOpen, 'link' => '/tower'],
            ['key' => 'exceptionsCritical', 'labelAr' => 'استثناءات حرجة', 'labelEn' => 'Critical exceptions', 'value' => $excCritical, 'link' => '/tower?severity=c'],
            ['key' => 'slaBreached', 'labelAr' => 'خرق SLA', 'labelEn' => 'SLA breached', 'value' => $slaBreached, 'link' => '/tower?sla=breached'],
        ];

        return [
            'generatedAt' => R::iso(now()), 'costVisible' => $canCost, 'expiringSoonDays' => $expDays, 'kpis' => $kpis,
            'actions' => $this->actions($today),
            'inventoryByWarehouse' => $this->inventoryByWarehouse($canCost),
            'recentActivity' => ActivityLog::orderByDesc('at')->orderByDesc('id')->limit(8)->get()->all(),
            'pendingApprovals' => $this->pendingApprovals($user),
        ];
    }

    /** Items needing attention, most urgent first. Each carries a path the web can open. */
    private function actions(Carbon $today): array
    {
        $now = R::db(now());
        $t = R::db($today);
        $out = [];

        $exceptions = DB::select(
            'SELECT e.number, e.kind, e.severity, e.status, e.owner_role, e.text_ar, e.text_en, ('.self::slaDue().' < ?) AS breached
             FROM exceptions e WHERE e.status <> \'resolved\' AND (e.severity = \'c\' OR '.self::slaDue().' < ?)
             ORDER BY (e.severity = \'c\') DESC, e.created_at ASC, e.id ASC LIMIT 6',
            [$now, $now],
        );
        foreach ($exceptions as $e) {
            $breached = (bool) $e->breached;
            $out[] = [
                'kind' => $breached ? 'sla_breach' : 'exception', 'number' => $e->number,
                'textAr' => ($breached ? 'خرق SLA — ' : 'استثناء حرج — ').$e->text_ar,
                'textEn' => ($breached ? 'SLA breached — ' : 'Critical — ').($e->text_en ?: $e->text_ar),
                'owner' => $e->owner_role, 'path' => "/exc/{$e->number}",
                'status' => $e->status, // so the client offers acknowledge only while the exception is still open
            ];
        }

        $expired = DB::select(
            'SELECT bt.batch_no, p.sku, p.name_ar, p.name_en, SUM(b.on_hand) AS qty
             FROM batches bt JOIN inventory_balances b ON b.batch_id = bt.id JOIN products p ON p.id = bt.product_id
             WHERE bt.expiry_date < ? GROUP BY bt.id, bt.batch_no, p.sku, p.name_ar, p.name_en HAVING SUM(b.on_hand) > 0 ORDER BY SUM(b.on_hand) DESC, bt.batch_no LIMIT 5',
            [$t],
        );
        foreach ($expired as $b) {
            $qty = R::num($b->qty);
            $out[] = ['kind' => 'expired', 'number' => $b->batch_no, 'textAr' => "دفعة منتهية {$b->batch_no} — {$b->name_ar} ({$qty} وحدة)", 'textEn' => "Expired batch {$b->batch_no} — {$b->name_en} ({$qty} units)", 'owner' => 'inv', 'path' => "/product/{$b->sku}"];
        }

        $pendingPos = DB::table('purchase_orders as po')->join('suppliers as s', 's.id', '=', 'po.supplier_id')->where('po.status', 'pending')
            ->orderBy('po.created_at')->orderBy('po.id')->limit(5)->get(['po.id', 'po.number', 'po.total', 's.name_ar', 's.name_en']);
        foreach ($pendingPos as $p) {
            $step = DB::table('po_approvals')->where('po_id', $p->id)->where('decision', 'pending')->orderBy('step')->first(['role_key', 'label_ar', 'label_en']);
            $total = R::fmt($p->total);
            $out[] = [
                'kind' => 'po_approval', 'number' => $p->number,
                'textAr' => "أمر شراء {$p->number} بانتظار اعتماد ".($step->label_ar ?? '')." — {$p->name_ar} ({$total} ر.س)",
                'textEn' => "PO {$p->number} awaiting ".(($step->label_en ?? '') ?: 'approval')." — {$p->name_en} (SAR {$total})",
                'owner' => ($step->role_key ?? '') ?: 'proc', 'path' => "/po/{$p->number}",
            ];
        }

        foreach (DB::table('drivers')->where('blocked', true)->where('active', true)->orderBy('code')->limit(5)->get(['code', 'name_ar', 'name_en']) as $d) {
            $out[] = ['kind' => 'driver_blocked', 'number' => $d->code, 'textAr' => "السائق {$d->name_ar} محظور من الإسناد", 'textEn' => 'Driver '.($d->name_en ?: $d->name_ar).' is blocked', 'owner' => 'disp', 'path' => "/fleet?driver={$d->code}"];
        }

        $docColumns = ['reg_expiry' => ['التسجيل', 'registration'], 'insurance_expiry' => ['التأمين', 'insurance'], 'inspection_expiry' => ['الفحص', 'inspection'], 'op_card_expiry' => ['بطاقة التشغيل', 'op card']];
        $vehicles = DB::table('vehicles')->where('active', true)
            ->where(fn ($w) => $w->where('reg_expiry', '<', $t)->orWhere('insurance_expiry', '<', $t)->orWhere('inspection_expiry', '<', $t)->orWhere('op_card_expiry', '<', $t))
            ->orderBy('code')->limit(5)->get(['code', 'plate_ar', ...array_keys($docColumns)]);
        foreach ($vehicles as $v) {
            $docs = array_filter($docColumns, fn ($labels, $column) => $v->{$column} !== null && $v->{$column} < $t, ARRAY_FILTER_USE_BOTH);
            $out[] = [
                'kind' => 'vehicle_doc', 'number' => $v->code,
                'textAr' => "مركبة {$v->plate_ar} — وثائق منتهية: ".implode('، ', array_column($docs, 0)),
                'textEn' => "Vehicle {$v->code} — expired: ".implode(', ', array_column($docs, 1)),
                'owner' => 'disp', 'path' => "/fleet?vehicle={$v->code}",
            ];
        }

        return $out;
    }

    private function inventoryByWarehouse(bool $canCost): array
    {
        $rows = DB::select(
            'SELECT w.id AS `warehouseId`, w.code, w.name_ar AS `nameAr`, w.name_en AS `nameEn`,
                COALESCE(SUM(b.on_hand), 0) AS `onHand`, COALESCE(SUM(b.reserved), 0) AS reserved,
                COALESCE(SUM(CASE WHEN NOT b.quarantine AND NOT b.blocked AND '.$this->storageIn('z.type').' THEN GREATEST(b.on_hand - b.reserved, 0) ELSE 0 END), 0) AS available,
                COALESCE(SUM(b.on_hand * COALESCE(p.purchase_price, 0)), 0) AS value, COUNT(DISTINCT b.product_id) AS skus
             FROM warehouses w LEFT JOIN inventory_balances b ON b.warehouse_id = w.id AND b.on_hand > 0
             LEFT JOIN bins bn ON bn.id = b.bin_id LEFT JOIN zones z ON z.id = bn.zone_id LEFT JOIN products p ON p.id = b.product_id
             WHERE w.active GROUP BY w.id, w.code, w.name_ar, w.name_en ORDER BY w.code',
        );
        $base = array_sum(array_map(fn ($r) => R::num($canCost ? $r->value : $r->onHand), $rows));

        return array_map(function ($r) use ($canCost, $base) {
            $row = ['warehouseId' => $r->warehouseId, 'code' => $r->code, 'nameAr' => $r->nameAr, 'nameEn' => $r->nameEn, 'onHand' => R::num($r->onHand), 'reserved' => R::num($r->reserved), 'available' => R::num($r->available), 'skus' => R::num($r->skus)];
            if ($canCost) {
                $row['value'] = R::round(R::num($r->value), 2);
            }

            return $row + ['sharePct' => R::pct(R::num($canCost ? $r->value : $r->onHand), $base) ?? 0];
        }, $rows);
    }

    /** Approval steps waiting on one of the user's roles (PO chain + PR approvals). */
    private function pendingApprovals(AuthUser $user): array
    {
        if (! $user->roles) {
            return ['pos' => [], 'prs' => []];
        }
        $pos = DB::table('po_approvals as a')->join('purchase_orders as po', 'po.id', '=', 'a.po_id')->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('a.decision', 'pending')->whereIn('a.role_key', $user->roles)->where('po.status', 'pending')
            ->orderBy('po.created_at')->orderBy('po.id')->orderBy('a.step')->limit(20)
            ->get(['a.step', 'a.role_key', 'a.label_ar', 'a.label_en', 'po.id', 'po.number', 'po.total', 'po.approval_step', 'po.created_at', 's.name_ar', 's.name_en']);
        $prs = DB::table('pr_approvals as a')->join('purchase_requisitions as pr', 'pr.id', '=', 'a.pr_id')->join('warehouses as w', 'w.id', '=', 'pr.warehouse_id')
            ->where('a.decision', 'pending')->whereIn('a.role_key', $user->roles)->whereIn('pr.status', ['submitted', 'review'])
            ->orderBy('pr.created_at')->orderBy('pr.id')->orderBy('a.step')->limit(20)
            ->get(['a.step', 'a.role_key', 'pr.id', 'pr.number', 'pr.priority', 'pr.need_date', 'pr.created_at', 'w.code']);

        return [
            // Only the step that is actually next in the chain is actionable (approvalStep = steps already approved).
            'pos' => $pos->filter(fn ($a) => (int) $a->step === (int) $a->approval_step + 1)->map(fn ($a) => [
                'type' => 'PO', 'id' => $a->id, 'number' => $a->number, 'step' => (int) $a->step, 'roleKey' => $a->role_key, 'labelAr' => $a->label_ar, 'labelEn' => $a->label_en,
                'total' => R::num($a->total), 'supplierAr' => $a->name_ar, 'supplierEn' => $a->name_en, 'createdAt' => R::iso($a->created_at), 'path' => "/po/{$a->number}",
            ])->values()->all(),
            'prs' => $prs->map(fn ($a) => [
                'type' => 'PR', 'id' => $a->id, 'number' => $a->number, 'step' => (int) $a->step, 'roleKey' => $a->role_key, 'priority' => $a->priority,
                'needDate' => R::iso($a->need_date), 'warehouse' => $a->code, 'createdAt' => R::iso($a->created_at), 'path' => "/procurement?pr={$a->number}",
            ])->values()->all(),
        ];
    }

    // ───────────── Control Tower ─────────────
    public function tower(AuthUser $user): array
    {
        $today = R::startOfToday();
        $t = R::db($today);
        $from = $t;
        $to = R::db($today->copy()->addDay());
        $nowAt = now();
        $now = R::db($nowAt);
        $canCost = $this->canCost($user);
        $open = fn ($q) => $q->where('status', '<>', 'resolved');

        $sev = array_merge(['c' => 0, 'w' => 0, 'i' => 0], $this->grouped('exceptions', 'severity', $open));
        $st = array_merge(['open' => 0, 'ack' => 0, 'resolved' => 0], $this->grouped('exceptions', 'status', fn ($q) => $q));
        $byKind = $this->grouped('exceptions', 'kind', $open);
        $slaCount = $this->slaBreachedCount();

        $slaList = DB::select(
            'SELECT e.number, e.kind, e.severity, e.status, e.owner_role, e.text_ar, e.text_en, e.document_number, e.created_at, e.sla_hours,
                    ROUND('.Sql::seconds(self::slaDue(), '?').' / 60) AS overdue_min
             FROM exceptions e WHERE e.status <> \'resolved\' AND '.self::slaDue().' < ? ORDER BY e.created_at ASC, e.id ASC LIMIT 10',
            [$now, $now],
        );

        $late = fn () => DB::table('trips as t')->where('t.delay_min', '>', 0)->whereIn('t.status', self::ACTIVE_TRIP);
        $lateCount = $late()->count();
        $lateTrips = $late()->join('warehouses as w', 'w.id', '=', 't.warehouse_id')->leftJoin('vehicles as v', 'v.id', '=', 't.vehicle_id')->leftJoin('drivers as d', 'd.id', '=', 't.driver_id')
            ->orderByDesc('t.delay_min')->orderBy('t.number')->limit(10)
            ->get(['t.number', 't.status', 't.delay_min', 't.eta', 't.route_ar', 't.route_en', 'w.code as wh_code', 'v.code as v_code', 'v.plate_ar', 'd.code as d_code', 'd.name_ar as d_name']);

        $inbound = fn () => DB::table('inbound_shipments as sh')->where('sh.status', 'expected')->where('sh.eta', '<', $t);
        $inboundCount = $inbound()->count();
        $inboundList = $inbound()->join('purchase_orders as po', 'po.id', '=', 'sh.po_id')->join('suppliers as s', 's.id', '=', 'sh.supplier_id')->join('warehouses as w', 'w.id', '=', 'sh.warehouse_id')
            ->orderBy('sh.eta')->orderBy('sh.number')->limit(10)->get(['sh.number', 'sh.eta', 'po.number as po_number', 's.name_ar', 's.name_en', 'w.code']);

        $risk = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN bt.expiry_date IS NOT NULL AND bt.expiry_date < ? THEN b.on_hand ELSE 0 END), 0) AS `expiredQty`,
                COALESCE(SUM(CASE WHEN bt.expiry_date IS NOT NULL AND bt.expiry_date < ? THEN b.on_hand * COALESCE(p.purchase_price, 0) ELSE 0 END), 0) AS `expiredValue`,
                COALESCE(SUM(CASE WHEN b.quarantine OR z.type = \'quarantine\' THEN b.on_hand ELSE 0 END), 0) AS `quarantineQty`,
                COALESCE(SUM(CASE WHEN b.quarantine OR z.type = \'quarantine\' THEN b.on_hand * COALESCE(p.purchase_price, 0) ELSE 0 END), 0) AS `quarantineValue`,
                COALESCE(SUM(CASE WHEN z.type = \'damaged\' THEN b.on_hand ELSE 0 END), 0) AS `damagedQty`,
                COALESCE(SUM(CASE WHEN b.blocked THEN b.on_hand ELSE 0 END), 0) AS `blockedQty`
             FROM inventory_balances b JOIN bins bn ON bn.id = b.bin_id JOIN zones z ON z.id = bn.zone_id JOIN products p ON p.id = b.product_id
             LEFT JOIN batches bt ON bt.id = b.batch_id WHERE b.on_hand > 0',
            [$t, $t],
        );
        $risk = array_map(R::num(...), (array) $risk);

        $transit = DB::selectOne(
            'SELECT COUNT(DISTINCT t.id) AS transfers, COALESCE(SUM(l.qty - COALESCE(l.received_qty, 0)), 0) AS qty
             FROM warehouse_transfers t LEFT JOIN transfer_lines l ON l.transfer_id = t.id WHERE t.status = \'transit\'',
        );
        $retInspect = $this->grouped('returns', 'status', fn ($q) => $q->whereIn('status', ['received', 'inspect']));

        $throughput = DB::select(
            'SELECT w.id AS `warehouseId`, w.code, w.name_ar AS `nameAr`, w.name_en AS `nameEn`,
                (SELECT COALESCE(SUM(gl.accepted_qty), 0) FROM goods_receipts g JOIN grn_lines gl ON gl.grn_id = g.id WHERE g.warehouse_id = w.id AND g.posted_at >= ? AND g.posted_at < ?) AS `grnQty`,
                (SELECT COUNT(*) FROM goods_receipts g WHERE g.warehouse_id = w.id AND g.posted_at >= ? AND g.posted_at < ?) AS grns,
                (SELECT COALESCE(SUM(pt.picked_qty), 0) FROM pick_tasks pt JOIN pick_lists pl ON pl.id = pt.pick_list_id WHERE pl.warehouse_id = w.id AND pt.picked_at >= ? AND pt.picked_at < ?) AS `pickedQty`,
                (SELECT COUNT(*) FROM fulfillment_orders f WHERE f.warehouse_id = w.id AND f.dispatched_at >= ? AND f.dispatched_at < ?) AS `dispatchedOrders`,
                (SELECT COUNT(*) FROM trips t WHERE t.warehouse_id = w.id AND t.dispatched_at >= ? AND t.dispatched_at < ?) AS `dispatchedTrips`
             FROM warehouses w WHERE w.active ORDER BY w.code',
            [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to],
        );

        $queue = DB::table('exceptions')->where('status', '<>', 'resolved')->orderBy('severity')->orderBy('created_at')->orderBy('id')->limit(15)
            ->get(['number', 'kind', 'severity', 'status', 'owner_role', 'text_ar', 'text_en', 'document_number', 'entity_type', 'entity_number', 'created_at', 'sla_hours']);

        $transfers = R::num($transit->transfers ?? 0);
        $transitQty = R::num($transit->qty ?? 0);
        $retCount = array_sum($retInspect);

        $kpis = [
            ['key' => 'critical', 'labelAr' => 'حرج', 'labelEn' => 'Critical', 'value' => $sev['c'], 'link' => '/tower?severity=c'],
            ['key' => 'high', 'labelAr' => 'عالٍ', 'labelEn' => 'High', 'value' => $sev['w'], 'link' => '/tower?severity=w'],
            ['key' => 'medium', 'labelAr' => 'متوسط', 'labelEn' => 'Medium', 'value' => $sev['i'], 'link' => '/tower?severity=i'],
            ['key' => 'slaBreached', 'labelAr' => 'خرق SLA', 'labelEn' => 'SLA breaches', 'value' => $slaCount, 'link' => '/tower?sla=breached'],
            ['key' => 'lateTrips', 'labelAr' => 'رحلات متأخرة', 'labelEn' => 'Late trips', 'value' => $lateCount, 'link' => '/ttower'],
            ['key' => 'inboundAtRisk', 'labelAr' => 'شحنات واردة متأخرة', 'labelEn' => 'Inbound at risk', 'value' => $inboundCount, 'link' => '/receiving?status=expected'],
            ['key' => 'transfersInTransit', 'labelAr' => 'تحويلات في العبور', 'labelEn' => 'Transfers in transit', 'value' => $transfers, 'hint' => "{$transitQty} وحدة / units", 'link' => '/returns?tab=transfers'],
            ['key' => 'returnsAwaitingInspection', 'labelAr' => 'مرتجعات بانتظار الفحص', 'labelEn' => 'Returns awaiting inspection', 'value' => $retCount, 'link' => '/returns?status=received'],
            ['key' => 'expiredQty', 'labelAr' => 'مخزون منتهي (وحدة)', 'labelEn' => 'Expired stock (units)', 'value' => $risk['expiredQty'], 'link' => '/batches?status=expired'],
            ['key' => 'quarantineQty', 'labelAr' => 'مخزون محجور (وحدة)', 'labelEn' => 'Quarantined stock (units)', 'value' => $risk['quarantineQty'], 'link' => '/inv?status=quarantine'],
        ];

        $stockAtRisk = ['expiredQty' => $risk['expiredQty'], 'quarantineQty' => $risk['quarantineQty'], 'damagedQty' => $risk['damagedQty'], 'blockedQty' => $risk['blockedQty']];
        if ($canCost) {
            $stockAtRisk += ['expiredValue' => R::round($risk['expiredValue'], 2), 'quarantineValue' => R::round($risk['quarantineValue'], 2)];
        }

        return [
            'generatedAt' => R::iso($nowAt), 'costVisible' => $canCost, 'kpis' => $kpis,
            'exceptions' => [
                'bySeverity' => $sev, 'byStatus' => $st,
                'byKind' => array_map(fn ($kind, $count) => ['kind' => (string) $kind, 'count' => $count], array_keys($byKind), $byKind),
                'queue' => $queue->map(fn ($e) => [
                    'number' => $e->number, 'kind' => $e->kind, 'severity' => $e->severity, 'status' => $e->status, 'ownerRole' => $e->owner_role, 'textAr' => $e->text_ar, 'textEn' => $e->text_en,
                    'documentNumber' => $e->document_number, 'entityType' => $e->entity_type, 'entityNumber' => $e->entity_number, 'createdAt' => R::iso($e->created_at), 'slaHours' => R::num($e->sla_hours),
                    'breached' => Carbon::parse($e->created_at, 'UTC')->addSeconds((int) round($e->sla_hours * 3600))->lt($nowAt), 'path' => "/exc/{$e->number}",
                ])->all(),
            ],
            'slaBreaches' => ['count' => $slaCount, 'items' => array_map(fn ($r) => [
                'number' => $r->number, 'kind' => $r->kind, 'severity' => $r->severity, 'status' => $r->status, 'ownerRole' => $r->owner_role, 'textAr' => $r->text_ar, 'textEn' => $r->text_en,
                'documentNumber' => $r->document_number, 'createdAt' => R::iso($r->created_at), 'slaHours' => R::num($r->sla_hours), 'overdueMin' => R::num($r->overdue_min), 'path' => "/exc/{$r->number}",
            ], $slaList)],
            'lateTrips' => ['count' => $lateCount, 'items' => $lateTrips->map(fn ($r) => [
                'number' => $r->number, 'status' => $r->status, 'delayMin' => (int) $r->delay_min, 'eta' => $r->eta, 'routeAr' => $r->route_ar, 'routeEn' => $r->route_en,
                'warehouse' => ['code' => $r->wh_code],
                'vehicle' => $r->v_code !== null ? ['code' => $r->v_code, 'plateAr' => $r->plate_ar] : null,
                'driver' => $r->d_code !== null ? ['code' => $r->d_code, 'nameAr' => $r->d_name] : null,
                'path' => "/trip/{$r->number}",
            ])->all()],
            'inboundAtRisk' => ['count' => $inboundCount, 'items' => $inboundList->map(fn ($s) => [
                'number' => $s->number, 'eta' => R::iso($s->eta), 'daysLate' => (int) floor(($today->getTimestamp() - Carbon::parse($s->eta, 'UTC')->getTimestamp()) / 86400),
                'po' => $s->po_number, 'supplierAr' => $s->name_ar, 'supplierEn' => $s->name_en, 'warehouse' => $s->code, 'path' => "/shipments/{$s->number}",
            ])->all()],
            'stockAtRisk' => $stockAtRisk,
            'transfersInTransit' => ['count' => $transfers, 'qty' => $transitQty],
            'returnsAwaitingInspection' => ['count' => $retCount, 'byStatus' => (object) $retInspect],
            'throughputToday' => array_map(fn ($r) => [
                'warehouseId' => $r->warehouseId, 'code' => $r->code, 'nameAr' => $r->nameAr, 'nameEn' => $r->nameEn, 'grnQty' => R::num($r->grnQty), 'grns' => R::num($r->grns),
                'pickedQty' => R::num($r->pickedQty), 'dispatchedOrders' => R::num($r->dispatchedOrders), 'dispatchedTrips' => R::num($r->dispatchedTrips),
            ], $throughput),
            // Honest status: "connected" only when the provider is configured in the environment, otherwise integration_pending.
            'integrations' => array_map(fn ($i) => [
                'key' => $i['key'], 'labelAr' => $i['labelAr'], 'labelEn' => $i['labelEn'],
                'status' => trim((string) config('integrations.'.$i['env'], '')).trim((string) config('integrations.'.($i['alt'] ?? $i['env']), '')) !== '' ? 'connected' : 'integration_pending', 'configVar' => $i['env'],
            ], self::INTEGRATIONS),
        ];
    }
}
