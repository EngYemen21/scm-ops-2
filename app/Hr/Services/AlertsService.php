<?php

namespace App\Hr\Services;

use App\Hr\Models\Contract;
use App\Hr\Models\Document;
use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Hr\Models\HrAlert;
use App\Hr\Support\Hr;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What expires: an employee's documents, the identity and the passport of the personal file, a running contract.
 * The pattern of the fleet's document alerts (FleetService::refreshDocumentAlerts): ONE open alert per thing that
 * expires, brought in step with the dates by `refresh()` — created when the date comes within 60 days, moved through
 * the stages 60 → 30 → 7 days → expired, and resolved by itself when the paper is renewed, voided or the person left.
 * The people who can act are told once per stage. Idempotent.
 *
 * Privacy: an alert about the personal file (identity, passport, a private document, a contract) is listed and
 * announced only to holders of `hr.private`; the others go to holders of `hr.manage`. Nothing is written to the
 * activity feed, which other roles read.
 *
 * `refresh()` is called when the list is opened and the last refresh is older than five minutes; under a scheduler or
 * the housekeeping heartbeat it should also run once a day (wiring).
 */
class AlertsService
{
    public const WINDOW_DAYS = 60;

    private const REFRESH_CACHE_KEY = 'hr:alerts:refreshed';

    /** @return array{created:int, updated:int, resolved:int, notified:int} */
    public function refresh(): array
    {
        $stats = DB::transaction(fn () => $this->sync(null));
        try {
            Cache::put(self::REFRESH_CACHE_KEY, time(), 3600);
        } catch (Throwable) {
            // the throttle is an optimisation only
        }

        return $stats;
    }

    /** One employee, right after something of theirs changed. Runs inside the caller's transaction. */
    public function refreshEmployee(string $employeeId): array
    {
        return $this->sync($employeeId);
    }

    private function sync(?string $employeeId): array
    {
        $limit = Carbon::today()->addDays(self::WINDOW_DAYS)->toDateString();
        $working = fn ($q) => $q->select('id')->from('hr_employees')->where('status', 'active');
        $one = fn ($q) => $employeeId ? $q->where('employee_id', $employeeId) : $q;
        $wanted = [];
        foreach ($one(Document::where('status', 'active')->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $limit)->whereIn('employee_id', $working))->get(['id', 'employee_id', 'kind', 'expiry_date', 'is_private']) as $d) {
            $wanted["document:{$d->id}"] = ['employee_id' => $d->employee_id, 'category' => 'document', 'ref_id' => $d->id, 'kind' => $d->kind, 'due_date' => Hr::day($d->expiry_date), 'is_private' => (bool) $d->is_private];
        }
        $papers = EmployeePrivate::whereIn('employee_id', $working)->where(fn ($w) => $w->whereDate('identity_expiry', '<=', $limit)->orWhereDate('passport_expiry', '<=', $limit));
        foreach ($one($papers)->get(['id', 'employee_id', 'identity_expiry', 'passport_expiry']) as $p) {
            foreach (['identity' => Hr::day($p->identity_expiry), 'passport' => Hr::day($p->passport_expiry)] as $category => $day) {
                if ($day !== null && $day <= $limit) {
                    $wanted["{$category}:{$p->id}"] = ['employee_id' => $p->employee_id, 'category' => $category, 'ref_id' => $p->id, 'kind' => null, 'due_date' => $day, 'is_private' => true];
                }
            }
        }
        foreach ($one(Contract::where('state', 'running')->whereNotNull('end_date')->whereDate('end_date', '<=', $limit)->whereIn('employee_id', $working))->get(['id', 'employee_id', 'end_date']) as $c) {
            $wanted["contract:{$c->id}"] = ['employee_id' => $c->employee_id, 'category' => 'contract', 'ref_id' => $c->id, 'kind' => null, 'due_date' => Hr::day($c->end_date), 'is_private' => true];
        }

        $open = $one(HrAlert::where('status', 'open'))->lockForUpdate()->get()->keyBy(fn (HrAlert $a) => "{$a->category}:{$a->ref_id}");
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'notified' => 0];
        foreach ($wanted as $key => $w) {
            $stage = HrAlert::stageOf((int) Hr::daysTo($w['due_date']));
            if ($stage === null) {
                continue;
            }
            $a = $open[$key] ?? null;
            if (! $a) {
                $a = HrAlert::create($w + ['stage' => $stage, 'status' => 'open']);
                $stats['created']++;
            } elseif ($a->stage !== $stage || Hr::day($a->due_date) !== $w['due_date'] || (bool) $a->is_private !== $w['is_private'] || $a->kind !== $w['kind']) {
                // a date that moved is news again, whatever people were told about the old one
                $a->update(['stage' => $stage, 'due_date' => $w['due_date'], 'is_private' => $w['is_private'], 'kind' => $w['kind']] + (Hr::day($a->due_date) !== $w['due_date'] ? ['notified_stage' => null] : []));
                $stats['updated']++;
            }
            if (HrAlert::rank($stage) > HrAlert::rank($a->notified_stage)) {
                $this->tell($a);
                $a->update(['notified_stage' => $stage]);
                $stats['notified']++;
            }
        }
        foreach ($open as $key => $a) {
            if (! isset($wanted[$key]) || HrAlert::stageOf((int) Hr::daysTo($wanted[$key]['due_date'])) === null) {
                $a->update(['status' => 'resolved', 'resolved_at' => now()]);
                $stats['resolved']++;
            }
        }

        return $stats;
    }

    /** The people who can act on it are told — the holders of the permission that lets them see it, nobody else. */
    private function tell(HrAlert $a): void
    {
        $e = Employee::find($a->employee_id, ['id', 'code', 'name_ar', 'name_en']);
        if (! $e) {
            return;
        }
        $text = self::wording($a->category, $a->kind, (int) Hr::daysTo($a->due_date), Hr::day($a->due_date));
        Hr::tellRoles($a->is_private ? 'hr.private' : 'hr.manage', "{$e->name_ar} ({$e->code}): {$text['ar']}", "{$e->name_en} ({$e->code}): {$text['en']}", 'Employee', $e->id, $e->code);
    }

    /** @return array{ar:string, en:string} what expires and when, without naming anyone */
    public static function wording(string $category, ?string $kind, int $days, ?string $date): array
    {
        [$ar, $en, $feminine] = match ($category) {
            'identity' => ['الهوية / الإقامة', 'Identity / residence permit', true],
            'passport' => ['جواز السفر', 'Passport', false],
            'contract' => ['عقد العمل', 'Employment contract', false],
            default => [Document::KINDS[$kind]['ar'] ?? 'وثيقة', Document::KINDS[$kind]['en'] ?? 'Document', true],
        };
        $n = abs($days);
        $whenAr = $days < 0 ? ($feminine ? 'منتهية' : 'منتهٍ')." منذ {$n} يومًا" : ($days === 0 ? ($feminine ? 'تنتهي اليوم' : 'ينتهي اليوم') : ($feminine ? 'تنتهي' : 'ينتهي')." خلال {$n} يومًا");
        $whenEn = $days < 0 ? "expired {$n} day(s) ago" : ($days === 0 ? 'expires today' : "expires in {$n} day(s)");

        return ['ar' => "{$ar} {$whenAr}".($date ? " ({$date})" : ''), 'en' => "{$en} {$whenEn}".($date ? " ({$date})" : '')];
    }

    /** @param  array{stage?:?string, category?:?string, employee?:?string, department?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $this->refreshWhenDue();
        $q = $this->visible($user)->with(['employee:id,code,name_ar,name_en,job_title,department_id']);
        if (! empty($f['stage'])) {
            $q->whereIn('stage', array_intersect(explode(',', (string) $f['stage']), array_keys(HrAlert::STAGES)) ?: ['-']);
        }
        if (! empty($f['category'])) {
            $q->whereIn('category', array_intersect(explode(',', (string) $f['category']), HrAlert::CATEGORIES) ?: ['-']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['department'])) {
            $q->whereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where('department_id', $f['department']));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->whereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
        }
        $q->orderBy('due_date', ($f['order'] ?? null) === 'desc' ? 'desc' : 'asc')->orderBy('id');

        return $page->paginate($q, fn (HrAlert $a) => self::shape($a)) + ['counts' => $this->counts($user)];
    }

    /** Open alerts the person may see, by stage. @return array{expired:int, d7:int, d30:int, d60:int, total:int} */
    public function counts(AuthUser $user): array
    {
        $by = $this->visible($user)->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $out = [];
        foreach (array_reverse(array_keys(HrAlert::STAGES)) as $stage) {
            $out[$stage] = (int) ($by[$stage] ?? 0);
        }

        return $out + ['total' => array_sum($out)];
    }

    /** Open alerts of one employee the person may see (the employee's card). */
    public function forEmployee(AuthUser $user, string $employeeId): array
    {
        return $this->visible($user)->where('employee_id', $employeeId)->orderBy('due_date')->orderBy('id')->get()->map(fn (HrAlert $a) => self::shape($a))->all();
    }

    private function visible(AuthUser $user)
    {
        return HrAlert::query()->where('status', 'open')->when(! Hr::seesPrivate($user), fn ($q) => $q->where('is_private', false));
    }

    public static function shape(HrAlert $a): array
    {
        $days = (int) Hr::daysTo($a->due_date);
        $text = self::wording($a->category, $a->kind, $days, null);
        $e = $a->relationLoaded('employee') ? $a->employee : null;

        return [
            'id' => $a->id, 'category' => $a->category, 'kind' => $a->kind, 'stage' => $a->stage, 'dueDate' => Hr::day($a->due_date), 'days' => $days, 'private' => (bool) $a->is_private,
            'textAr' => $text['ar'], 'textEn' => $text['en'], 'refId' => $a->ref_id, 'since' => Hr::at($a->created_at),
        ] + ($e ? ['employee' => ['id' => $e->id, 'code' => $e->code, 'nameAr' => $e->name_ar, 'nameEn' => $e->name_en, 'jobTitle' => $e->job_title]] : []);
    }

    private function refreshWhenDue(): void
    {
        try {
            $due = time() - (int) Cache::get(self::REFRESH_CACHE_KEY, 0) > 300;
        } catch (Throwable) {
            $due = true;
        }
        if ($due) {
            try {
                $this->refresh();
            } catch (Throwable $e) {
                Log::warning('hr alert refresh failed: '.class_basename($e)); // never the message: it may carry a person's values
            }
        }
    }
}
