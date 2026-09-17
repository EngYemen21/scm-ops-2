<?php

namespace App\Services\Inventory;

use App\Models\CountLine;
use App\Models\InventoryBalance;
use App\Models\InventoryCount;
use App\Models\InventoryMovement;
use App\Models\StatusHistory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cycle / full / spot / ABC counts (§12). Lines snapshot systemQty; `start` freezes the rows (blocked=true) when
 * requested; `approve` posts `adj` movements through the engine for every variance and unblocks the rows.
 */
class CountsService
{
    /** COUNT_STATES as a state machine: open → counting → variance → adjusted → closed. */
    public const TRANSITIONS = ['open' => ['counting'], 'counting' => ['variance'], 'variance' => ['adjusted'], 'adjusted' => ['closed'], 'closed' => []];

    private const STATUS_AR = ['open' => 'مجدول', 'counting' => 'قيد العد', 'variance' => 'فروقات بانتظار الاعتماد', 'adjusted' => 'تمت التسوية', 'closed' => 'مُغلق'];

    private const RELATIONS = ['warehouse', 'zone', 'lines.product', 'lines.bin.zone', 'lines.batch'];

    public function __construct(
        private readonly InventoryService $inv,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    private function load(string $id): InventoryCount
    {
        $c = InventoryCount::with(self::RELATIONS)->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('COUNT_NOT_FOUND', "الجرد {$id} غير موجود", "Count {$id} not found");

        return $this->sortLines($c);
    }

    private function sortLines(InventoryCount $c): InventoryCount
    {
        return $c->setRelation('lines', $c->lines->sortBy(fn (CountLine $l) => [$l->bin->code, $l->product->sku, $l->id])->values());
    }

    /** Blind counts hide the system quantity from counters (anyone without inventory.adjust) until the count is completed. */
    public function present(InventoryCount $c, ?AuthUser $user = null): array
    {
        $hide = $c->blind && in_array($c->status, ['open', 'counting'], true) && ! ($user?->can('inventory.adjust') ?? false);
        $lines = $c->lines->map(function (CountLine $l) use ($hide) {
            $line = [
                'product' => Shapes::product($l->product, []), 'bin' => Shapes::bin($l->bin), 'batch' => Shapes::batch($l->batch),
            ] + $l->toArray();

            return $hide
                ? ['systemQty' => null, 'blind' => true] + $line
                : $line + ['variance' => $l->counted_qty === null ? null : $l->counted_qty - $l->system_qty];
        })->all();
        $progress = ['total' => $c->lines->count(), 'counted' => $c->lines->whereNotNull('counted_qty')->count()];
        if (! $hide) {
            $progress['variances'] = $c->lines->filter(fn (CountLine $l) => $l->counted_qty !== null && $l->counted_qty !== $l->system_qty)->count();
        }
        $zone = $c->zone;

        return [
            'warehouse' => Shapes::warehouse($c->warehouse),
            'zone' => $zone ? ['id' => $zone->id, 'code' => $zone->code, 'nameAr' => $zone->name_ar, 'nameEn' => $zone->name_en, 'type' => $zone->type] : null,
            'lines' => $lines, 'progress' => $progress, 'allowed' => self::TRANSITIONS[$c->status] ?? [],
        ] + $c->toArray();
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_AR[$status] ?? $status;
    }

    private function setStatus(AuthUser $user, InventoryCount $c, string $to, ?string $note = null, array $extra = []): InventoryCount
    {
        $from = $c->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw AppError::rule('COUNT_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($from)} ← {$this->statusLabel($to)}", "Invalid transition {$from} → {$to}");
        }
        $c->update(['status' => $to] + $extra);
        $this->audit->status($user, 'InventoryCount', $c->id, $c->number, $from, $to, $note);
        $suffix = $note ? ' — '.$note : '';
        $this->notify->activity($user, 'InventoryCount', $c->id, $c->number, "الجرد {$c->number} → {$this->statusLabel($to)}{$suffix}", "Count {$c->number} → {$to}{$suffix}", $to === 'variance' ? ['wm', 'inv'] : []);

        return $this->load($c->id);
    }

    /**
     * Balance rows in scope of a count (used at schedule time to build the lines).
     *
     * @return Collection<int, InventoryBalance>
     */
    private function rowsInScope(string $warehouseId, ?string $zoneId, ?string $scope): Collection
    {
        $base = fn () => InventoryBalance::where('warehouse_id', $warehouseId);
        switch ($scope) {
            case 'zone':
                return $base()->whereHas('bin', fn ($b) => $b->where('zone_id', $zoneId))->get();
            case 'exp':
                $days = (int) $this->settings->get('inventory.expiringSoonDays') ?: 30;

                return $base()->where('on_hand', '>', 0)->whereHas('batch', fn ($b) => $b->where('expiry_date', '<=', Carbon::today()->addDays($days)))->get();
            case 'neg': // bins with variances in earlier counts of this warehouse
                $bins = CountLine::whereNotNull('counted_qty')->whereColumn('counted_qty', '!=', 'system_qty')
                    ->whereHas('count', fn ($c) => $c->where('warehouse_id', $warehouseId)->whereIn('status', ['adjusted', 'closed']))
                    ->distinct()->pluck('bin_id')->all();

                return $base()->whereIn('bin_id', $bins)->get();
            case 'abc': // top 20 % of SKUs by stock value (A items)
                $rows = $base()->where('on_hand', '>', 0)->with('product:id,purchase_price')->get();
                $value = [];
                foreach ($rows as $r) {
                    $value[$r->product_id] = ($value[$r->product_id] ?? 0) + $r->on_hand * (float) ($r->product->purchase_price ?? 0);
                }
                arsort($value);
                $top = array_slice(array_keys($value), 0, max(1, (int) ceil(count($value) * 0.2)));

                return $rows->whereIn('product_id', $top)->values();
            case 'random':
                return $base()->get()->shuffle()->take(20)->values();
            default:
                return $base()->get();
        }
    }

    // ───────────── schedule / read ─────────────

    /**
     * @param  array{warehouseCode:string, zoneCode?:?string, type:string, scope:string, blind:bool, freeze:bool, date:string, counterUsername?:?string}  $dto
     */
    public function schedule(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $warehouse = Warehouse::where('code', mb_strtoupper($dto['warehouseCode']))->first()
                ?? throw AppError::notFound('WAREHOUSE_NOT_FOUND', "المستودع {$dto['warehouseCode']} غير موجود", 'Warehouse not found');
            $zone = null;
            if (! empty($dto['zoneCode'])) {
                $zone = Zone::where('warehouse_id', $warehouse->id)->where('code', mb_strtoupper($dto['zoneCode']))->first()
                    ?? throw AppError::notFound('ZONE_NOT_FOUND', "المنطقة {$dto['zoneCode']} غير موجودة في {$warehouse->code}", 'Zone not found');
            }
            $counter = null;
            if (! empty($dto['counterUsername'])) {
                $counter = User::where('username', $dto['counterUsername'])->first();
                if (! $counter || ! $counter->active) {
                    throw AppError::notFound('USER_NOT_FOUND', "العدّاد {$dto['counterUsername']} غير موجود", 'Counter user not found');
                }
            }
            $scope = match ($dto['type']) {
                'full' => 'all', 'abc' => 'abc', default => $dto['scope'],
            };
            $rows = $this->rowsInScope($warehouse->id, $zone?->id, $scope);
            if ($rows->isEmpty()) {
                throw AppError::rule('COUNT_EMPTY', 'لا توجد أرصدة ضمن نطاق الجرد', 'No balance rows in count scope');
            }
            $number = $this->numbering->next('CNT');
            $c = InventoryCount::create([
                'number' => $number, 'warehouse_id' => $warehouse->id, 'zone_id' => $zone?->id, 'type' => $dto['type'], 'scope' => $scope, 'blind' => $dto['blind'],
                'freeze' => $dto['freeze'], 'status' => 'open', 'counter_id' => $counter?->id, 'counter' => $counter?->name_ar ?: null, 'date' => Carbon::parse($dto['date']),
            ]);
            foreach ($rows as $r) {
                CountLine::create(['count_id' => $c->id, 'product_id' => $r->product_id, 'bin_id' => $r->bin_id, 'batch_id' => $r->batch_id, 'system_qty' => $r->on_hand]);
            }
            $n = $rows->count();
            $this->audit->log($user, ['action' => 'COUNT.SCHEDULE', 'entityType' => 'InventoryCount', 'entityId' => $c->id, 'entityNumber' => $number, 'newValue' => [
                'warehouse' => $warehouse->code, 'zone' => $zone?->code, 'type' => $dto['type'], 'scope' => $scope, 'blind' => $dto['blind'], 'freeze' => $dto['freeze'],
                'date' => $dto['date'], 'counter' => $counter?->username, 'lines' => $n,
            ]]);
            $this->audit->status($user, 'InventoryCount', $c->id, $number, null, 'open');
            $this->notify->activity($user, 'InventoryCount', $c->id, $number,
                "جُدول {$number} — ".($dto['blind'] ? 'عد أعمى' : 'عد عادي').($dto['freeze'] ? ' مع تجميد النطاق' : '')." يوم {$dto['date']} ({$n} سطر)",
                "Count {$number} scheduled — ".($dto['blind'] ? 'blind' : 'open').($dto['freeze'] ? ', frozen scope' : '')." on {$dto['date']} ({$n} lines)",
                $counter ? ['worker', 'inv'] : []);

            return $this->present($this->load($c->id), $user);
        });
    }

    /** @param  array{status?:?string, warehouse?:?string, type?:?string}  $f */
    public function list(Paging $page, array $f, ?AuthUser $user = null): array
    {
        $query = InventoryCount::with(self::RELATIONS);
        if (! empty($f['status'])) {
            $query->whereIn('status', explode(',', $f['status']));
        }
        if (! empty($f['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', mb_strtoupper($f['warehouse'])));
        }
        if (! empty($f['type'])) {
            $query->where('type', $f['type']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('counter', 'like', $like)->orWhereHas('zone', fn ($z) => $z->where('code', 'like', $like)));
        }
        $query->orderBy('date', $page->order)->orderBy('created_at', $page->order)->orderBy('id', $page->order);

        return $page->paginate($query, fn (InventoryCount $c) => $this->present($this->sortLines($c), $user));
    }

    public function get(string $id, ?AuthUser $user = null): array
    {
        $c = $this->load($id);
        $history = StatusHistory::where('entity_type', 'InventoryCount')->where('entity_id', $c->id)->orderBy('at')->orderBy('id')->get();
        $movements = InventoryMovement::where('reference_type', 'InventoryCount')->where('reference_id', $c->id)->orderBy('created_at')->orderBy('number')->get(Shapes::DOC_MOVEMENT_COLUMNS);

        return $this->present($c, $user) + [
            'history' => $history->map->toArray()->all(),
            'movements' => $movements->map(fn (InventoryMovement $m) => Shapes::docMovement($m))->all(),
        ];
    }

    // ───────────── workflow ─────────────

    private function lineRow(CountLine $l): ?InventoryBalance
    {
        return InventoryBalance::where('product_id', $l->product_id)->where('bin_id', $l->bin_id)->where('batch_key', $l->batch_id ?? '')->first();
    }

    /** open → counting. Re-snapshots systemQty (stock may have moved since scheduling) and freezes the rows when requested. */
    public function start(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $c = $this->load($id);
            if ($c->status !== 'open') {
                throw AppError::rule('COUNT_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($c->status)} ← {$this->statusLabel('counting')}", "Invalid transition {$c->status} → counting");
            }
            $rowIds = [];
            foreach ($c->lines as $l) {
                $row = $this->lineRow($l);
                if ($row) {
                    $rowIds[] = $row->id;
                    if ($row->on_hand !== $l->system_qty) {
                        $l->update(['system_qty' => $row->on_hand]);
                    }
                }
            }
            if ($c->freeze) {
                $this->inv->setBlocked($rowIds, true);
            }

            return $this->present($this->setStatus($user, $c, 'counting', $c->freeze ? 'جُمدت '.count($rowIds).' صفًا أثناء العد' : null), $user);
        });
    }

    /**
     * Counter enters physical quantities (any subset of lines; may be called repeatedly while counting).
     *
     * @param  array<int,array{lineId:string, countedQty:int}>  $lines
     */
    public function enter(AuthUser $user, string $id, array $lines): array
    {
        return DB::transaction(function () use ($user, $id, $lines) {
            $c = $this->load($id);
            if ($c->status !== 'counting') {
                throw AppError::rule('COUNT_NOT_COUNTING', "إدخال العد ممكن فقط والجرد قيد العد (الحالة: {$this->statusLabel($c->status)})", "Entries only while counting (status {$c->status})");
            }
            if ($c->counter_id && $c->counter_id !== $user->id && ! $user->can('inventory.adjust')) {
                throw AppError::rule('COUNT_NOT_COUNTER', 'هذا الجرد مسند لعدّاد آخر', 'Count is assigned to another counter');
            }
            $byId = $c->lines->keyBy('id');
            foreach ($lines as $l) {
                $line = $byId->get($l['lineId'])
                    ?? throw AppError::validation('BAD_LINE', "السطر {$l['lineId']} ليس ضمن هذا الجرد", "Line {$l['lineId']} not in this count");
                $line->update(['counted_qty' => (int) $l['countedQty']]);
            }
            $this->audit->log($user, ['action' => 'COUNT.ENTER', 'entityType' => 'InventoryCount', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['lines' => count($lines)]]);

            return $this->present($this->load($c->id), $user);
        });
    }

    /** counting → variance. Every line must have been counted. */
    public function complete(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $c = $this->load($id);
            $missing = $c->lines->whereNull('counted_qty');
            if ($c->status === 'counting' && $missing->isNotEmpty()) {
                throw AppError::rule('COUNT_INCOMPLETE', "{$missing->count()} سطر لم يُعد بعد", "{$missing->count()} line(s) not counted yet", ['missing' => $missing->pluck('id')->values()->all()]);
            }
            $variances = $c->lines->filter(fn (CountLine $l) => $l->counted_qty !== $l->system_qty)->count();

            return $this->present($this->setStatus($user, $c, 'variance', $variances ? "{$variances} فرق بانتظار اعتماد التسوية" : 'لا فروقات'), $user);
        });
    }

    /** variance → adjusted: posts an `adj` movement per variance line through the engine, unblocks the rows. */
    public function approve(AuthUser $user, string $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $note) {
            $c = $this->load($id);
            if ($c->status !== 'variance') {
                throw AppError::rule('COUNT_TRANSITION', "انتقال غير مسموح: {$this->statusLabel($c->status)} ← {$this->statusLabel('adjusted')}", "Invalid transition {$c->status} → adjusted");
            }
            $txId = $this->inv->newTxId();
            $rowIds = [];
            $adjustments = [];
            foreach ($c->lines as $l) {
                $row = $this->lineRow($l);
                if ($row) {
                    $rowIds[] = $row->id;
                }
                if ($l->counted_qty === null || $l->counted_qty === $l->system_qty) {
                    continue;
                }
                // adjust against the CURRENT on-hand (equals systemQty when frozen); the engine refuses to go below reserved
                $current = $row?->on_hand ?? 0;
                $delta = $l->counted_qty - $current;
                if ($delta === 0) {
                    continue;
                }
                if ($row) {
                    $this->inv->setBlocked([$row->id], false); // unblock before posting
                }
                $mv = $this->inv->post($user, $txId, [
                    'type' => 'adj', 'productId' => $l->product_id, 'batchId' => $l->batch_id, 'qty' => abs($delta),
                    ($delta > 0 ? 'to' : 'from') => ['warehouseId' => $c->warehouse_id, 'binId' => $l->bin_id],
                    'reference' => ['type' => 'InventoryCount', 'id' => $c->id, 'number' => $c->number], 'note' => "{$c->number} · تسوية جرد معتمدة",
                ]);
                $adjustments[] = ['line' => $l->id, 'sku' => $l->product->sku, 'bin' => $l->bin->code, 'from' => $current, 'to' => $l->counted_qty, 'movement' => $mv->number];
                $this->audit->log($user, ['action' => 'COUNT.ADJUST', 'entityType' => 'InventoryBalance', 'entityId' => $row?->id, 'entityNumber' => "{$l->product->sku} @ {$c->warehouse->code}/{$l->bin->code}",
                    'field' => 'onHand', 'oldValue' => $current, 'newValue' => $l->counted_qty, 'transactionId' => $txId]);
            }
            $this->inv->setBlocked($rowIds, false);
            $n = count($adjustments);
            $upd = $this->setStatus($user, $c, 'adjusted', $note ?: ($n ? "اعتُمدت {$n} تسوية ورُفع التجميد" : 'لا فروقات — رُفع التجميد'), ['approved_by_id' => $user->id, 'adjusted_at' => now()]);

            return $this->present($upd, $user) + ['adjustments' => $adjustments];
        });
    }

    /** adjusted → closed (unblocks rows defensively). */
    public function close(AuthUser $user, string $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $id, $note) {
            $c = $this->load($id);
            $rowIds = $c->lines->map(fn (CountLine $l) => $this->lineRow($l)?->id)->filter()->values()->all();
            $this->inv->setBlocked($rowIds, false);

            return $this->present($this->setStatus($user, $c, 'closed', $note), $user);
        });
    }
}
