<?php

namespace App\Insights\Services;

use App\Insights\Models\ExpiryLevel;
use App\Insights\Models\ExpiryLog;
use App\Insights\Support\Owned;
use App\Models\Notification;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expiry alert levels and their log (Odoo "Inventory Expiry Tracker"). The lots are the OPS batches; the quantity is
 * what the inventory balances hold of them (every warehouse, every bin). Rule of `run()`: a batch with stock whose
 * expiry date is between today and `days_before_expiry` days from today is announced ONCE per level — the log row is
 * keyed by (level, batch) and the database refuses a second one, also under two runs at the same time. Expired
 * batches are not announced here: the batches page and the expiry report already show them.
 *
 * Channels: in-app = a notification to each recipient; e-mail is recorded as `skipped` (no mail provider is configured
 * in OPS) — never reported as sent.
 */
class ExpiryService
{
    public function __construct(private readonly AuditService $audit, private readonly NotifyService $notify) {}

    // ───────────── levels ─────────────

    public function levels(array $f = []): array
    {
        $q = ExpiryLevel::with('recipients:id,username,name_ar,name_en')->withCount('log');
        if (($f['active'] ?? null) !== null && $f['active'] !== '') {
            $q->where('active', in_array($f['active'], ['1', 'true', true], true));
        }

        return $q->orderBy('sequence')->orderByDesc('days_before_expiry')->orderBy('id')->get()->map(fn (ExpiryLevel $l) => $this->levelRow($l))->all();
    }

    public function createLevel(AuthUser $user, array $d): array
    {
        $l = DB::transaction(function () use ($user, $d) {
            $l = ExpiryLevel::create([
                'name' => trim($d['name']), 'days_before_expiry' => (int) $d['daysBeforeExpiry'], 'notification_type' => $d['notificationType'] ?? 'inapp',
                'sequence' => (int) ($d['sequence'] ?? 10), 'color' => (int) ($d['color'] ?? 0), 'active' => true, 'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            $l->recipients()->sync($this->users($d['userIds']));
            $this->audit->log($user, ['action' => 'EXPIRY_LEVEL.CREATE', 'entityType' => 'ExpiryLevel', 'entityId' => $l->id, 'entityNumber' => $l->name,
                'newValue' => ['days' => $l->days_before_expiry, 'channel' => $l->notification_type, 'recipients' => count($d['userIds'])]]);

            return $l;
        });

        return $this->levelRow($l->fresh('recipients')->loadCount('log'));
    }

    public function updateLevel(AuthUser $user, string $id, array $d): array
    {
        $l = DB::transaction(function () use ($user, $id, $d) {
            $l = ExpiryLevel::lockForUpdate()->find($id) ?? throw AppError::notFound('EXPIRY_LEVEL_NOT_FOUND', 'المستوى غير موجود', 'Level not found');
            Owned::refuse($l, 'level');
            $values = [];
            foreach (['name' => 'name', 'daysBeforeExpiry' => 'days_before_expiry', 'notificationType' => 'notification_type', 'sequence' => 'sequence', 'color' => 'color', 'active' => 'active'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $values[$col] = is_string($d[$in]) ? trim($d[$in]) : $d[$in];
                }
            }
            $old = array_map(fn ($c) => $l->getAttribute($c), array_combine(array_keys($values), array_keys($values)));
            $l->update($values + ['updated_by' => $user->username]);
            if (array_key_exists('userIds', $d)) {
                $old['recipients'] = $l->recipients()->pluck('users.id')->all();
                $l->recipients()->sync($this->users($d['userIds']));
                $values['recipients'] = $d['userIds'];
            }
            $this->audit->log($user, ['action' => 'EXPIRY_LEVEL.UPDATE', 'entityType' => 'ExpiryLevel', 'entityId' => $l->id, 'entityNumber' => $l->name, 'oldValue' => $old, 'newValue' => $values]);

            return $l;
        });

        return $this->levelRow($l->fresh('recipients')->loadCount('log'));
    }

    /** The active OPS users a level may name as recipients (for the level form; expiryalert.manage). */
    public function recipients(): array
    {
        return User::where('active', true)->where('username', 'not like', 'svc.%')->orderBy('username')->orderBy('id')->get(['id', 'username', 'name_ar', 'name_en'])
            ->map(fn ($u) => ['id' => (string) $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en])->all();
    }

    // ───────────── running ─────────────

    /** @return array{levels:int, announced:int, byLevel:array<string,int>} */
    public function run(?AuthUser $actor = null, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $levels = ExpiryLevel::with('recipients:id')->where('active', true)->orderBy('sequence')->orderBy('id')->get();
        $out = ['levels' => $levels->count(), 'announced' => 0, 'byLevel' => []];
        if ($levels->isEmpty()) {
            return $out;
        }
        $horizon = $today->copy()->addDays((int) $levels->max('days_before_expiry'))->endOfDay();
        $batches = $this->stocked($today, $horizon);
        foreach ($levels as $level) {
            $limit = $today->copy()->addDays($level->days_before_expiry)->endOfDay();
            $recipients = $level->recipients->pluck('id')->map(fn ($id) => (string) $id)->all();
            $announced = [];
            foreach ($batches as $b) {
                if (Carbon::parse($b->expiry_date)->gt($limit)) {
                    continue;
                }
                $days = (int) Carbon::parse($b->expiry_date)->startOfDay()->diffInDays($today, true);
                $row = ['id' => (string) Str::ulid(), 'level_id' => $level->id, 'batch_id' => $b->id, 'product_id' => $b->product_id, 'batch_no' => $b->batch_no, 'expiry_date' => $b->expiry_date,
                    'qty_on_hand' => (int) $b->qty, 'days_left' => $days, 'notified_at' => now(), 'notification_type' => $level->notification_type, 'recipients' => json_encode($recipients),
                    'email_status' => $level->notification_type === 'inapp' ? 'none' : 'skipped', 'created_at' => now()];
                if (DB::table('ins_expiry_log')->insertOrIgnore($row) > 0) {
                    $announced[] = $b;
                }
            }
            if ($announced) {
                $n = count($announced);
                $out['announced'] += $n;
                $out['byLevel'][$level->name] = $n;
                $first = collect($announced)->take(5)->map(fn ($b) => "{$b->sku}/{$b->batch_no}")->implode('، ');
                if ($level->notification_type !== 'email') {
                    foreach ($recipients as $userId) {
                        Notification::create(['user_id' => $userId, 'text_ar' => "تنبيه صلاحية «{$level->name}»: {$n} دفعة تنتهي خلال {$level->days_before_expiry} يومًا ({$first})",
                            'text_en' => "Expiry alert \"{$level->name}\": {$n} lot(s) expire within {$level->days_before_expiry} days ({$first})", 'entity_type' => 'ExpiryLevel', 'entity_id' => $level->id, 'entity_number' => $level->name, 'at' => now()]);
                    }
                }
                $this->notify->activity($actor, 'ExpiryLevel', $level->id, $level->name, "تنبيه صلاحية «{$level->name}»: {$n} دفعة", "Expiry alert \"{$level->name}\": {$n} lot(s)");
            }
        }
        if ($actor) {
            $this->audit->log($actor, ['action' => 'EXPIRY_ALERT.RUN', 'entityType' => 'ExpiryLevel', 'entityNumber' => $today->toDateString(), 'newValue' => $out]);
        }

        return $out;
    }

    /** Batches with stock expiring between today and `$until`, earliest first. */
    private function stocked(Carbon $today, Carbon $until)
    {
        return DB::table('batches as bt')->join('inventory_balances as b', 'b.batch_id', '=', 'bt.id')->join('products as p', 'p.id', '=', 'bt.product_id')
            ->whereNotNull('bt.expiry_date')->where('bt.expiry_date', '>=', $today)->where('bt.expiry_date', '<=', $until)
            ->groupBy('bt.id', 'bt.product_id', 'bt.batch_no', 'bt.expiry_date', 'p.sku')->havingRaw('sum(b.on_hand) > 0')
            ->orderBy('bt.expiry_date')->orderBy('bt.id')
            ->get(['bt.id', 'bt.product_id', 'bt.batch_no', 'bt.expiry_date', 'p.sku', DB::raw('sum(b.on_hand) as qty')]);
    }

    // ───────────── reading ─────────────

    public function log(Paging $page, array $f): array
    {
        $q = ExpiryLog::query()->with(['level:id,name,days_before_expiry,color', 'product:id,sku,name_ar,name_en']);
        if (! empty($f['level'])) {
            $q->where('level_id', $f['level']);
        }
        if (! empty($f['sku'])) {
            $q->whereHas('product', fn ($p) => $p->where('sku', $f['sku']));
        }
        if (! empty($f['source'])) {
            $f['source'] === 'odoo' ? $q->where('source_system', 'odoo') : $q->whereNull('source_system');
        }
        if (! empty($f['from'])) {
            $q->where('notified_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $q->where('notified_at', '<=', Carbon::parse($f['to'])->endOfDay());
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('batch_no', 'like', $like)->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
        }
        match ($page->sort) {
            'expiry' => $q->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date', $page->order),
            default => $q->orderBy('notified_at', $page->sort === 'notifiedAt' ? $page->order : 'desc'),
        };
        $q->orderBy('id');
        $users = [];

        return $page->paginate($q, function (ExpiryLog $l) use (&$users) {
            $ids = array_values(array_filter((array) $l->recipients));
            $missing = array_diff($ids, array_keys($users));
            if ($missing) {
                $users += User::whereIn('id', $missing)->get(['id', 'username', 'name_ar'])->keyBy('id')->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar])->all();
            }

            return [
                'id' => $l->id, 'level' => $l->level ? ['id' => $l->level->id, 'name' => $l->level->name, 'days' => $l->level->days_before_expiry, 'color' => $l->level->color] : null,
                'batchId' => $l->batch_id, 'batchNo' => $l->batch_no, 'product' => $l->product ? ['id' => $l->product->id, 'sku' => $l->product->sku, 'nameAr' => $l->product->name_ar, 'nameEn' => $l->product->name_en] : null,
                'expiry' => $l->expiry_date?->toJSON(), 'qtyOnHand' => $l->qty_on_hand, 'daysLeft' => $l->days_left, 'notifiedAt' => $l->notified_at?->toJSON(), 'channel' => $l->notification_type,
                'emailStatus' => $l->email_status, 'recipients' => array_values(array_filter(array_map(fn ($id) => $users[$id] ?? null, $ids))), 'sourceSystem' => $l->source_system,
            ];
        });
    }

    /** Batches with stock that the active levels will announce or have announced: what each level says of each. */
    public function upcoming(Paging $page, array $f): array
    {
        $today = now()->startOfDay();
        $levels = ExpiryLevel::where('active', true)->orderByDesc('days_before_expiry')->orderBy('id')->get(['id', 'name', 'days_before_expiry', 'color']);
        if ($levels->isEmpty()) {
            return $page->wrap([], 0) + ['levels' => []];
        }
        $rows = $this->stocked($today, $today->copy()->addDays((int) $levels->max('days_before_expiry'))->endOfDay());
        if ($page->q) {
            $needle = mb_strtolower($page->q);
            $rows = $rows->filter(fn ($b) => str_contains(mb_strtolower($b->sku), $needle) || str_contains(mb_strtolower($b->batch_no), $needle));
        }
        $total = $rows->count();
        $slice = $rows->slice(($page->page - 1) * $page->pageSize, $page->pageSize)->values();
        $logged = DB::table('ins_expiry_log')->whereIn('batch_id', $slice->pluck('id'))->get(['batch_id', 'level_id', 'notified_at'])->groupBy('batch_id');
        $names = DB::table('products')->whereIn('id', $slice->pluck('product_id'))->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $items = $slice->map(function ($b) use ($levels, $logged, $today, $names) {
            $days = (int) Carbon::parse($b->expiry_date)->startOfDay()->diffInDays($today, true);
            $done = collect($logged[$b->id] ?? [])->keyBy('level_id');

            return ['batchId' => $b->id, 'batchNo' => $b->batch_no, 'sku' => $b->sku, 'nameAr' => $names[$b->product_id]->name_ar ?? null, 'nameEn' => $names[$b->product_id]->name_en ?? null,
                'expiry' => Carbon::parse($b->expiry_date)->toJSON(), 'daysLeft' => $days, 'qty' => (int) $b->qty,
                'levels' => $levels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'days' => $l->days_before_expiry, 'due' => $days <= $l->days_before_expiry,
                    'notifiedAt' => isset($done[$l->id]) ? Carbon::parse($done[$l->id]->notified_at)->toJSON() : null])->all()];
        })->all();

        return $page->wrap($items, $total) + ['levels' => $levels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'days' => $l->days_before_expiry])->all()];
    }

    private function levelRow(ExpiryLevel $l): array
    {
        return [
            'id' => $l->id, 'name' => $l->name, 'daysBeforeExpiry' => $l->days_before_expiry, 'notificationType' => $l->notification_type, 'sequence' => $l->sequence, 'color' => $l->color, 'active' => $l->active,
            'recipients' => $l->recipients->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en])->values()->all(),
            'recipientsKept' => $l->recipients_kept ?? [], 'notifications' => (int) ($l->log_count ?? 0),
            'sourceSystem' => $l->source_system, 'ownedBySource' => Owned::bySource($l), 'updatedAt' => $l->updated_at?->toJSON(),
        ];
    }

    /** @return string[] */
    private function users(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $found = User::whereIn('id', $ids)->where('active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($found) !== count($ids)) {
            throw AppError::validation('USER_NOT_FOUND', 'مستلم من القائمة غير موجود أو غير نشط', 'A recipient of the list does not exist or is inactive', ['missing' => array_values(array_diff($ids, $found))]);
        }

        return $found;
    }
}
