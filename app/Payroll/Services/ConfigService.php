<?php

namespace App\Payroll\Services;

use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\AttendanceRule;
use App\Payroll\Models\AttendanceRuleLine;
use App\Payroll\Models\Holiday;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\Profile;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\ScheduleDay;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What working time is made of: work schedules, public holidays, kinds of time off and attendance policies. Changing
 * any of them needs `payroll.manage`; nothing is deleted — a schedule, a holiday, a type or a policy is switched off.
 *
 * What came from Odoo is Odoo's while the section follows Odoo (`…_OWNED_BY_SOURCE`); the columns OPS adds on such a
 * record stay editable — the yearly allowance of a leave type, the grace of a policy, which schedule is the default.
 * A change here applies from now on: sheets and payslips already made keep the figures they were made with.
 */
class ConfigService
{
    /** What Odoo holds of a leave type; the rest is OPS's own. */
    private const LEAVE_TYPE_OWNED = ['name_ar', 'name_en', 'paid', 'requires_allocation', 'approval', 'requires_document', 'unit', 'active', 'sequence', 'color'];

    public function __construct(private readonly AuditService $audit) {}

    // ───────────────────────────── schedules ─────────────────────────────

    public function schedules(): array
    {
        $used = Profile::whereNotNull('schedule_id')->selectRaw('schedule_id, count(*) as n')->groupBy('schedule_id')->pluck('n', 'schedule_id');

        return Schedule::with('days')->orderByDesc('is_default')->orderByDesc('active')->orderBy('name_ar')->get()->map(fn (Schedule $s) => $this->scheduleRow($s, (int) ($used[$s->id] ?? 0)))->all();
    }

    /** @param  array{nameAr?:string, nameEn?:?string, timezone?:?string, hoursPerDay?:float|int|string, isDefault?:bool, active?:bool, notes?:?string, days?:array<int, array{weekday:int|string, kind?:?string, from:string, to:string, name?:?string}>}  $dto */
    public function saveSchedule(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $s = $id ? (Schedule::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('SCHEDULE_NOT_FOUND', 'جدول الدوام غير موجود', 'Schedule not found')) : null;
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            if (array_key_exists('timezone', $dto) && $dto['timezone']) {
                if (! in_array($dto['timezone'], timezone_identifiers_list(), true)) {
                    throw AppError::validation('SCHEDULE_TIMEZONE', 'المنطقة الزمنية غير معروفة', 'Unknown time zone');
                }
                $values['timezone'] = $dto['timezone'];
            }
            if (array_key_exists('hoursPerDay', $dto)) {
                $values['hours_per_day'] = round((float) $dto['hoursPerDay'], 2);
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            $days = array_key_exists('days', $dto) ? self::days((array) $dto['days']) : null;
            $own = array_intersect_key($values, array_flip(['notes'])); // what OPS may change on a schedule that is Odoo's
            if ($s && Owned::locked($s) && (array_diff_key($values, $own) || $days !== null)) {
                Owned::refuse($s, 'schedule', "جدول الدوام «{$s->name_ar}»", "Schedule '{$s->name_en}'");
            }
            if (! $s) {
                if (empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'اسم جدول الدوام مطلوب', 'The schedule needs a name');
                }
                $s = Schedule::create($values + ['name_en' => $values['name_ar'], 'timezone' => (string) config('app.business_timezone', 'Asia/Riyadh'), 'hours_per_day' => 8, 'active' => true, 'created_by' => $user->username]);
            } else {
                if (($values['active'] ?? true) === false && $s->is_default && empty($dto['isDefault'])) {
                    throw AppError::rule('SCHEDULE_IS_DEFAULT', 'هذا هو جدول الدوام الافتراضي — اجعل جدولًا آخر افتراضيًا قبل إيقافه', 'This is the default schedule — make another one the default before switching it off');
                }
                $s->update(array_filter($values, fn ($v, $k) => $v !== null || $k === 'notes', ARRAY_FILTER_USE_BOTH));
            }
            if ($days !== null) {
                ScheduleDay::where('schedule_id', $s->id)->delete(); // the stretches of a schedule are one value: replaced whole
                foreach ($days as $i => $d) {
                    ScheduleDay::create(['schedule_id' => $s->id, 'sequence' => ($i + 1) * 10] + $d);
                }
            }
            if ((float) $s->hours_per_day <= 0 || (float) $s->hours_per_day > 24) {
                throw AppError::validation('SCHEDULE_HOURS', 'ساعات اليوم بين 0 و24', 'Hours per day are between 0 and 24');
            }
            if (! empty($dto['isDefault'])) { // the default is OPS's own choice, also among Odoo's schedules
                if (! $s->active) {
                    throw AppError::rule('SCHEDULE_INACTIVE', 'الجدول الموقوف لا يكون افتراضيًا', 'An inactive schedule cannot be the default');
                }
                Schedule::where('id', '!=', $s->id)->where('is_default', true)->update(['is_default' => false]);
                $s->update(['is_default' => true]);
            }
            $this->audit->log($user, ['action' => 'PAYCFG.SCHEDULE.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'WorkSchedule', 'entityId' => $s->id, 'entityNumber' => $s->name_ar,
                'newValue' => ['fields' => array_keys($values), 'days' => $days === null ? null : count($days), 'default' => ! empty($dto['isDefault'])]]);

            return $this->scheduleRow($s->refresh()->load('days'), Profile::where('schedule_id', $s->id)->count());
        });
    }

    /** The stretches of a week as rows: validated, sorted, and no two stretches of work on one weekday overlapping. */
    private static function days(array $days): array
    {
        $out = [];
        $errors = [];
        foreach (array_values($days) as $i => $d) {
            $weekday = (int) ($d['weekday'] ?? -1);
            $from = self::minute($d['from'] ?? null);
            $to = self::minute($d['to'] ?? null);
            $kind = ($d['kind'] ?? 'work') === 'break' ? 'break' : 'work';
            if ($weekday < 0 || $weekday > 6) {
                $errors[] = ['path' => "days.{$i}.weekday", 'message' => 'اليوم من 0 (الاثنين) إلى 6 (الأحد)'];
            }
            if ($from === null || $to === null || $to <= $from) {
                $errors[] = ['path' => "days.{$i}.from", 'message' => 'الوقت بصيغة HH:MM والنهاية بعد البداية'];
            }
            $out[] = ['weekday' => $weekday, 'kind' => $kind, 'name' => Shape::text($d['name'] ?? null, 120), 'from_minute' => $from, 'to_minute' => $to];
        }
        if (! $errors) {
            usort($out, fn (array $a, array $b) => [$a['weekday'], $a['from_minute']] <=> [$b['weekday'], $b['from_minute']]);
            $work = array_values(array_filter($out, fn (array $d) => $d['kind'] === 'work'));
            foreach ($work as $i => $d) {
                if ($i > 0 && $work[$i - 1]['weekday'] === $d['weekday'] && $work[$i - 1]['to_minute'] > $d['from_minute']) {
                    $errors[] = ['path' => 'days', 'message' => 'فترتا عمل في اليوم نفسه تتقاطعان'];
                    break;
                }
            }
        }
        if ($errors) {
            throw AppError::validation('INVALID_INPUT', 'راجِع فترات جدول الدوام', 'Check the stretches of the schedule', $errors);
        }

        return $out;
    }

    /** `HH:MM` (or minutes) as minutes after midnight, 0..1440. */
    private static function minute(mixed $v): ?int
    {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (int) $v >= 0 && (int) $v <= 1440 ? (int) $v : null;
        }
        if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m) && (int) $m[2] < 60) {
            $minutes = (int) $m[1] * 60 + (int) $m[2];

            return $minutes <= 1440 ? $minutes : null;
        }

        return null;
    }

    private function scheduleRow(Schedule $s, int $employees): array
    {
        $work = $s->days->where('kind', 'work');

        return ['id' => $s->id, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'timezone' => $s->timezone, 'hoursPerDay' => (float) $s->hours_per_day, 'isDefault' => $s->is_default, 'active' => $s->active,
            'notes' => $s->notes, 'employees' => $employees, 'workingDays' => $work->pluck('weekday')->unique()->sort()->values()->all(),
            'weeklyMinutes' => (int) $work->sum(fn (ScheduleDay $d) => $d->to_minute - $d->from_minute),
            'days' => $s->days->map(fn (ScheduleDay $d) => ['id' => $d->id, 'weekday' => $d->weekday, 'kind' => $d->kind, 'name' => $d->name, 'from' => Shape::clock($d->from_minute), 'to' => Shape::clock($d->to_minute)])->values()->all(),
            'source' => $s->source_system, 'locked' => Owned::locked($s)];
    }

    // ───────────────────────────── public holidays ─────────────────────────────

    /** @param  array{year?:?string, from?:?string, to?:?string, active?:?string}  $f */
    public function holidays(Paging $page, array $f): array
    {
        $q = Holiday::query();
        if (! empty($f['year'])) {
            $q->whereDate('date_from', '<=', ((int) $f['year']).'-12-31')->whereDate('date_to', '>=', ((int) $f['year']).'-01-01');
        }
        if (! empty($f['from'])) {
            $q->whereDate('date_to', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('date_from', '<=', $f['to']);
        }
        if (($f['active'] ?? '') !== 'all') {
            $q->where('active', ($f['active'] ?? '') !== '0');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $q->orderBy('date_from', $page->sort === 'date' && $page->order === 'desc' ? 'desc' : 'asc')->orderBy('id');

        return $page->paginate($q, fn (Holiday $h) => $this->holidayRow($h));
    }

    /** @param  array{nameAr?:string, nameEn?:?string, dateFrom?:string, dateTo?:?string, scope?:?string, scopeIds?:?array, active?:bool, notes?:?string}  $dto */
    public function saveHoliday(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $h = $id ? (Holiday::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('HOLIDAY_NOT_FOUND', 'العطلة غير موجودة', 'Holiday not found')) : null;
            Owned::refuse($h, 'holiday', 'العطلة', 'The holiday');
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            foreach (['dateFrom' => 'date_from', 'dateTo' => 'date_to'] as $key => $column) {
                if (! empty($dto[$key])) {
                    $values[$column] = Carbon::parse((string) $dto[$key])->format('Y-m-d');
                }
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            if (array_key_exists('scope', $dto) || array_key_exists('scopeIds', $dto)) {
                $scope = in_array($dto['scope'] ?? $h?->scope, Holiday::SCOPES, true) ? ($dto['scope'] ?? $h->scope) : 'company';
                $ids = $scope === 'company' ? [] : array_values(array_unique(array_filter(array_map('strval', (array) ($dto['scopeIds'] ?? $h?->scope_ids ?? [])))));
                if ($scope !== 'company') {
                    $table = $scope === 'department' ? 'hr_departments' : 'hr_employees';
                    if (! $ids || DB::table($table)->whereIn('id', $ids)->count() !== count($ids)) {
                        throw AppError::validation('HOLIDAY_SCOPE', $scope === 'department' ? 'حدِّد الأقسام التي تشملها العطلة' : 'حدِّد الموظفين الذين تشملهم العطلة', 'Name the departments or employees the holiday is for');
                    }
                }
                $values += ['scope' => $scope, 'scope_ids' => $ids ?: null];
            }
            $from = $values['date_from'] ?? Shape::day($h?->date_from);
            $to = $values['date_to'] ?? ($h ? Shape::day($h->date_to) : $from);
            if (! $from || ! $to || $to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 60) {
                throw AppError::validation('HOLIDAY_DATES', 'تاريخ العطلة مطلوب، ونهايتها لا تسبق بدايتها، ولا تتجاوز 60 يومًا', 'A holiday needs its dates, cannot end before it starts and cannot be longer than 60 days');
            }
            $values['date_to'] = $to;
            if (! $h) {
                if (empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'اسم العطلة مطلوب', 'The holiday needs a name');
                }
                $h = Holiday::create($values + ['name_en' => $values['name_ar'], 'scope' => 'company', 'active' => true, 'created_by' => $user->username]);
            } else {
                $h->update(array_filter($values, fn ($v, $k) => $v !== null || in_array($k, ['notes', 'scope_ids'], true), ARRAY_FILTER_USE_BOTH));
            }
            $this->audit->log($user, ['action' => 'PAYCFG.HOLIDAY.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'PublicHoliday', 'entityId' => $h->id, 'entityNumber' => $h->name_ar,
                'newValue' => ['from' => Shape::day($h->date_from), 'to' => Shape::day($h->date_to), 'scope' => $h->scope, 'active' => $h->active]]);

            return $this->holidayRow($h->refresh());
        });
    }

    private function holidayRow(Holiday $h): array
    {
        return ['id' => $h->id, 'nameAr' => $h->name_ar, 'nameEn' => $h->name_en, 'dateFrom' => Shape::day($h->date_from), 'dateTo' => Shape::day($h->date_to),
            'days' => (int) Carbon::parse(Shape::day($h->date_from))->diffInDays(Carbon::parse(Shape::day($h->date_to))) + 1, 'scope' => $h->scope, 'scopeIds' => $h->scope_ids ?? [], 'active' => $h->active,
            'notes' => $h->notes, 'source' => $h->source_system, 'locked' => Owned::locked($h)];
    }

    // ───────────────────────────── kinds of time off ─────────────────────────────

    public function leaveTypes(bool $withInactive = false): array
    {
        return LeaveType::when(! $withInactive, fn ($q) => $q->where('active', true))->orderBy('sequence')->orderBy('name_ar')->get()->map(fn (LeaveType $t) => self::leaveTypeRow($t))->all();
    }

    public function saveLeaveType(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $t = $id ? (LeaveType::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('LEAVE_TYPE_NOT_FOUND', 'نوع الإجازة غير موجود', 'Leave type not found')) : null;
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            foreach (['paid' => 'paid', 'requiresAllocation' => 'requires_allocation', 'requiresDocument' => 'requires_document', 'allowHalfDay' => 'allow_half_day', 'allowNegative' => 'allow_negative', 'active' => 'active'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = (bool) $dto[$key];
                }
            }
            if (array_key_exists('approval', $dto)) {
                $values['approval'] = in_array($dto['approval'], LeaveType::APPROVALS, true) ? $dto['approval'] : throw AppError::validation('LEAVE_APPROVAL', 'طريقة الاعتماد غير معروفة', 'Unknown approval');
            }
            if (array_key_exists('yearlyDays', $dto)) {
                $values['yearly_days'] = $dto['yearlyDays'] === null || $dto['yearlyDays'] === '' ? null : round((float) $dto['yearlyDays'], 2);
            }
            foreach (['color' => [0, LeaveType::COLORS - 1], 'sequence' => [0, 100000]] as $key => [$min, $max]) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$key] = max($min, min($max, (int) $dto[$key]));
                }
            }
            if ($t && Owned::locked($t) && array_intersect(array_keys($values), self::LEAVE_TYPE_OWNED)) {
                Owned::refuse($t, 'leave_type', "نوع الإجازة «{$t->name_ar}»", "Leave type '{$t->name_en}'");
            }
            if (! $t) {
                $code = mb_strtoupper(trim((string) ($dto['code'] ?? '')));
                if (! preg_match('/^[A-Z0-9_.\-]{2,30}$/', $code) || empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'رمز النوع (حروف لاتينية وأرقام) واسمه مطلوبان', 'The type needs a code (letters and digits) and a name');
                }
                if (LeaveType::where('code', $code)->exists()) {
                    throw AppError::conflict('LEAVE_TYPE_EXISTS', "الرمز {$code} مستخدم", "Code {$code} is taken");
                }
                $t = LeaveType::create($values + ['code' => $code, 'name_en' => $values['name_ar'], 'approval' => 'manager', 'active' => true, 'sequence' => ((int) LeaveType::max('sequence')) + 10, 'created_by' => $user->username]);
            } else {
                $t->update(array_filter($values, fn ($v, $k) => $v !== null || in_array($k, ['notes', 'yearly_days'], true), ARRAY_FILTER_USE_BOTH));
            }
            $this->audit->log($user, ['action' => 'PAYCFG.LEAVETYPE.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'LeaveType', 'entityId' => $t->id, 'entityNumber' => $t->code, 'newValue' => $values]);

            return self::leaveTypeRow($t->refresh());
        });
    }

    public static function leaveTypeRow(LeaveType $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'nameAr' => $t->name_ar, 'nameEn' => $t->name_en, 'paid' => $t->paid, 'requiresAllocation' => $t->requires_allocation,
            'yearlyDays' => $t->yearly_days === null ? null : (float) $t->yearly_days, 'approval' => $t->approval, 'requiresDocument' => $t->requires_document, 'allowHalfDay' => $t->allow_half_day,
            'allowNegative' => $t->allow_negative, 'unit' => $t->unit, 'color' => $t->color, 'sequence' => $t->sequence, 'active' => $t->active, 'notes' => $t->notes,
            'source' => $t->source_system, 'locked' => Owned::locked($t)];
    }

    // ───────────────────────────── attendance policies ─────────────────────────────

    public function policies(): array
    {
        $used = Profile::whereNotNull('policy_id')->selectRaw('policy_id, count(*) as n')->groupBy('policy_id')->pluck('n', 'policy_id');

        return AttendancePolicy::with(['lateRule.lines', 'earlyRule.lines', 'absenceRule.lines', 'overtimeRules'])->orderByDesc('is_default')->orderByDesc('active')->orderBy('name_ar')->get()
            ->map(fn (AttendancePolicy $p) => $this->policyRow($p, (int) ($used[$p->id] ?? 0)))->all();
    }

    /**
     * @param array{nameAr?:string, nameEn?:?string, lateGraceMinutes?:int|string, earlyGraceMinutes?:int|string, isDefault?:bool, active?:bool, notes?:?string,
     *   late?:?array<int, array>, early?:?array<int, array>, absence?:?array<int, array>, overtime?:?array<int, array>} $dto
     *   late / early lines: {type: rate|fix, thresholdMinutes, rate?, amountMinutes?, weights?: [first..fifth]} · absence lines: {counter, rate} ·
     *   overtime: {dayType: workday|weekend|holiday, activeAfterMinutes, rate}
     */
    public function savePolicy(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $p = $id ? (AttendancePolicy::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('POLICY_NOT_FOUND', 'سياسة الحضور غير موجودة', 'Attendance policy not found')) : null;
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            foreach (['lateGraceMinutes' => 'late_grace_minutes', 'earlyGraceMinutes' => 'early_grace_minutes'] as $key => $column) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$column] = max(0, min(600, (int) $dto[$key]));
                }
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            $rules = array_intersect_key($dto, array_flip(['late', 'early', 'absence', 'overtime']));
            // on a policy that is Odoo's, its name, its rules and whether it is in use are Odoo's; the grace and the notes are OPS's
            if ($p && Owned::locked($p) && (array_intersect(array_keys($values), ['name_ar', 'name_en', 'active']) || $rules)) {
                Owned::refuse($p, 'policy', "سياسة الحضور «{$p->name_ar}»", "Attendance policy '{$p->name_en}'");
            }
            if (! $p) {
                if (empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'اسم السياسة مطلوب', 'The policy needs a name');
                }
                $p = AttendancePolicy::create($values + ['name_en' => $values['name_ar'], 'active' => true, 'created_by' => $user->username]);
            } else {
                if (($values['active'] ?? true) === false && $p->is_default && empty($dto['isDefault'])) {
                    throw AppError::rule('POLICY_IS_DEFAULT', 'هذه هي السياسة الافتراضية — اجعل سياسة أخرى افتراضية قبل إيقافها', 'This is the default policy — make another one the default first');
                }
                $p->update(array_filter($values, fn ($v, $k) => $v !== null || $k === 'notes', ARRAY_FILTER_USE_BOTH));
            }
            foreach (['late' => 'late_rule_id', 'early' => 'early_rule_id', 'absence' => 'absence_rule_id'] as $kind => $column) {
                if (array_key_exists($kind, $rules)) {
                    $p->update([$column => $this->rule($p, $kind, $p->getAttribute($column), (array) ($rules[$kind] ?? []), $user)]);
                }
            }
            if (array_key_exists('overtime', $rules)) {
                $this->overtime($p, (array) ($rules['overtime'] ?? []), $user);
            }
            if ($rules && $p->incomplete) {
                $p->update(['incomplete' => false]); // a person wrote its rules: it decides again
            }
            if (! empty($dto['isDefault'])) {
                if (! $p->active || $p->incomplete) {
                    throw AppError::rule('POLICY_INACTIVE', 'السياسة الموقوفة أو غير المكتملة لا تكون افتراضية', 'An inactive or incomplete policy cannot be the default');
                }
                AttendancePolicy::where('id', '!=', $p->id)->where('is_default', true)->update(['is_default' => false]);
                $p->update(['is_default' => true]);
            }
            $this->audit->log($user, ['action' => 'PAYCFG.POLICY.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'AttendancePolicy', 'entityId' => $p->id, 'entityNumber' => $p->name_ar,
                'newValue' => ['fields' => array_keys($values), 'rules' => array_keys($rules), 'default' => ! empty($dto['isDefault'])]]);

            return $this->policyRow($p->refresh()->load(['lateRule.lines', 'earlyRule.lines', 'absenceRule.lines', 'overtimeRules']), Profile::where('policy_id', $p->id)->count());
        });
    }

    /**
     * The late / early / absence rule of a policy as the lines a person sent: the policy's own rule is rewritten; a
     * rule another policy shares is left alone and this policy gets one of its own. No lines: the policy has no such rule.
     */
    private function rule(AttendancePolicy $p, string $kind, ?string $currentId, array $lines, AuthUser $user): ?string
    {
        $rows = [];
        foreach (array_values($lines) as $i => $l) {
            if ($kind === 'absence') {
                $counter = (int) ($l['counter'] ?? $i + 1);
                $rate = (float) ($l['rate'] ?? 1);
                if ($counter < 1 || $counter > 31 || $rate < 0 || $rate > 10) {
                    throw AppError::validation('POLICY_RULE', 'سطر الغياب: الترتيب من 1 والمعدل بين 0 و10', 'Absence line: a counter from 1 and a rate between 0 and 10');
                }
                $rows[] = ['sequence' => $counter * 10, 'counter' => $counter, 'rate' => $rate];

                continue;
            }
            $type = ($l['type'] ?? 'rate') === 'fix' ? 'fix' : 'rate';
            $threshold = (int) ($l['thresholdMinutes'] ?? 0);
            $rate = $type === 'rate' ? (float) ($l['rate'] ?? 1) : null;
            $amount = $type === 'fix' ? (int) ($l['amountMinutes'] ?? 0) : null;
            if ($threshold < 0 || $threshold > 1440 || ($rate !== null && ($rate < 0 || $rate > 10)) || ($amount !== null && ($amount < 0 || $amount > 1440))) {
                throw AppError::validation('POLICY_RULE', 'سطر القاعدة: الحد بالدقائق 0..1440، المعدل 0..10، المدة الثابتة 0..1440', 'Rule line: a threshold of 0..1440 minutes, a rate of 0..10, a fixed time of 0..1440');
            }
            $weights = array_pad(array_slice(array_values((array) ($l['weights'] ?? [])), 0, 5), 5, null);
            $rows[] = ['sequence' => ($i + 1) * 10, 'type' => $type, 'threshold_minutes' => $threshold, 'rate' => $rate, 'amount_minutes' => $amount]
                + array_combine(['first', 'second', 'third', 'fourth', 'fifth'], array_map(fn ($w) => $w === null || $w === '' ? null : max(0.0, min(10.0, (float) $w)), $weights));
        }
        $current = $currentId ? AttendanceRule::find($currentId) : null;
        $column = ['late' => 'late_rule_id', 'early' => 'early_rule_id', 'absence' => 'absence_rule_id'][$kind];
        $shared = $current && ($current->source_system !== null || AttendancePolicy::where($column, $current->id)->where('id', '!=', $p->id)->exists());
        if (! $rows) {
            if ($current && ! $shared) {
                $current->update(['active' => false]);
            }

            return null;
        }
        if (! $current || $shared) {
            $names = ['late' => ['قاعدة التأخير', 'Lateness rule'], 'early' => ['قاعدة الخروج المبكر', 'Early-leave rule'], 'absence' => ['قاعدة الغياب', 'Absence rule']][$kind];
            $current = AttendanceRule::create(['kind' => $kind, 'name_ar' => "{$names[0]} — {$p->name_ar}", 'name_en' => "{$names[1]} — {$p->name_en}", 'active' => true, 'created_by' => $user->username]);
        } else {
            $current->update(['active' => true]);
            AttendanceRuleLine::where('rule_id', $current->id)->delete(); // the lines of a rule are one value: replaced whole
        }
        foreach ($rows as $row) {
            AttendanceRuleLine::create(['rule_id' => $current->id] + $row);
        }

        return $current->id;
    }

    /** The overtime rules of a policy: one per kind of day at most. */
    private function overtime(AttendancePolicy $p, array $rules, AuthUser $user): void
    {
        $seen = [];
        $ids = [];
        $labels = ['workday' => ['إضافي أيام العمل', 'Overtime on working days'], 'weekend' => ['إضافي العطلة الأسبوعية', 'Overtime on the weekend'], 'holiday' => ['إضافي العطل الرسمية', 'Overtime on public holidays']];
        foreach ($rules as $r) {
            $type = (string) ($r['dayType'] ?? '');
            $rate = (float) ($r['rate'] ?? 0);
            $after = (int) ($r['activeAfterMinutes'] ?? 0);
            if (! isset($labels[$type]) || isset($seen[$type]) || $rate <= 0 || $rate > 10 || $after < 0 || $after > 1440) {
                throw AppError::validation('POLICY_RULE', 'قاعدة الإضافي: نوع اليوم (عمل / عطلة أسبوعية / عطلة رسمية) مرة واحدة، معدل بين 0 و10، وحدّ بالدقائق', 'Overtime rule: one per kind of day, a rate between 0 and 10, a threshold in minutes');
            }
            $seen[$type] = true;
            $ids[] = AttendanceRule::create(['kind' => 'overtime', 'name_ar' => "{$labels[$type][0]} — {$p->name_ar}", 'name_en' => "{$labels[$type][1]} — {$p->name_en}", 'day_type' => $type,
                'active_after_minutes' => $after, 'rate' => $rate, 'active' => true, 'created_by' => $user->username])->id;
        }
        // the rules it had: the ones nobody else uses are switched off, the links are replaced
        foreach ($p->overtimeRules()->get() as $old) {
            if ($old->source_system === null && ! DB::table('pay_policy_overtime_rules')->where('rule_id', $old->id)->where('policy_id', '!=', $p->id)->exists()) {
                $old->update(['active' => false]);
            }
        }
        $p->overtimeRules()->sync($ids);
    }

    private function policyRow(AttendancePolicy $p, int $employees): array
    {
        $lines = fn (?AttendanceRule $r) => $r && $r->active ? $r->lines->map(fn (AttendanceRuleLine $l) => $r->kind === 'absence'
            ? ['counter' => $l->counter, 'rate' => (float) $l->rate]
            : ['type' => $l->type, 'thresholdMinutes' => $l->threshold_minutes, 'rate' => $l->rate === null ? null : (float) $l->rate, 'amountMinutes' => $l->amount_minutes,
                'weights' => array_map(fn ($w) => $w === null ? null : (float) $w, [$l->first, $l->second, $l->third, $l->fourth, $l->fifth])])->values()->all() : [];

        return ['id' => $p->id, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'lateGraceMinutes' => $p->late_grace_minutes, 'earlyGraceMinutes' => $p->early_grace_minutes, 'isDefault' => $p->is_default,
            'incomplete' => $p->incomplete, 'active' => $p->active, 'notes' => $p->notes, 'employees' => $employees,
            'late' => $lines($p->lateRule), 'early' => $lines($p->earlyRule), 'absence' => $lines($p->absenceRule),
            'overtime' => $p->overtimeRules->where('active', true)->map(fn (AttendanceRule $r) => ['dayType' => $r->day_type, 'activeAfterMinutes' => (int) $r->active_after_minutes, 'rate' => (float) $r->rate])->values()->all(),
            'source' => $p->source_system, 'locked' => Owned::locked($p)];
    }
}
