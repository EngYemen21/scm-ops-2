<?php

namespace App\Approvals\Services;

use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalCategoryApprover;
use App\Approvals\Models\ApprovalRequest;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Categories of approval requests: the kinds of request the company uses, each with its approvers (order, who is a
 * must, from which amount each is asked), the number of approvals it needs and the fields a request must carry.
 *
 * A category that came from Odoo is Odoo's while the approvals area still follows Odoo: OPS shows it, lets people
 * raise OPS requests with it, and refuses to change it (APPROVAL_CATEGORY_OWNED_BY_SOURCE).
 */
class CategoriesService
{
    /** Numbering key of the codes given to categories nobody named: AC-001, AC-002 … */
    private const SEQUENCE = 'APC';

    public function __construct(private readonly People $people, private readonly AuditService $audit, private readonly NumberingService $numbering) {}

    /** Does Odoo own the records of this area that came from it? */
    public static function odooOwns(): bool
    {
        return OdooProjection::owns('approvals');
    }

    public function owned(ApprovalCategory $c): bool
    {
        return $c->source_system === 'odoo' && self::odooOwns();
    }

    public function find(string $idOrCode): ApprovalCategory
    {
        $key = trim($idOrCode);

        return ApprovalCategory::where('id', $key)->orWhere('code', $key)->first()
            ?? throw AppError::notFound('APPROVAL_CATEGORY_NOT_FOUND', "نوع الطلب {$key} غير موجود", 'Approval category not found');
    }

    /** @param  array{active?:?string, source?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = ApprovalCategory::query()->with('approvers');
        $active = (string) ($f['active'] ?? '');
        if ($active === '1' || $active === 'true') {
            $q->where('active', true);
        } elseif ($active === '0' || $active === 'false') {
            $q->where('active', false);
        }
        if (($f['source'] ?? '') === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? '') === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '%_\\').'%';
            $q->where(fn ($w) => $w->where('name_ar', Sql::like(), $like)->orWhere('name_en', Sql::like(), $like)->orWhere('code', Sql::like(), $like)->orWhere('description', Sql::like(), $like));
        }
        $column = match ($page->sort) {
            'code' => 'code', 'name' => 'name_ar', 'createdAt' => 'created_at', 'minApprovals' => 'min_approvals', default => null,
        };
        // the order people set (active first, then `sort`) unless a column was asked for
        $column ? $q->orderBy($column, $page->order) : $q->orderByDesc('active')->orderBy('sort')->orderBy('name_ar');
        $out = $page->paginate($q->orderBy('id'), fn (ApprovalCategory $c) => $c);
        $counts = $this->counts(array_map(fn (ApprovalCategory $c) => $c->id, $out['items']));
        $this->people->load(collect($out['items'])->flatMap(fn (ApprovalCategory $c) => $c->approvers->pluck('user_id'))->all());
        $out['items'] = array_map(fn (ApprovalCategory $c) => $this->present($c) + ['counts' => $counts[$c->id] ?? ['requests' => 0, 'pending' => 0]], $out['items']);

        return $out;
    }

    public function get(string $idOrCode): array
    {
        $c = $this->find($idOrCode)->load('approvers');

        return $this->present($c) + ['counts' => $this->counts([$c->id])[$c->id] ?? ['requests' => 0, 'pending' => 0]];
    }

    /** Active categories for the "new request" form, in the order people set. */
    public function active(): array
    {
        $rows = ApprovalCategory::with('approvers')->where('active', true)->orderBy('sort')->orderBy('name_ar')->orderBy('id')->get();
        $this->people->load($rows->flatMap(fn (ApprovalCategory $c) => $c->approvers->pluck('user_id'))->all());

        return $rows->map(fn (ApprovalCategory $c) => $this->present($c))->all();
    }

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $values = $this->values($dto, null);
            $approvers = $this->approverRows($dto['approvers'] ?? []);
            $this->checkMinimum($values['min_approvals'], count($approvers));
            $code = $this->code($dto['code'] ?? null, null);
            $c = ApprovalCategory::create($values + ['code' => $code, 'created_by' => $user->username]);
            foreach ($approvers as $row) {
                ApprovalCategoryApprover::create($row + ['category_id' => $c->id]);
            }
            $this->audit->log($user, ['action' => 'APPROVAL_CATEGORY.CREATE', 'entityType' => 'ApprovalCategory', 'entityId' => $c->id, 'entityNumber' => $c->code,
                'newValue' => $this->snapshot($c->load('approvers'))]);

            return $this->get($c->id);
        });
    }

    public function update(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $c = ApprovalCategory::where(fn ($w) => $w->where('id', $idOrCode)->orWhere('code', $idOrCode))->lockForUpdate()->first()
                ?? throw AppError::notFound('APPROVAL_CATEGORY_NOT_FOUND', "نوع الطلب {$idOrCode} غير موجود", 'Approval category not found');
            if ($this->owned($c)) {
                throw AppError::rule('APPROVAL_CATEGORY_OWNED_BY_SOURCE',
                    "نوع الطلب «{$c->name_ar}» مصدره أودو وما زال يُدار هناك — عدّله في أودو فيصل التعديل تلقائيًا. الأنواع المنشأة هنا تُعدَّل هنا.",
                    "Category \"{$c->name_en}\" comes from Odoo and is still managed there — change it in Odoo and the change arrives here. Categories created in OPS are edited in OPS.");
            }
            $c->load('approvers');
            $before = $this->snapshot($c);
            $values = $this->values($dto, $c);
            if (array_key_exists('code', $dto) && $dto['code'] !== null && trim((string) $dto['code']) !== '' && mb_strtoupper(trim((string) $dto['code'])) !== mb_strtoupper($c->code)) {
                $values['code'] = $this->code($dto['code'], $c->id);
            }
            $approvers = array_key_exists('approvers', $dto) && is_array($dto['approvers']) ? $this->approverRows($dto['approvers']) : null;
            $this->checkMinimum($values['min_approvals'] ?? $c->min_approvals, $approvers !== null ? count($approvers) : $c->approvers->count());
            $c->update($values);
            if ($approvers !== null) {
                ApprovalCategoryApprover::where('category_id', $c->id)->delete();
                foreach ($approvers as $row) {
                    ApprovalCategoryApprover::create($row + ['category_id' => $c->id]);
                }
            }
            $after = $this->snapshot($c->refresh()->load('approvers'));
            $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== ($before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->audit->log($user, ['action' => 'APPROVAL_CATEGORY.UPDATE', 'entityType' => 'ApprovalCategory', 'entityId' => $c->id, 'entityNumber' => $c->code,
                    'oldValue' => array_intersect_key($before, array_flip($changed)), 'newValue' => array_intersect_key($after, array_flip($changed))]);
            }

            return $this->get($c->id);
        });
    }

    public function present(ApprovalCategory $c): array
    {
        $this->people->load($c->approvers->pluck('user_id')->all());

        return [
            'id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'description' => $c->description, 'active' => (bool) $c->active, 'sort' => (int) $c->sort,
            'approvalMode' => $c->approval_mode, 'minApprovals' => (int) $c->min_approvals, 'allowSelfApproval' => (bool) $c->allow_self_approval,
            'fields' => ApprovalFlow::fields($c->fields),
            'approvers' => $c->approvers->map(fn (ApprovalCategoryApprover $a) => [
                'id' => $a->id, 'user' => $this->people->present($a->user_id, $a->user_name, $a->user_login), 'sequence' => (int) $a->sequence, 'required' => (bool) $a->required,
                'minAmount' => $a->min_amount,
            ])->values()->all(),
            'source' => $c->source_system, 'externalRef' => $c->external_ref, 'owned' => $this->owned($c), 'sourceMeta' => $c->source_meta,
            'createdBy' => $c->created_by, 'createdAt' => $c->created_at?->toJSON(), 'updatedAt' => $c->updated_at?->toJSON(),
        ];
    }

    /** The short form a request carries. */
    public static function brief(ApprovalCategory $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'active' => (bool) $c->active, 'fields' => ApprovalFlow::fields($c->fields),
            'approvalMode' => $c->approval_mode, 'minApprovals' => (int) $c->min_approvals, 'allowSelfApproval' => (bool) $c->allow_self_approval];
    }

    // ───────────── internals ─────────────

    /** @param  string[]  $ids  @return array<string, array{requests:int, pending:int}> */
    private function counts(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (ApprovalRequest::whereIn('category_id', $ids)->groupBy('category_id', 'status')->selectRaw('category_id, status, count(*) as n')->toBase()->get() as $row) {
            $out[$row->category_id] ??= ['requests' => 0, 'pending' => 0];
            $out[$row->category_id]['requests'] += (int) $row->n;
            if ($row->status === 'pending') {
                $out[$row->category_id]['pending'] += (int) $row->n;
            }
        }

        return $out;
    }

    /** The columns of a create / update from the validated payload (only what was sent, on an update). */
    private function values(array $dto, ?ApprovalCategory $existing): array
    {
        $v = [];
        $has = fn (string $k) => array_key_exists($k, $dto);
        if (! $existing || $has('nameAr')) {
            $ar = trim((string) ($dto['nameAr'] ?? ''));
            if ($ar === '') {
                throw AppError::validation('APPROVAL_CATEGORY_NAME', 'اسم نوع الطلب إلزامي', 'The category name is required', [['path' => 'nameAr', 'message' => 'required']]);
            }
            $v['name_ar'] = mb_substr($ar, 0, 250);
        }
        if (! $existing || $has('nameEn')) {
            $en = trim((string) ($dto['nameEn'] ?? ''));
            $v['name_en'] = mb_substr($en !== '' ? $en : ($v['name_ar'] ?? $existing->name_ar), 0, 250);
        }
        if ($has('description')) {
            $v['description'] = ($d = trim((string) $dto['description'])) !== '' ? mb_substr($d, 0, 500) : null;
        }
        if ($has('active')) {
            $v['active'] = (bool) $dto['active'];
        }
        if ($has('sort')) {
            $v['sort'] = (int) $dto['sort'];
        }
        if (! $existing || $has('approvalMode')) {
            $mode = $dto['approvalMode'] ?? 'parallel';
            if (! in_array($mode, ApprovalFlow::MODES, true)) {
                throw AppError::validation('APPROVAL_MODE', 'طريقة الاعتماد غير معروفة', 'Unknown approval mode', [['path' => 'approvalMode', 'message' => 'parallel | sequence']]);
            }
            $v['approval_mode'] = $mode;
        }
        if (! $existing || $has('minApprovals')) {
            $v['min_approvals'] = max(1, (int) ($dto['minApprovals'] ?? 1));
        }
        if ($has('allowSelfApproval')) {
            $v['allow_self_approval'] = (bool) $dto['allowSelfApproval'];
        }
        if (! $existing || $has('fields')) {
            $given = is_array($dto['fields'] ?? null) ? $dto['fields'] : [];
            foreach ($given as $key => $level) {
                if (! in_array($key, ApprovalFlow::FIELDS, true) || ! in_array($level, ApprovalFlow::LEVELS, true)) {
                    throw AppError::validation('APPROVAL_FIELD_RULE', "قاعدة حقل غير معروفة: {$key}", "Unknown field rule: {$key}", [['path' => "fields.{$key}", 'message' => 'required | optional | no']]);
                }
            }
            $v['fields'] = ApprovalFlow::fields($given + ($existing ? (array) $existing->fields : []));
        }

        return $v;
    }

    /**
     * The approver rows of a category from the payload: every one an active user who can open the section, nobody twice.
     *
     * @param  array<int, array{user:string, sequence?:mixed, required?:mixed, minAmount?:mixed}>  $given
     * @return array<int, array{user_id:string, user_name:string, sequence:int, required:bool, min_amount:?float}>
     */
    private function approverRows(array $given): array
    {
        $rows = [];
        $seen = [];
        foreach (ApprovalFlow::rows($given) as $i => $a) {
            $u = $this->people->find((string) ($a['user'] ?? ''))
                ?? throw AppError::validation('APPROVAL_APPROVER_UNKNOWN', 'المعتمد المحدد ليس مستخدمًا نشطًا في النظام', 'The approver is not an active user', [['path' => "approvers.{$i}.user", 'message' => 'unknown user']]);
            if (isset($seen[$u->id])) {
                throw AppError::validation('APPROVAL_APPROVER_DUPLICATE', "المعتمد {$u->name_ar} مكرر في القائمة", "Approver {$u->name_en} is listed twice", [['path' => "approvers.{$i}.user", 'message' => 'duplicate']]);
            }
            if (! $this->people->canOpen($u->id)) {
                throw AppError::rule('APPROVAL_APPROVER_NO_ACCESS', "المستخدم {$u->name_ar} لا يملك صلاحية فتح قسم الاعتمادات (page.approvals) فلا يستطيع اتخاذ قرار — امنحه الصلاحية أولًا",
                    "User {$u->name_en} cannot open the approvals section (page.approvals), so he cannot decide — grant it first");
            }
            $seen[$u->id] = true;
            $min = $a['minAmount'] ?? null;
            $rows[] = [
                'user_id' => $u->id, 'user_name' => $u->name_ar,
                'sequence' => isset($a['sequence']) && $a['sequence'] !== '' && $a['sequence'] !== null ? (int) $a['sequence'] : ($i + 1) * 10,
                'required' => ! empty($a['required']), 'min_amount' => $min === null || $min === '' || (float) $min <= 0 ? null : round((float) $min, 2),
            ];
        }
        usort($rows, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return $rows;
    }

    /** More approvals than approvers can never be reached: that is a mistake in the form, not a rule to keep. */
    private function checkMinimum(int $min, int $approvers): void
    {
        if ($approvers > 0 && $min > $approvers) {
            throw AppError::rule('APPROVAL_MIN_EXCEEDS_APPROVERS', "الحد الأدنى للاعتمادات ({$min}) أكبر من عدد المعتمدين ({$approvers})", "The minimum number of approvals ({$min}) exceeds the number of approvers ({$approvers})");
        }
    }

    /** The code of a category: the one typed (letters, digits, dot, dash, underscore), or the next AC-001. */
    private function code(mixed $given, ?string $exceptId): string
    {
        $code = mb_strtoupper(trim((string) ($given ?? '')));
        if ($code === '') {
            DB::table('number_sequences')->insertOrIgnore(['key' => self::SEQUENCE, 'prefix' => 'AC-', 'next' => 1, 'width' => 3]);
            do {
                $code = $this->numbering->next(self::SEQUENCE);
            } while (ApprovalCategory::where('code', $code)->exists());

            return $code;
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $code)) {
            throw AppError::validation('APPROVAL_CATEGORY_CODE', 'رمز النوع: حروف لاتينية وأرقام وشرطة فقط، حتى 40 خانة', 'Category code: Latin letters, digits and dashes only, up to 40 characters', [['path' => 'code', 'message' => 'invalid']]);
        }
        if (str_starts_with($code, 'OD-')) {
            throw AppError::validation('APPROVAL_CATEGORY_CODE_RESERVED', 'الرموز التي تبدأ بـ OD- محجوزة لأنواع أودو', 'Codes starting with OD- are reserved for the categories of Odoo', [['path' => 'code', 'message' => 'reserved']]);
        }
        if (ApprovalCategory::where('code', $code)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw AppError::conflict('APPROVAL_CATEGORY_CODE_TAKEN', "الرمز {$code} مستخدم لنوع آخر", "Code {$code} is used by another category");
        }

        return $code;
    }

    /** What the audit trail keeps of a category. */
    private function snapshot(ApprovalCategory $c): array
    {
        return [
            'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'description' => $c->description, 'active' => (bool) $c->active, 'sort' => (int) $c->sort,
            'approvalMode' => $c->approval_mode, 'minApprovals' => (int) $c->min_approvals, 'allowSelfApproval' => (bool) $c->allow_self_approval, 'fields' => ApprovalFlow::fields($c->fields),
            'approvers' => $c->approvers->map(fn (ApprovalCategoryApprover $a) => ['user' => $a->user_name, 'sequence' => (int) $a->sequence, 'required' => (bool) $a->required, 'minAmount' => $a->min_amount !== null ? (float) $a->min_amount : null])->values()->all(),
        ];
    }
}
