<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Models\CostCenter;
use App\Finance\Models\JournalLine;
use App\Finance\Support\Coa;
use App\Models\Vehicle;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/** Chart of accounts, cost centers and bank accounts — master data of the ledger (every change audited). */
class AccountsService
{
    public function __construct(private readonly AuditService $audit, private readonly AccountMap $map) {}

    /** The chart as a flat, sorted list with each account's posted balance (natural sign). */
    public function chart(bool $withBalances = true): array
    {
        $accounts = Account::orderBy('code')->get();
        $sums = $withBalances ? JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->where('fin_journals.status', '!=', 'draft')
            ->selectRaw('fin_journal_lines.account_id, coalesce(sum(fin_journal_lines.debit), 0) as debit, coalesce(sum(fin_journal_lines.credit), 0) as credit')->groupBy('fin_journal_lines.account_id')->get()->keyBy('account_id') : collect();

        $parents = $accounts->pluck('parent_id', 'id');
        $depth = function (Account $a) use ($parents): int {
            $n = 0;
            $p = $a->parent_id;
            while ($p && $n < 8) {
                $n++;
                $p = $parents[$p] ?? null;
            }

            return $n;
        };

        return $accounts->map(function (Account $a) use ($sums, $depth) {
            $d = (float) ($sums[$a->id]->debit ?? 0);
            $c = (float) ($sums[$a->id]->credit ?? 0);

            return $a->toArray() + ['debit' => round($d, 2), 'credit' => round($c, 2), 'balance' => LedgerService::natural($a, $d, $c), 'depth' => $depth($a)];
        })->all();
    }

    /** @param  array{code:string, nameAr:string, nameEn?:?string, type:string, subtype?:?string, parent?:?string, isPostable?:bool, role?:?string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        if (! in_array($dto['type'], Account::TYPES, true)) {
            throw AppError::validation('ACCOUNT_TYPE', 'نوع الحساب غير معروف', 'Unknown account type');
        }
        if (Account::where('code', $dto['code'])->exists()) {
            throw AppError::conflict('ACCOUNT_EXISTS', "الحساب {$dto['code']} موجود", 'Account code exists');
        }
        $parent = ! empty($dto['parent']) ? $this->map->code($dto['parent']) : null;
        $role = ($dto['role'] ?? null) ?: null;
        if ($role && ! in_array($role, Coa::ROLES, true)) {
            throw AppError::validation('ACCOUNT_ROLE', "الدور المحاسبي {$role} غير معروف", 'Unknown posting role');
        }
        if ($role && Account::where('role', $role)->exists()) {
            throw AppError::conflict('ROLE_TAKEN', "الدور {$role} مرتبط بحساب آخر — انقله من ذلك الحساب أولًا", 'Role already mapped');
        }
        $a = Account::create(['code' => $dto['code'], 'name_ar' => $dto['nameAr'], 'name_en' => $dto['nameEn'] ?: $dto['nameAr'], 'type' => $dto['type'], 'subtype' => $dto['subtype'] ?? null, 'parent_id' => $parent?->id,
            'is_postable' => $dto['isPostable'] ?? true, 'role' => $role, 'active' => true, 'sort' => 999]);
        $this->audit->log($user, ['action' => 'ACCOUNT.CREATE', 'entityType' => 'Account', 'entityId' => $a->id, 'entityNumber' => $a->code, 'newValue' => $dto]);
        $this->map->flush();

        return $a->refresh()->toArray();
    }

    /** Rename, re-parent, (de)activate, move a posting role. Type and code of an account with postings never change. */
    public function update(AuthUser $user, string $code, array $dto): array
    {
        $a = $this->map->code($code);
        $hasPostings = JournalLine::where('account_id', $a->id)->exists();
        $data = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'subtype' => 'subtype'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $data[$col] = $dto[$k];
            }
        }
        if (array_key_exists('active', $dto)) {
            if (! $dto['active'] && $a->role) {
                throw AppError::rule('ACCOUNT_ROLE_ACTIVE', "الحساب {$a->code} مرتبط بدور محاسبي ({$a->role}) — انقل الدور قبل إيقافه", 'Account carries a posting role');
            }
            $data['active'] = (bool) $dto['active'];
        }
        if (array_key_exists('parent', $dto)) {
            $data['parent_id'] = $dto['parent'] ? $this->map->code($dto['parent'])->id : null;
        }
        if (array_key_exists('isPostable', $dto)) {
            if (! $dto['isPostable'] && $hasPostings) {
                throw AppError::rule('ACCOUNT_HAS_POSTINGS', 'الحساب عليه قيود ولا يمكن جعله رئيسيًا', 'Account has postings');
            }
            $data['is_postable'] = (bool) $dto['isPostable'];
        }
        if (array_key_exists('role', $dto)) {
            $role = $dto['role'] ?: null;
            if ($role && ! in_array($role, Coa::ROLES, true)) {
                throw AppError::validation('ACCOUNT_ROLE', "الدور المحاسبي {$role} غير معروف", 'Unknown posting role');
            }
            if ($role) {
                $holder = Account::where('role', $role)->where('id', '!=', $a->id)->first();
                if ($holder) {
                    $holder->update(['role' => null]); // the role moves here
                    $this->audit->log($user, ['action' => 'ACCOUNT.ROLE_MOVE', 'entityType' => 'Account', 'entityId' => $holder->id, 'entityNumber' => $holder->code, 'field' => 'role', 'oldValue' => $role, 'newValue' => "→ {$a->code}"]);
                }
            }
            $data['role'] = $role;
        }
        if (array_key_exists('type', $dto) && $dto['type'] !== $a->type) {
            if ($hasPostings) {
                throw AppError::rule('ACCOUNT_HAS_POSTINGS', 'لا يتغير نوع حساب عليه قيود', 'Type of a posted account cannot change');
            }
            if (! in_array($dto['type'], Account::TYPES, true)) {
                throw AppError::validation('ACCOUNT_TYPE', 'نوع الحساب غير معروف', 'Unknown account type');
            }
            $data['type'] = $dto['type'];
        }
        $before = $a->only(array_keys($data));
        $a->update($data + ['updated_at' => now()]);
        $this->audit->log($user, ['action' => 'ACCOUNT.UPDATE', 'entityType' => 'Account', 'entityId' => $a->id, 'entityNumber' => $a->code, 'oldValue' => $before, 'newValue' => $data]);
        $this->map->flush();

        return $a->refresh()->toArray();
    }

    public function costCenters(): array
    {
        return CostCenter::orderBy('kind')->orderBy('code')->get()->map->toArray()->all();
    }

    /** @param  array{code:string, nameAr:string, nameEn?:?string, kind?:string, vehicle?:?string}  $dto */
    public function createCostCenter(AuthUser $user, array $dto): array
    {
        if (CostCenter::where('code', $dto['code'])->exists()) {
            throw AppError::conflict('COST_CENTER_EXISTS', "مركز التكلفة {$dto['code']} موجود", 'Cost center exists');
        }
        $vehicle = ! empty($dto['vehicle']) ? (Vehicle::where('code', $dto['vehicle'])->orWhere('id', $dto['vehicle'])->first() ?? throw AppError::notFound('VEHICLE_NOT_FOUND', 'المركبة غير موجودة', 'Vehicle not found')) : null;
        $cc = CostCenter::create(['code' => $dto['code'], 'name_ar' => $dto['nameAr'], 'name_en' => $dto['nameEn'] ?: $dto['nameAr'], 'kind' => $vehicle ? 'vehicle' : ($dto['kind'] ?? 'admin'), 'vehicle_id' => $vehicle?->id, 'active' => true]);
        $this->audit->log($user, ['action' => 'COST_CENTER.CREATE', 'entityType' => 'CostCenter', 'entityId' => $cc->id, 'entityNumber' => $cc->code, 'newValue' => $dto]);

        return $cc->refresh()->toArray();
    }

    public function banks(): array
    {
        return BankAccount::with('account:id,code,name_ar,name_en')->orderBy('code')->get()->map(function (BankAccount $b) {
            $s = JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->where('fin_journals.status', '!=', 'draft')->where('fin_journal_lines.account_id', $b->account_id)
                ->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) as debit, coalesce(sum(fin_journal_lines.credit), 0) as credit')->first();

            return $b->toArray() + ['balance' => round((float) $s->debit - (float) $s->credit, 2)];
        })->all();
    }

    /** @param  array{code:string, nameAr:string, nameEn?:?string, kind?:string, bankName?:?string, iban?:?string, account?:?string}  $dto */
    public function createBank(AuthUser $user, array $dto): array
    {
        if (BankAccount::where('code', $dto['code'])->exists()) {
            throw AppError::conflict('BANK_ACCOUNT_EXISTS', "الحساب {$dto['code']} موجود", 'Bank account exists');
        }
        $kind = $dto['kind'] ?? 'bank';
        // a dedicated GL account per bank keeps the balance sheet readable: created under the bank/cash header automatically
        $gl = ! empty($dto['account']) ? $this->map->code($dto['account']) : DB::transaction(function () use ($dto, $kind) {
            $base = $kind === 'cash' ? 1101 : 1111;
            $code = (string) $base;
            while (Account::where('code', $code)->exists()) {
                $code = (string) (((int) $code) + 1);
            }
            $parent = Account::where('code', '1100')->first();

            return Account::create(['code' => $code, 'name_ar' => $dto['nameAr'], 'name_en' => $dto['nameEn'] ?: $dto['nameAr'], 'type' => 'asset', 'subtype' => $kind, 'parent_id' => $parent?->id, 'is_postable' => true, 'active' => true, 'sort' => 999]);
        });
        $b = BankAccount::create(['code' => $dto['code'], 'name_ar' => $dto['nameAr'], 'name_en' => $dto['nameEn'] ?: $dto['nameAr'], 'kind' => $kind, 'bank_name' => $dto['bankName'] ?? null, 'iban' => $dto['iban'] ?? null, 'account_id' => $gl->id, 'currency' => 'SAR', 'active' => true]);
        $this->audit->log($user, ['action' => 'BANK_ACCOUNT.CREATE', 'entityType' => 'BankAccount', 'entityId' => $b->id, 'entityNumber' => $b->code, 'newValue' => ['glAccount' => $gl->code, 'iban' => $dto['iban'] ?? null]]);

        return $b->refresh()->load('account:id,code,name_ar,name_en')->toArray();
    }

    public function updateBank(AuthUser $user, string $code, array $dto): array
    {
        $b = BankAccount::where('code', $code)->orWhere('id', $code)->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found');
        $data = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'bankName' => 'bank_name', 'iban' => 'iban', 'active' => 'active'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $data[$col] = $dto[$k];
            }
        }
        $b->update($data);
        $this->audit->log($user, ['action' => 'BANK_ACCOUNT.UPDATE', 'entityType' => 'BankAccount', 'entityId' => $b->id, 'entityNumber' => $b->code, 'newValue' => $data]);

        return $b->refresh()->load('account:id,code,name_ar,name_en')->toArray();
    }
}
