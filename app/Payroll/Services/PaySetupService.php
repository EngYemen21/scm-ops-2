<?php

namespace App\Payroll\Services;

use App\Finance\Models\Account;
use App\Finance\Services\LedgerService;
use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\Component;
use App\Payroll\Models\GosiRate;
use App\Payroll\Models\Parameter;
use App\Payroll\Models\PayAccount;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Profile;
use App\Payroll\Models\Run;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\Structure;
use App\Payroll\Models\StructureRule;
use App\Payroll\PayrollBootstrap;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What pay is made of: pay components, pay structures, social-insurance rates, the engine's parameters, the posting
 * map, and how each employee is paid. Changing any of it needs `payroll.manage`.
 *
 * RATES HAVE DATES. A social-insurance rate or a parameter is a row with the date it applies from; a change is a new
 * row. A row that an approved payslip was computed under is history: it is not edited or removed (`RATE_IN_USE`), and
 * no row may be slipped in behind an approved month (`RATE_RETROACTIVE`) — an approved run must stay what a
 * recomputation of its inputs would say.
 */
class PaySetupService
{
    /** What Odoo holds of a structure; the scheme, the default flag and the notes are OPS's own. */
    private const STRUCTURE_OWNED = ['code', 'name_ar', 'name_en', 'active'];

    public function __construct(private readonly AuditService $audit, private readonly Workforce $workforce) {}

    // ───────────────────────────── pay components ─────────────────────────────

    public function components(bool $withInactive = true): array
    {
        return Component::when(! $withInactive, fn ($q) => $q->where('active', true))->orderBy('sequence')->orderBy('code')->get()->map(fn (Component $c) => self::componentRow($c))->all();
    }

    public function saveComponent(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $c = $id ? (Component::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('COMPONENT_NOT_FOUND', 'بند الراتب غير موجود', 'Pay component not found')) : null;
            Owned::refuse($c, 'component', "بند الراتب «{$c?->name_ar}»", "Pay component '{$c?->name_en}'");
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
                $values['sequence'] = max(1, min(899, (int) $dto['sequence']));
            }
            if (array_key_exists('accountKey', $dto)) {
                $values['account_key'] = $dto['accountKey'] ? (isset(RunPosting::KEYS[$dto['accountKey']]) ? $dto['accountKey'] : throw AppError::validation('ACCOUNT_KEY', 'بند التوجيه المحاسبي غير معروف', 'Unknown posting key')) : null;
            }
            if (array_key_exists('accountCode', $dto)) {
                $values['account_code'] = $dto['accountCode'] ? $this->account((string) $dto['accountCode'], null)->code : null;
            }
            if (array_key_exists('gosiBase', $dto)) {
                $values['gosi_base'] = (bool) $dto['gosiBase'];
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            if (! $c) {
                $code = mb_strtoupper(trim((string) ($dto['code'] ?? '')));
                $category = (string) ($dto['category'] ?? '');
                if (! preg_match('/^[A-Z0-9_.\-]{2,40}$/', $code) || empty($values['name_ar']) || ! in_array($category, Component::INPUT_CATEGORIES, true)) {
                    throw AppError::validation('INVALID_INPUT', 'بند جديد: رمز (حروف لاتينية وأرقام)، اسم، ونوعه إضافة أو خصم', 'A new component needs a code (letters and digits), a name, and whether it adds or deducts');
                }
                if (in_array($code, ['GROSS', 'NET'], true) || Component::where('code', $code)->exists()) {
                    throw AppError::conflict('COMPONENT_EXISTS', "الرمز {$code} مستخدم", "Code {$code} is taken");
                }
                // what a person adds is always an input of a month (an adjustment): the engine's own components are fixed
                $c = Component::create($values + ['code' => $code, 'name_en' => $values['name_ar'], 'category' => $category, 'calc' => 'input', 'gosi_base' => false,
                    'sequence' => $category === 'deduction' ? 185 : 65, 'account_key' => $category === 'deduction' ? 'deductions' : 'allowances', 'system' => false, 'active' => true, 'created_by' => $user->username]);
            } else {
                if (array_key_exists('gosi_base', $values) && $c->calc !== 'contract') {
                    throw AppError::rule('COMPONENT_GOSI_BASE', 'وعاء التأمينات يتكوّن من مبالغ العقد فقط (الأساسي والبدلات الثابتة)', 'Only contract amounts (basic and fixed allowances) can be in the social-insurance base');
                }
                if (($values['active'] ?? true) === false && $c->code === 'BASIC') {
                    throw AppError::rule('COMPONENT_REQUIRED', 'الراتب الأساسي لا يُوقَف', 'The basic salary cannot be switched off');
                }
                $c->update(array_filter($values, fn ($v, $k) => $v !== null || in_array($k, ['notes', 'account_key', 'account_code'], true), ARRAY_FILTER_USE_BOTH));
            }
            $this->audit->log($user, ['action' => 'PAYCFG.COMPONENT.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'PayComponent', 'entityId' => $c->id, 'entityNumber' => $c->code, 'newValue' => $values]);

            return self::componentRow($c->refresh());
        });
    }

    public static function componentRow(Component $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'category' => $c->category, 'calc' => $c->calc, 'contractField' => $c->contract_field,
            'gosiBase' => $c->gosi_base, 'sequence' => $c->sequence, 'accountKey' => $c->account_key, 'accountCode' => $c->account_code, 'system' => $c->system, 'active' => $c->active, 'notes' => $c->notes,
            'input' => $c->calc === 'input', 'source' => $c->source_system, 'locked' => Owned::locked($c)];
    }

    // ───────────────────────────── pay structures ─────────────────────────────

    public function structures(): array
    {
        $used = Profile::whereNotNull('structure_id')->selectRaw('structure_id, count(*) as n')->groupBy('structure_id')->pluck('n', 'structure_id');
        $rules = StructureRule::selectRaw('structure_id, count(*) as n')->groupBy('structure_id')->pluck('n', 'structure_id');

        return Structure::orderByDesc('is_default')->orderByDesc('active')->orderBy('name_ar')->get()->map(fn (Structure $s) => $this->structureRow($s, (int) ($used[$s->id] ?? 0), (int) ($rules[$s->id] ?? 0)))->all();
    }

    public function structure(string $id): array
    {
        $s = Structure::with('rules.component')->find($id) ?? throw AppError::notFound('STRUCTURE_NOT_FOUND', 'هيكل الراتب غير موجود', 'Pay structure not found');

        return $this->structureRow($s, Profile::where('structure_id', $s->id)->count(), $s->rules->count()) + [
            'rules' => $s->rules->map(fn (StructureRule $r) => ['id' => $r->id, 'sequence' => $r->sequence, 'code' => $r->code, 'name' => $r->name, 'categoryCode' => $r->category_code, 'condition' => $r->condition,
                'conditionText' => $r->condition_text, 'formula' => $r->formula, 'quantity' => $r->quantity, 'appearsOnPayslip' => $r->appears_on_payslip, 'active' => $r->active,
                'component' => Shape::named($r->component), 'source' => $r->source_system])->all(),
        ];
    }

    public function saveStructure(AuthUser $user, ?string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $s = $id ? (Structure::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('STRUCTURE_NOT_FOUND', 'هيكل الراتب غير موجود', 'Pay structure not found')) : null;
            $values = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'notes' => 'notes'] as $key => $column) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = Shape::text($dto[$key], $column === 'notes' ? 2000 : 190);
                }
            }
            if (array_key_exists('gosiScheme', $dto)) {
                $scheme = Shape::text($dto['gosiScheme'], 20);
                if ($scheme !== null && ! GosiRate::where('scheme', $scheme)->exists()) {
                    throw AppError::validation('GOSI_SCHEME', "لا نسب تأمينات مُعرَّفة للنظام «{$scheme}» — أضِف نسبه أولًا", "No social-insurance rates exist for scheme '{$scheme}' — add its rates first");
                }
                $values['gosi_scheme'] = $scheme === null ? null : mb_strtolower($scheme);
            }
            if (array_key_exists('active', $dto)) {
                $values['active'] = (bool) $dto['active'];
            }
            if ($s && Owned::locked($s) && array_intersect(array_keys($values), self::STRUCTURE_OWNED)) {
                Owned::refuse($s, 'structure', "هيكل الراتب «{$s->name_ar}»", "Pay structure '{$s->name_en}'");
            }
            if (! $s) {
                $code = mb_strtoupper(trim((string) ($dto['code'] ?? '')));
                if (! preg_match('/^[A-Z0-9_.\-]{2,40}$/', $code) || empty($values['name_ar'])) {
                    throw AppError::validation('INVALID_INPUT', 'رمز الهيكل (حروف لاتينية وأرقام) واسمه مطلوبان', 'The structure needs a code (letters and digits) and a name');
                }
                if (Structure::where('code', $code)->exists()) {
                    throw AppError::conflict('STRUCTURE_EXISTS', "الرمز {$code} مستخدم", "Code {$code} is taken");
                }
                $s = Structure::create($values + ['code' => $code, 'name_en' => $values['name_ar'], 'active' => true, 'created_by' => $user->username]);
            } else {
                if (($values['active'] ?? true) === false && $s->is_default && empty($dto['isDefault'])) {
                    throw AppError::rule('STRUCTURE_IS_DEFAULT', 'هذا هو الهيكل الافتراضي — اجعل هيكلًا آخر افتراضيًا قبل إيقافه', 'This is the default structure — make another one the default first');
                }
                $s->update(array_filter($values, fn ($v, $k) => $v !== null || in_array($k, ['notes', 'gosi_scheme'], true), ARRAY_FILTER_USE_BOTH));
            }
            if (! empty($dto['isDefault'])) {
                if (! $s->active) {
                    throw AppError::rule('STRUCTURE_INACTIVE', 'الهيكل الموقوف لا يكون افتراضيًا', 'An inactive structure cannot be the default');
                }
                Structure::where('id', '!=', $s->id)->where('is_default', true)->update(['is_default' => false]);
                $s->update(['is_default' => true]);
            }
            $this->audit->log($user, ['action' => 'PAYCFG.STRUCTURE.'.($id ? 'UPDATE' : 'CREATE'), 'entityType' => 'PayStructure', 'entityId' => $s->id, 'entityNumber' => $s->code ?? $s->name_ar, 'newValue' => $values + ['default' => ! empty($dto['isDefault'])]]);

            return $this->structure($s->id);
        });
    }

    private function structureRow(Structure $s, int $employees, int $rules): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'gosiScheme' => $s->gosi_scheme, 'typeLabel' => $s->type_label, 'country' => $s->country,
            'isDefault' => $s->is_default, 'active' => $s->active, 'notes' => $s->notes, 'employees' => $employees, 'ruleCount' => $rules, 'source' => $s->source_system, 'locked' => Owned::locked($s)];
    }

    // ───────────────────────────── social-insurance rates ─────────────────────────────

    public function gosiRates(): array
    {
        $today = Carbon::today()->toDateString();
        $rows = GosiRate::orderBy('scheme')->orderByDesc('effective_from')->get();
        $current = [];
        foreach ($rows as $r) {
            if (! isset($current[$r->scheme]) && Shape::day($r->effective_from) <= $today) {
                $current[$r->scheme] = $r->id;
            }
        }
        $structures = Structure::whereNotNull('gosi_scheme')->where('active', true)->selectRaw('gosi_scheme, count(*) as n')->groupBy('gosi_scheme')->pluck('n', 'gosi_scheme');

        return ['schemes' => $rows->groupBy('scheme')->map(fn ($group, $scheme) => ['scheme' => $scheme, 'structures' => (int) ($structures[$scheme] ?? 0),
            'rates' => $group->map(fn (GosiRate $r) => self::gosiRow($r) + ['current' => ($current[$r->scheme] ?? null) === $r->id, 'inUse' => $this->gosiInUse($r)])->values()->all()])->values()->all()];
    }

    /** @param  array{scheme:string, effectiveFrom:string, employeePct:mixed, employerPct:mixed, baseMin?:mixed, baseMax?:mixed, note?:?string}  $dto */
    public function createGosiRate(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $scheme = mb_strtolower(trim((string) $dto['scheme']));
            if (! preg_match('/^[a-z0-9_\-]{2,20}$/', $scheme)) {
                throw AppError::validation('GOSI_SCHEME', 'اسم النظام حروف لاتينية صغيرة وأرقام (مثل saudi)', 'A scheme is named with lower-case letters and digits (e.g. saudi)');
            }
            $values = self::gosiValues($dto) + ['scheme' => $scheme, 'effective_from' => Carbon::parse((string) $dto['effectiveFrom'])->format('Y-m-d')];
            if (GosiRate::where('scheme', $scheme)->whereDate('effective_from', $values['effective_from'])->exists()) {
                throw AppError::conflict('RATE_EXISTS', "للنظام «{$scheme}» نسبة بتاريخ {$values['effective_from']} بالفعل", "Scheme '{$scheme}' already has a rate from {$values['effective_from']}");
            }
            $this->assertNotBehind($values['effective_from'], $scheme);
            $r = GosiRate::create($values + ['created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PAYCFG.GOSI.CREATE', 'entityType' => 'GosiRate', 'entityId' => $r->id, 'entityNumber' => "{$scheme}@{$values['effective_from']}", 'newValue' => $values]);

            return self::gosiRow($r->refresh());
        });
    }

    public function updateGosiRate(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $r = GosiRate::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('RATE_NOT_FOUND', 'النسبة غير موجودة', 'Rate not found');
            $values = self::gosiValues($dto + ['employeePct' => $r->employee_pct, 'employerPct' => $r->employer_pct, 'baseMin' => $r->base_min, 'baseMax' => $r->base_max, 'note' => $r->note]);
            $changed = false;
            foreach (['employee_pct', 'employer_pct', 'base_min', 'base_max'] as $column) {
                [$old, $new] = [$r->getAttribute($column), $values[$column]];
                $changed = $changed || ($old === null) !== ($new === null) || ($old !== null && abs((float) $old - (float) $new) > 0.00001);
            }
            if ($changed && $this->gosiInUse($r)) { // the note of a rate may always be completed; its figures are history once pay was approved under them
                throw self::inUse();
            }
            $r->update($values);
            $this->audit->log($user, ['action' => 'PAYCFG.GOSI.UPDATE', 'entityType' => 'GosiRate', 'entityId' => $r->id, 'entityNumber' => "{$r->scheme}@".Shape::day($r->effective_from), 'newValue' => $values]);

            return self::gosiRow($r->refresh());
        });
    }

    /** A rate nobody was paid under yet may be taken out again (a typing slip); one that was is history. */
    public function removeGosiRate(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $r = GosiRate::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('RATE_NOT_FOUND', 'النسبة غير موجودة', 'Rate not found');
            if ($this->gosiInUse($r)) {
                throw self::inUse();
            }
            if (GosiRate::where('scheme', $r->scheme)->count() === 1 && Structure::where('gosi_scheme', $r->scheme)->exists()) {
                throw AppError::rule('RATE_LAST', "هذه آخر نسبة للنظام «{$r->scheme}» وهياكل رواتب تعمل به — غيِّر نظام تلك الهياكل أولًا", "This is the last rate of scheme '{$r->scheme}' and pay structures use it — change those structures first");
            }
            $r->delete();
            $this->audit->log($user, ['action' => 'PAYCFG.GOSI.REMOVE', 'entityType' => 'GosiRate', 'entityId' => $id, 'entityNumber' => "{$r->scheme}@".Shape::day($r->effective_from)]);

            return ['ok' => true];
        });
    }

    private static function gosiValues(array $dto): array
    {
        $employee = round((float) $dto['employeePct'], 4);
        $employer = round((float) $dto['employerPct'], 4);
        $min = round((float) ($dto['baseMin'] ?? 0), 2);
        $max = ($dto['baseMax'] ?? null) === null || $dto['baseMax'] === '' ? null : round((float) $dto['baseMax'], 2);
        if ($employee < 0 || $employee > 100 || $employer < 0 || $employer > 100 || $min < 0 || ($max !== null && $max < $min)) {
            throw AppError::validation('GOSI_RATE', 'النسب بين 0 و100، والحد الأدنى للوعاء لا يتجاوز حدّه الأعلى', 'Percentages are between 0 and 100, and the floor of the base cannot exceed its ceiling');
        }

        return ['employee_pct' => $employee, 'employer_pct' => $employer, 'base_min' => $min, 'base_max' => $max, 'note' => Shape::text($dto['note'] ?? null, 500)];
    }

    /** An approved payslip of the scheme lies on or after the month the rate starts in. */
    private function gosiInUse(GosiRate $r): bool
    {
        return $this->behindApproved(Shape::day($r->effective_from), $r->scheme);
    }

    private static function gosiRow(GosiRate $r): array
    {
        return ['id' => $r->id, 'scheme' => $r->scheme, 'effectiveFrom' => Shape::day($r->effective_from), 'employeePct' => (float) $r->employee_pct, 'employerPct' => (float) $r->employer_pct,
            'baseMin' => $r->base_min, 'baseMax' => $r->base_max, 'note' => $r->note, 'createdBy' => $r->created_by];
    }

    // ───────────────────────────── parameters ─────────────────────────────

    public function parameters(): array
    {
        $today = Carbon::today()->toDateString();
        $rows = Parameter::orderBy('code')->orderByDesc('effective_from')->get()->groupBy('code');
        $out = [];
        foreach (array_unique(array_merge(array_keys(Rates::KNOWN), $rows->keys()->all())) as $code) {
            $known = Rates::KNOWN[$code] ?? null;
            $history = ($rows[$code] ?? collect())->values();
            $current = $history->first(fn (Parameter $p) => Shape::day($p->effective_from) <= $today);
            $out[] = ['code' => $code, 'known' => $known !== null, 'kind' => $known[0] ?? 'text', 'nameAr' => $known[1] ?? ($history->first()?->name ?? $code), 'nameEn' => $known[2] ?? ($history->first()?->name ?? $code),
                'options' => $known[3] ?? null, 'current' => $current?->value,
                'history' => $history->map(fn (Parameter $p) => ['id' => $p->id, 'value' => $p->value, 'effectiveFrom' => Shape::day($p->effective_from), 'note' => $p->note, 'current' => $current?->id === $p->id,
                    'inUse' => $known !== null && $this->behindApproved(Shape::day($p->effective_from)), 'source' => $p->source_system])->all()];
        }

        return $out;
    }

    /** @param  array{code:string, value:mixed, effectiveFrom:string, note?:?string}  $dto */
    public function createParameter(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $code = trim((string) $dto['code']);
            $known = Rates::KNOWN[$code] ?? throw AppError::validation('PARAMETER_UNKNOWN', "المعامل «{$code}» ليس من معاملات المحرّك", "Parameter '{$code}' is not one the engine reads");
            $value = trim((string) $dto['value']);
            if (($known[0] === 'number' && (! is_numeric($value) || (float) $value <= 0 || (float) $value > 366)) || ($known[0] === 'choice' && ! in_array($value, $known[3], true))) {
                throw AppError::validation('PARAMETER_VALUE', $known[0] === 'number' ? 'القيمة رقم أكبر من صفر' : 'القيمة واحدة من: '.implode('، ', $known[3]), $known[0] === 'number' ? 'The value is a positive number' : 'The value is one of: '.implode(', ', $known[3]));
            }
            $from = Carbon::parse((string) $dto['effectiveFrom'])->format('Y-m-d');
            if (Parameter::where('code', $code)->whereDate('effective_from', $from)->exists()) {
                throw AppError::conflict('RATE_EXISTS', "للمعامل «{$code}» قيمة بتاريخ {$from} بالفعل", "Parameter '{$code}' already has a value from {$from}");
            }
            $this->assertNotBehind($from, null);
            $p = Parameter::create(['code' => $code, 'name' => $known[2], 'value' => $value, 'effective_from' => $from, 'note' => Shape::text($dto['note'] ?? null, 500), 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'PAYCFG.PARAMETER.CREATE', 'entityType' => 'PayParameter', 'entityId' => $p->id, 'entityNumber' => "{$code}@{$from}", 'newValue' => ['value' => $value]]);

            return $this->parameters();
        });
    }

    public function removeParameter(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $p = Parameter::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('RATE_NOT_FOUND', 'القيمة غير موجودة', 'Value not found');
            Owned::refuse($p, 'parameter', 'هذا المعامل', 'This parameter');
            $from = Shape::day($p->effective_from);
            if (isset(Rates::KNOWN[$p->code])) {
                if ($this->behindApproved($from)) {
                    throw self::inUse();
                }
                if (Parameter::where('code', $p->code)->count() === 1) {
                    throw AppError::rule('RATE_LAST', "هذه القيمة الوحيدة للمعامل «{$p->code}» والمحرّك لا يحسب بدونها — أضِف قيمة جديدة بتاريخها بدل الحذف", "This is the only value of '{$p->code}' and the engine cannot compute without one — add a new dated value instead");
                }
            }
            $p->delete();
            $this->audit->log($user, ['action' => 'PAYCFG.PARAMETER.REMOVE', 'entityType' => 'PayParameter', 'entityId' => $id, 'entityNumber' => "{$p->code}@{$from}"]);

            return $this->parameters();
        });
    }

    /** An approved payslip exists on or after the month a date falls in (of one scheme's structures, or of anyone). */
    private function behindApproved(string $from, ?string $scheme = null): bool
    {
        return Payslip::whereIn('state', Run::FINAL)->whereNull('source_system')->where('period', '>=', substr($from, 0, 7))
            ->when($scheme, fn ($q) => $q->whereIn('structure_id', Structure::where('gosi_scheme', $scheme)->select('id')))->exists();
    }

    private function assertNotBehind(string $from, ?string $scheme): void
    {
        if ($this->behindApproved($from, $scheme)) {
            throw AppError::rule('RATE_RETROACTIVE', "رواتب معتمدة تقع في {$from} أو بعده — قيمة جديدة تسري من شهر لم يُعتمد راتبه بعد", "Approved pay exists on or after {$from} — a new value applies from a month whose pay is not approved yet", ['from' => $from]);
        }
    }

    private static function inUse(): AppError
    {
        return AppError::rule('RATE_IN_USE', 'رواتب معتمدة حُسبت بهذه القيمة — لا تُعدَّل ولا تُحذف؛ أضِف قيمة جديدة بتاريخ سريانها', 'Approved pay was computed with this value — it is not edited or removed; add a new value with its own date');
    }

    // ───────────────────────────── the posting map ─────────────────────────────

    public function accounts(): array
    {
        PayrollBootstrap::accounts(); // a key added by a later release appears with its obvious account, or empty
        $map = PayAccount::pluck('account_code', 'key');
        $accounts = Account::whereIn('code', $map->filter()->values()->all() ?: ['-'])->get(['code', 'name_ar', 'name_en', 'type', 'is_postable', 'active'])->keyBy('code');
        $out = [];
        foreach (RunPosting::KEYS as $key => [$side, $ar, $en]) {
            $a = ($code = $map[$key] ?? null) ? ($accounts[$code] ?? null) : null;
            $out[] = ['key' => $key, 'side' => $side, 'nameAr' => $ar, 'nameEn' => $en, 'accountCode' => $code,
                'account' => $a ? ['code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en, 'type' => $a->type] : null, 'ready' => (bool) ($a && $a->is_postable && $a->active)];
        }

        return $out;
    }

    /** @param  array<string, ?string>  $accounts  key → account code (null clears it) */
    public function saveAccounts(AuthUser $user, array $accounts): array
    {
        return DB::transaction(function () use ($user, $accounts) {
            $changed = [];
            foreach ($accounts as $key => $code) {
                if (! isset(RunPosting::KEYS[$key])) {
                    throw AppError::validation('ACCOUNT_KEY', "بند التوجيه «{$key}» غير معروف", "Unknown posting key '{$key}'");
                }
                $code = $code === null || $code === '' ? null : $this->account((string) $code, RunPosting::KEYS[$key][0])->code;
                PayAccount::updateOrCreate(['key' => $key], ['account_code' => $code, 'updated_by' => $user->username]);
                $changed[$key] = $code;
            }
            $this->audit->log($user, ['action' => 'PAYCFG.ACCOUNTS', 'entityType' => 'PayAccounts', 'newValue' => $changed]);

            return $this->accounts();
        });
    }

    /** An account payroll may post to: there, postable, active, not a customer / supplier control account, on the right side. */
    private function account(string $code, ?string $side): Account
    {
        $a = Account::where('code', $code)->first() ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', "الحساب {$code} غير موجود في دليل الحسابات", "Account {$code} is not in the chart");
        if (! $a->is_postable || ! $a->active) {
            throw AppError::rule('ACCOUNT_NOT_POSTABLE', "الحساب {$a->code} — {$a->name_ar} لا يقبل الترحيل", "Account {$a->code} is not postable");
        }
        if (isset(LedgerService::CONTROL_SUBTYPES[$a->subtype])) {
            throw AppError::rule('ACCOUNT_IS_CONTROL', "الحساب {$a->code} حساب تحكم للعملاء أو الموردين — لا تُرحَّل عليه الرواتب", "Account {$a->code} is a customer / supplier control account — payroll cannot post to it");
        }
        if ($side === 'expense' && $a->type !== 'expense') {
            throw AppError::rule('ACCOUNT_SIDE', "الحساب {$a->code} ليس حساب مصروف", "Account {$a->code} is not an expense account");
        }
        if ($side === 'liability' && $a->type === 'expense') {
            throw AppError::rule('ACCOUNT_SIDE', "الحساب {$a->code} حساب مصروف — هذا البند يحتاج حساب التزام (أو أصل)", "Account {$a->code} is an expense account — this key needs a liability (or asset) account");
        }

        return $a;
    }

    // ───────────────────────────── how each employee is paid ─────────────────────────────

    /** @param  array{department?:?string, structure?:?string, schedule?:?string, policy?:?string, method?:?string, status?:?string}  $f */
    public function profiles(AuthUser $user, Paging $page, array $f): array
    {
        Access::needPay($user);
        $q = Employee::query()->when(($f['status'] ?? '') !== 'all', fn ($x) => $x->where('status', 'active'));
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        foreach (['structure' => 'structure_id', 'schedule' => 'schedule_id', 'policy' => 'policy_id', 'method' => 'payment_method'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->whereIn('id', Profile::where($column, $f[$key])->select('employee_id'));
            }
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
        }
        $q->with('department')->orderBy($page->sort === 'code' ? 'code' : 'name_ar', $page->sort ? $page->order : 'asc')->orderBy('id');
        $total = (clone $q)->toBase()->getCountForPagination();
        $employees = $q->forPage($page->page, $page->pageSize)->get();
        $profiles = Profile::whereIn('employee_id', $employees->pluck('id')->all() ?: ['-'])->with(['structure', 'schedule', 'policy'])->get()->keyBy('employee_id');
        $defaults = $this->defaults();

        return $page->wrap($employees->map(fn (Employee $e) => $this->profileRow($e, $profiles[$e->id] ?? null, $defaults))->all(), $total) + ['defaults' => $defaults];
    }

    /** @param  array{structureId?:?string, scheduleId?:?string, policyId?:?string, paymentMethod?:?string, notes?:?string}  $dto */
    public function saveProfile(AuthUser $user, string $employeeIdOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $employeeIdOrCode, $dto) {
            $e = $this->workforce->employee($employeeIdOrCode) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $p = Profile::where('employee_id', $e->id)->lockForUpdate()->first();
            $values = [];
            foreach (['structureId' => ['structure_id', Structure::class], 'scheduleId' => ['schedule_id', Schedule::class], 'policyId' => ['policy_id', AttendancePolicy::class]] as $key => [$column, $class]) {
                if (array_key_exists($key, $dto)) {
                    $values[$column] = $dto[$key] ? ($class::whereKey($dto[$key])->where('active', true)->value('id') ?? throw AppError::notFound('PROFILE_REFERENCE', 'الهيكل أو الجدول أو السياسة غير موجود أو موقوف', 'The structure, schedule or policy does not exist or is inactive')) : null;
                }
            }
            if (array_key_exists('paymentMethod', $dto) && $dto['paymentMethod']) {
                $values['payment_method'] = in_array($dto['paymentMethod'], Profile::PAYMENT_METHODS, true) ? $dto['paymentMethod'] : throw AppError::validation('PAYMENT_METHOD', 'طريقة الصرف: bank أو cash', 'Payment method is bank or cash');
            }
            if (array_key_exists('notes', $dto)) {
                $values['notes'] = Shape::text($dto['notes'], 2000);
            }
            // on a profile Odoo filled from the contract, the structure, the schedule and the policy are Odoo's; how the pay is handed over is OPS's
            if ($p && Owned::locked($p) && array_intersect(array_keys($values), ['structure_id', 'schedule_id', 'policy_id'])) {
                Owned::refuse($p, 'pay_profile', "ملف راتب الموظف {$e->code}", "The pay profile of {$e->code}");
            }
            $p ? $p->update($values) : ($p = Profile::create($values + ['employee_id' => $e->id, 'payment_method' => 'bank', 'created_by' => $user->username]));
            $this->audit->log($user, ['action' => 'PAYCFG.PROFILE', 'entityType' => 'PayProfile', 'entityId' => $p->id, 'entityNumber' => $e->code, 'newValue' => ['fields' => array_keys($values)]]);

            return $this->profileRow($e->load('department'), $p->refresh()->load(['structure', 'schedule', 'policy']), $this->defaults());
        });
    }

    private function defaults(): array
    {
        return ['structure' => Shape::named(Structure::where('active', true)->where('is_default', true)->first()),
            'schedule' => Shape::named(Schedule::where('active', true)->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->first()),
            'policy' => Shape::named(AttendancePolicy::where('active', true)->where('is_default', true)->orderBy('created_at')->first())];
    }

    private function profileRow(Employee $e, ?Profile $p, array $defaults): array
    {
        return ['employee' => Shape::employee($e), 'department' => Shape::department($e->department), 'profileId' => $p?->id,
            'structure' => Shape::named($p?->structure, ['gosiScheme' => 'gosi_scheme']), 'schedule' => Shape::named($p?->schedule), 'policy' => Shape::named($p?->policy, ['incomplete' => 'incomplete']),
            'paymentMethod' => $p?->payment_method ?? 'bank', 'notes' => $p?->notes, 'usesDefaults' => ['structure' => ! $p?->structure_id, 'schedule' => ! $p?->schedule_id, 'policy' => ! $p?->policy_id || (bool) $p?->policy?->incomplete],
            'source' => $p?->source_system, 'locked' => $p ? Owned::locked($p) : false];
    }
}
