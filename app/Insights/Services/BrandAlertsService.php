<?php

namespace App\Insights\Services;

use App\Insights\Models\AlertRule;
use App\Insights\Models\BrandAlert;
use App\Insights\Models\BrandOwner;
use App\Insights\Support\Access;
use App\Insights\Support\AlertRules;
use App\Models\Notification;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The eight alerts of the brand dashboards (rules: App\Insights\Support\AlertRules), computed for every ACTIVE brand
 * owner by `run()` — the scheduled command `scm:insights-alerts`, or a holder of `brandowner.manage` on demand. An
 * occurrence is raised once: the row is keyed by (owner, occurrence key) and a second run, or two runs at the same
 * time, insert nothing more (the database refuses the twin). Each alert keeps the figures it was raised on (`data`).
 */
class BrandAlertsService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BrandScope $scope,
        private readonly SalesFacts $facts,
        private readonly BrandDashboardService $dashboard,
    ) {}

    // ───────────── rules ─────────────

    /** @return array<string, array{type:string, enabled:bool, threshold:float, window:int, unit:string, severity:string, label:array, rule:array}> */
    public function rules(): array
    {
        $rows = AlertRule::all()->keyBy('type');
        $out = [];
        foreach (AlertRules::DEFAULTS as $type => $d) {
            $r = $rows[$type] ?? null;
            $threshold = $r ? (float) $r->threshold : (float) $d['threshold'];
            $window = $r ? (int) $r->window_size : (int) $d['window'];
            $out[$type] = ['type' => $type, 'enabled' => $r ? $r->enabled : true, 'threshold' => $threshold, 'window' => $window, 'unit' => $d['unit'], 'severity' => $r?->severity ?? $d['severity'],
                'label' => AlertRules::LABELS[$type], 'rule' => AlertRules::text($type, $threshold, $window), 'updatedBy' => $r?->updated_by, 'updatedAt' => $r?->updated_at?->toJSON()];
        }

        return $out;
    }

    public function updateRule(AuthUser $user, string $type, array $d): array
    {
        if (! isset(AlertRules::DEFAULTS[$type])) {
            throw AppError::notFound('ALERT_TYPE_NOT_FOUND', 'نوع التنبيه غير موجود', 'Alert type not found');
        }
        $unit = AlertRules::DEFAULTS[$type]['unit'];
        if (isset($d['window']) && $unit === 'months' && ($d['window'] < 1 || $d['window'] > 12)) {
            throw AppError::validation('ALERT_WINDOW', 'عدد الأشهر بين ١ و١٢', 'The number of months is between 1 and 12');
        }
        if (isset($d['window']) && $unit === 'days' && $d['window'] > 365) {
            throw AppError::validation('ALERT_WINDOW', 'عدد الأيام لا يتجاوز ٣٦٥', 'The number of days is at most 365');
        }
        DB::transaction(function () use ($user, $type, $d) {
            $current = $this->rules()[$type];
            $row = AlertRule::lockForUpdate()->find($type) ?? new AlertRule(['type' => $type, 'enabled' => true, 'threshold' => $current['threshold'], 'window_size' => $current['window'], 'severity' => $current['severity']]);
            $old = ['enabled' => $current['enabled'], 'threshold' => $current['threshold'], 'window' => $current['window']];
            $row->fill(array_filter(['enabled' => $d['enabled'] ?? null, 'threshold' => isset($d['threshold']) ? round((float) $d['threshold'], 2) : null, 'window_size' => $d['window'] ?? null], fn ($v) => $v !== null));
            $row->updated_by = $user->username;
            $row->save();
            $this->audit->log($user, ['action' => 'BRAND_ALERT_RULE.UPDATE', 'entityType' => 'BrandAlertRule', 'entityId' => $type, 'entityNumber' => $type, 'oldValue' => $old,
                'newValue' => ['enabled' => $row->enabled, 'threshold' => (float) $row->threshold, 'window' => $row->window_size]]);
        });

        return $this->rules()[$type];
    }

    // ───────────── computing ─────────────

    /**
     * Computes the rules for every active owner (or one) as of `$today`.
     *
     * @return array{owners:int, raised:int, byType:array<string,int>}
     */
    public function run(?AuthUser $actor = null, ?string $ownerId = null, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $rules = array_filter($this->rules(), fn ($r) => $r['enabled']);
        $owners = BrandOwner::where('state', 'active')->when($ownerId, fn ($q) => $q->where('id', $ownerId))->orderBy('name')->get();
        $out = ['owners' => 0, 'raised' => 0, 'byType' => []];
        foreach ($owners as $o) {
            $raised = $this->owner($o, $rules, $today);
            $out['owners']++;
            foreach ($raised as $a) {
                $out['raised']++;
                $out['byType'][$a['alert_type']] = ($out['byType'][$a['alert_type']] ?? 0) + 1;
            }
            if ($raised && $o->user_id) {
                $n = count($raised);
                Notification::create(['user_id' => $o->user_id, 'text_ar' => "{$n} تنبيه جديد في لوحة علامة {$o->name}", 'text_en' => "{$n} new alert(s) on the {$o->name} brand dashboard",
                    'entity_type' => 'BrandOwner', 'entity_id' => $o->id, 'entity_number' => $o->name, 'at' => now()]);
            }
        }
        if ($actor) {
            $this->audit->log($actor, ['action' => 'BRAND_ALERT.RUN', 'entityType' => 'BrandAlert', 'entityNumber' => $today->toDateString(), 'newValue' => $out]);
        }

        return $out;
    }

    /** @return array<int, array> the alerts raised for this owner now */
    private function owner(BrandOwner $o, array $rules, Carbon $today): array
    {
        $products = $this->scope->productIds($o);
        $franchisees = $this->scope->franchisees($o, true);
        if (! $products || ! $franchisees) {
            return [];
        }
        $customers = array_keys($franchisees);
        $last = $today->copy()->startOfMonth()->subMonthNoOverflow();          // the last complete month
        $L = $last->format('Y-m');
        $months = max(1, (int) max($rules['purchase_drop']['window'] ?? 1, $rules['product_drop']['window'] ?? 1));
        $from = $last->copy()->subMonthsNoOverflow($months)->toDateString();
        $lines = $this->facts->lines($products, $customers, $from, $last->copy()->endOfMonth()->toDateString());
        $net = $gross = $credit = $qty = [];
        foreach ($lines as $l) {
            $m = substr($l['date'], 0, 7);
            $c = $l['customerId'];
            $net[$c][$m] = ($net[$c][$m] ?? 0) + $l['amount'];
            if ($l['kind'] === 'credit_note') {
                $credit[$c][$m] = ($credit[$c][$m] ?? 0) - $l['amount'];
            } else {
                $gross[$c][$m] = ($gross[$c][$m] ?? 0) + $l['amount'];
            }
            if ($l['productId']) {
                $qty[$c][$l['productId']][$m] = ($qty[$c][$l['productId']][$m] ?? 0) + $l['qty'];
            }
        }
        $prev = fn (int $n) => array_map(fn ($i) => $last->copy()->subMonthsNoOverflow($i)->format('Y-m'), range(1, $n));
        $new = [];
        $raise = function (string $type, ?string $customer, string $key, array $text, array $data, ?string $productId = null, ?array $action = null) use ($o, $rules, $today, &$new) {
            $new[] = [
                'id' => (string) Str::ulid(), 'owner_id' => $o->id, 'customer_id' => $customer, 'product_id' => $productId, 'alert_type' => $type, 'occurrence_key' => mb_substr("{$type}:{$key}", 0, 190),
                'title_ar' => mb_substr($text['ar'], 0, 250), 'title_en' => mb_substr($text['en'], 0, 250), 'description_ar' => $rules[$type]['rule']['ar'], 'description_en' => $rules[$type]['rule']['en'],
                'severity' => $rules[$type]['severity'], 'date' => $today->toDateString(), 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'is_read' => false,
                'action_model' => $action['model'] ?? null, 'action_ref' => $action['ref'] ?? null, 'created_at' => now(),
            ];
        };
        $name = fn (string $c) => $franchisees[$c]->customer?->name_ar ?? $c;
        $nameEn = fn (string $c) => $franchisees[$c]->customer?->name_en ?? $c;

        if (isset($rules['no_purchase'])) {
            $lastBuy = $this->facts->lastPurchase($products, $customers);
            foreach ($franchisees as $c => $f) {
                $ref = $lastBuy[$c] ?? $f->date_start?->toDateString() ?? $f->created_at?->toDateString();
                if (! $ref) {
                    continue;
                }
                $days = (int) Carbon::parse($ref)->startOfDay()->diffInDays($today);
                if ($days > $rules['no_purchase']['window']) {
                    $raise('no_purchase', $c, $c.':'.($lastBuy[$c] ?? 'never'), ['ar' => "{$name($c)}: لا شراء منذ {$days} يومًا", 'en' => "{$nameEn($c)}: no purchase for {$days} days"],
                        ['lastPurchase' => $lastBuy[$c] ?? null, 'since' => $ref, 'days' => $days, 'window' => $rules['no_purchase']['window']]);
                }
            }
        }
        foreach ($franchisees as $c => $f) {
            $now = round($net[$c][$L] ?? 0, 2);
            $target = (float) $f->monthly_target;
            $started = ! $f->date_start || $f->date_start->format('Y-m') <= $L;
            if (isset($rules['purchase_drop'])) {
                $r = $rules['purchase_drop'];
                $avg = round(array_sum(array_map(fn ($m) => $net[$c][$m] ?? 0, $prev($r['window']))) / $r['window'], 2);
                if ($avg > 0 && $now <= round($avg * (1 - $r['threshold'] / 100), 2)) {
                    $pct = round(($avg - $now) / $avg * 100, 1);
                    $raise('purchase_drop', $c, "{$c}:{$L}", ['ar' => "{$name($c)}: انخفضت المشتريات {$pct}% في {$L}", 'en' => "{$nameEn($c)}: purchases down {$pct}% in {$L}"],
                        ['month' => $L, 'purchases' => $now, 'average' => $avg, 'months' => $prev($r['window']), 'dropPct' => $pct, 'threshold' => $r['threshold']]);
                }
            }
            if ($target > 0 && $started) {
                $ach = round($now / $target * 100, 1);
                if (isset($rules['target_exceeded']) && $ach >= $rules['target_exceeded']['threshold']) {
                    $raise('target_exceeded', $c, "{$c}:{$L}", ['ar' => "{$name($c)}: حقق {$ach}% من الهدف في {$L}", 'en' => "{$nameEn($c)}: reached {$ach}% of target in {$L}"],
                        ['month' => $L, 'purchases' => $now, 'target' => round($target, 2), 'achievementPct' => $ach, 'threshold' => $rules['target_exceeded']['threshold']]);
                }
                $fullMonth = ! $f->date_start || $f->date_start->format('Y-m') < $L;
                if (isset($rules['target_not_met']) && $fullMonth && $ach < $rules['target_not_met']['threshold']) {
                    $raise('target_not_met', $c, "{$c}:{$L}", ['ar' => "{$name($c)}: {$ach}% فقط من الهدف في {$L}", 'en' => "{$nameEn($c)}: only {$ach}% of target in {$L}"],
                        ['month' => $L, 'purchases' => $now, 'target' => round($target, 2), 'achievementPct' => $ach, 'threshold' => $rules['target_not_met']['threshold']]);
                }
            }
            if (isset($rules['high_returns'])) {
                $g = round($gross[$c][$L] ?? 0, 2);
                $cr = round($credit[$c][$L] ?? 0, 2);
                if ($g > 0 && $cr > 0 && round($cr / $g * 100, 1) >= $rules['high_returns']['threshold']) {
                    $pct = round($cr / $g * 100, 1);
                    $raise('high_returns', $c, "{$c}:{$L}", ['ar' => "{$name($c)}: مرتجعات {$pct}% في {$L}", 'en' => "{$nameEn($c)}: returns {$pct}% in {$L}"],
                        ['month' => $L, 'invoiced' => $g, 'returns' => $cr, 'returnsPct' => $pct, 'threshold' => $rules['high_returns']['threshold']]);
                }
            }
            if (isset($rules['product_drop'])) {
                $r = $rules['product_drop'];
                foreach ($qty[$c] ?? [] as $p => $byMonth) {
                    $before = array_map(fn ($m) => $byMonth[$m] ?? 0, $prev($r['window']));
                    if (min($before) <= 0) {
                        continue;   // not bought in every one of those months
                    }
                    $avg = array_sum($before) / $r['window'];
                    $q = $byMonth[$L] ?? 0;
                    if ($q <= $avg * (1 - $r['threshold'] / 100)) {
                        $pct = round(($avg - $q) / $avg * 100, 1);
                        $sku = DB::table('products')->where('id', $p)->value('sku') ?? $p;
                        $raise('product_drop', $c, "{$c}:{$p}:{$L}", ['ar' => "{$name($c)}: انخفضت كمية {$sku} {$pct}% في {$L}", 'en' => "{$nameEn($c)}: {$sku} quantity down {$pct}% in {$L}"],
                            ['month' => $L, 'product' => $sku, 'qty' => $q, 'average' => round($avg, 3), 'dropPct' => $pct, 'threshold' => $r['threshold']], $p);
                    }
                }
            }
        }
        if (isset($rules['cancelled_orders'])) {
            $r = $rules['cancelled_orders'];
            $since = $today->copy()->subDays($r['window']);
            $cancelled = DB::table('sales_orders as o')->where('o.status', 'cancelled')->whereIn('o.customer_id', $customers)->where('o.updated_at', '>=', $since)
                ->whereExists(fn ($q) => $q->from('sales_order_lines as l')->whereColumn('l.so_id', 'o.id')->whereIn('l.product_id', $products))
                ->get(['o.customer_id', 'o.number']);
            foreach ($cancelled->groupBy('customer_id') as $c => $orders) {
                if ($orders->count() >= $r['threshold']) {
                    $n = $orders->count();
                    $raise('cancelled_orders', (string) $c, "{$c}:".$today->format('Y-m'), ['ar' => "{$name($c)}: {$n} طلبات ملغاة خلال {$r['window']} يومًا", 'en' => "{$nameEn($c)}: {$n} cancelled orders in {$r['window']} days"],
                        ['orders' => $orders->pluck('number')->values()->all(), 'count' => $n, 'window' => $r['window'], 'threshold' => $r['threshold']]);
                }
            }
        }
        if (isset($rules['overdue_invoice'])) {
            foreach ($this->dashboard->overdue($o, $products, $customers, (int) $rules['overdue_invoice']['window']) as $i) {
                $c = $i['customerId'];
                $raise('overdue_invoice', $c, "{$i['source']}:{$i['documentId']}", ['ar' => "{$name($c)}: الفاتورة {$i['number']} متأخرة {$i['daysOverdue']} يومًا", 'en' => "{$nameEn($c)}: invoice {$i['number']} {$i['daysOverdue']} days overdue"],
                    ['number' => $i['number'], 'source' => $i['source'], 'dueDate' => $i['dueDate'], 'daysOverdue' => $i['daysOverdue'], 'brandAmount' => $i['brandAmount'], 'openAmount' => $i['openAmount']],
                    null, ['model' => $i['source'] === 'ops' ? 'CustomerInvoice' : 'account.move', 'ref' => $i['documentId']]);
            }
        }
        if (! $new) {
            return [];
        }
        // what is raised already stays as it was: the twin is refused by the unique key, also under a concurrent run
        $existing = BrandAlert::where('owner_id', $o->id)->whereIn('occurrence_key', array_column($new, 'occurrence_key'))->pluck('occurrence_key')->flip();
        $fresh = array_values(array_filter($new, fn ($a) => ! isset($existing[$a['occurrence_key']])));
        $raised = [];
        foreach ($fresh as $a) {
            if (DB::table('ins_brand_alerts')->insertOrIgnore($a) > 0) {
                $raised[] = $a;
            }
        }

        return $raised;
    }

    // ───────────── reading ─────────────

    public function list(AuthUser $user, ?string $ownerId, array $f, Paging $page): array
    {
        $q = BrandAlert::query()->with(['customer:id,code,name_ar,name_en', 'owner:id,name,brand_name']);
        if ($ownerId) {
            $q->where('owner_id', Access::owner($user, $ownerId)->id);
        } elseif (! Access::manager($user)) {
            $mine = Access::ownerOf($user) ?? throw AppError::forbidden('BRAND_FORBIDDEN', 'لا تملك الاطلاع على العلامات', 'You may not see brand owners');
            $q->where('owner_id', Access::owner($user, $mine->id)->id);
        }
        if (($f['read'] ?? null) !== null && $f['read'] !== '') {
            $q->where('is_read', in_array($f['read'], ['1', 'true', true], true));
        }
        foreach (['type' => 'alert_type', 'severity' => 'severity', 'customer' => 'customer_id'] as $in => $col) {
            if (! empty($f[$in])) {
                $q->where($col, $f[$in]);
            }
        }
        if (! empty($f['from'])) {
            $q->where('date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('title_ar', 'like', $like)->orWhere('title_en', 'like', $like));
        }
        $page->sort === 'severity' ? $q->orderBy('severity', $page->order) : $q->orderBy('date', $page->sort === 'date' ? $page->order : 'desc');
        $q->orderByDesc('created_at')->orderByDesc('id');
        $unread = (clone $q)->where('is_read', false)->count();

        return $page->paginate($q, fn (BrandAlert $a) => $this->row($a)) + ['unread' => $unread];
    }

    public function mark(AuthUser $user, string $id, bool $read): array
    {
        $a = DB::transaction(function () use ($user, $id, $read) {
            $a = BrandAlert::lockForUpdate()->find($id) ?? throw AppError::notFound('BRAND_ALERT_NOT_FOUND', 'التنبيه غير موجود', 'Alert not found');
            Access::owner($user, $a->owner_id);
            if ($a->is_read !== $read) {
                $a->update(['is_read' => $read, 'read_at' => $read ? now() : null, 'read_by' => $read ? $user->username : null]);
                $this->audit->log($user, ['action' => $read ? 'BRAND_ALERT.READ' : 'BRAND_ALERT.UNREAD', 'entityType' => 'BrandAlert', 'entityId' => $a->id, 'entityNumber' => $a->alert_type]);
            }

            return $a;
        });

        return $this->row($a->fresh(['customer', 'owner']));
    }

    public function readAll(AuthUser $user, string $ownerId): array
    {
        $o = Access::owner($user, $ownerId);
        $n = DB::transaction(function () use ($user, $o) {
            $n = BrandAlert::where('owner_id', $o->id)->where('is_read', false)->update(['is_read' => true, 'read_at' => now(), 'read_by' => $user->username]);
            if ($n) {
                $this->audit->log($user, ['action' => 'BRAND_ALERT.READ_ALL', 'entityType' => 'BrandOwner', 'entityId' => $o->id, 'entityNumber' => $o->name, 'newValue' => ['read' => $n]]);
            }

            return $n;
        });

        return ['updated' => $n, 'unread' => 0];
    }

    public function row(BrandAlert $a): array
    {
        return [
            'id' => $a->id, 'ownerId' => $a->owner_id, 'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => $a->owner->name, 'brandName' => $a->owner->brand_name] : null,
            'type' => $a->alert_type, 'typeLabel' => AlertRules::LABELS[$a->alert_type] ?? ['ar' => $a->alert_type, 'en' => $a->alert_type], 'severity' => $a->severity,
            'titleAr' => $a->title_ar, 'titleEn' => $a->title_en, 'descriptionAr' => $a->description_ar, 'descriptionEn' => $a->description_en, 'date' => $a->date?->toDateString(),
            'customer' => $a->customer ? ['id' => $a->customer->id, 'code' => $a->customer->code, 'nameAr' => $a->customer->name_ar, 'nameEn' => $a->customer->name_en] : null,
            'productId' => $a->product_id, 'data' => $a->data, 'action' => $a->action_model ? ['model' => $a->action_model, 'ref' => $a->action_ref] : null,
            'read' => $a->is_read, 'readAt' => $a->read_at?->toJSON(), 'readBy' => $a->read_by, 'sourceSystem' => $a->source_system, 'createdAt' => $a->created_at?->toJSON(),
        ];
    }
}
