<?php

namespace App\Hr\Services;

use App\Finance\Models\Account;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\ExpenseCategory;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * Expense categories: what a claim line is for, and the account of the chart its lines are charged to. The list is
 * read by everyone who can file or review a claim; the reviewers (`expense.review`) keep it — the account is theirs.
 * A category that came from Odoo (an expense product) keeps Odoo's name while the section follows Odoo; its account in
 * the OPS chart, its proposed VAT rate and whether it is offered are OPS's own from the first day.
 */
class CategoriesService
{
    public const SOURCE_OWNED = ['code', 'name_ar', 'name_en'];

    public function __construct(private readonly AuditService $audit) {}

    public function list(AuthUser $user, bool $withArchived = false): array
    {
        $reviewer = $user->can('expense.review');
        $rows = ExpenseCategory::query()->when(! $withArchived || ! $reviewer, fn ($q) => $q->where('active', true))->orderBy('name_ar')->orderBy('id')->get();
        $used = $reviewer && $rows->isNotEmpty() ? ClaimLine::whereIn('category_id', $rows->pluck('id'))->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id') : collect();

        return ['items' => $rows->map(fn (ExpenseCategory $c) => $this->shape($c, $reviewer, (int) ($used[$c->id] ?? 0)))->all()];
    }

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $code = mb_strtoupper((string) Hr::text($dto['code'], 40));
            if (ExpenseCategory::where('code', $code)->exists()) {
                throw AppError::conflict('CATEGORY_CODE_TAKEN', "رمز الفئة {$code} مستخدم", "Category code {$code} is taken");
            }
            $c = ExpenseCategory::create(['code' => $code, 'name_ar' => Hr::text($dto['nameAr']), 'name_en' => Hr::text($dto['nameEn'] ?? null) ?? Hr::text($dto['nameAr']),
                'account_code' => $this->account($dto['accountCode'] ?? null), 'vat_pct' => (float) ($dto['vatPct'] ?? 0), 'active' => true, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'HR.CATEGORY.CREATE', 'entityType' => 'ExpenseCategory', 'entityId' => $c->id, 'entityNumber' => $c->code, 'newValue' => ['nameAr' => $c->name_ar, 'account' => $c->account_code, 'vatPct' => $c->vat_pct]]);

            return $this->shape($c, true, 0);
        });
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $c = ExpenseCategory::where(fn ($w) => $w->where('id', $id)->orWhere('code', $id))->lockForUpdate()->first() ?? throw AppError::notFound('CATEGORY_NOT_FOUND', 'فئة المصروف غير موجودة', 'Expense category not found');
            $new = [];
            foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en'] as $field => $column) {
                if (array_key_exists($field, $dto) && ($x = Hr::text($dto[$field])) !== null) {
                    $new[$column] = $x;
                }
            }
            if (array_key_exists('accountCode', $dto)) {
                $new['account_code'] = $this->account($dto['accountCode']);
            }
            if (array_key_exists('vatPct', $dto) && $dto['vatPct'] !== null) {
                $new['vat_pct'] = (float) $dto['vatPct'];
            }
            if (array_key_exists('active', $dto) && $dto['active'] !== null) {
                $new['active'] = filter_var($dto['active'], FILTER_VALIDATE_BOOLEAN);
            }
            $changes = Hr::diff($c, $new);
            Hr::guardSource($c, $changes, self::SOURCE_OWNED, 'category', "فئة المصروف {$c->code}", "Expense category {$c->code}");
            if ($changes) {
                $old = array_intersect_key($c->getAttributes(), $changes);
                $c->update($changes);
                $this->audit->log($user, ['action' => 'HR.CATEGORY.UPDATE', 'entityType' => 'ExpenseCategory', 'entityId' => $c->id, 'entityNumber' => $c->code, 'oldValue' => $old, 'newValue' => $changes]);
            }

            return $this->shape($c, true, ClaimLine::where('category_id', $c->id)->count());
        });
    }

    /** The code of a postable expense (or asset) account of the chart — the rule the finance voucher applies when it posts. */
    private function account(mixed $code): ?string
    {
        $code = Hr::text($code, 20);
        if ($code === null) {
            return null;
        }
        $a = Account::where('code', $code)->first() ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', "الحساب {$code} غير موجود", "Account {$code} not found");
        if (! $a->is_postable || ! $a->active || ! in_array($a->type, ['expense', 'asset'], true)) {
            throw AppError::rule('EXPENSE_ACCOUNT', "الحساب {$a->code} ليس حساب مصروف يقبل الترحيل", "Account {$a->code} is not a postable expense account");
        }

        return $a->code;
    }

    private function shape(ExpenseCategory $c, bool $reviewer, int $used): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'nameAr' => $c->name_ar, 'nameEn' => $c->name_en, 'vatPct' => (float) $c->vat_pct, 'active' => (bool) $c->active,
            'source' => $c->source_system, 'locked' => Source::locked($c->source_system)]
            + ($reviewer ? ['accountCode' => $c->account_code, 'lines' => $used] : []);
    }
}
