<?php

namespace App\Banking\Services;

use App\Banking\Models\PaymentTerm;
use App\Banking\Models\WriteoffRule;
use App\Banking\Support\Money;
use App\Banking\Support\Owned;
use App\Banking\Support\Shape;
use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Services\LedgerService;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Write-off rules: where a difference between the bank and the books may go, and up to how much. A rule posts a
 * journal with one click, so it is a control — it names a postable account that is neither a customers / suppliers
 * control account (those need a party) nor the ledger account of a bank, and it always carries a limit.
 * A rule is never deleted: it is switched off and stays on the matches that used it.
 *
 * A rule that came from Odoo is Odoo's while the section follows it; the OPS ledger account it writes off to is OPS's
 * own choice and may be set here — Odoo's chart of accounts is not OPS's.
 */
class RulesService
{
    public const MAX_LIMIT = '1000000.00';

    public function __construct(private readonly Banks $banks, private readonly AuditService $audit) {}

    /** @param  array{active?:?string, origin?:?string, kind?:?string, bank?:?string, nature?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = WriteoffRule::query()->with(['account:id,code,name_ar,name_en,is_postable,active', 'bankAccount:id,code,name_ar,name_en,kind']);
        if (($f['active'] ?? '') !== '') {
            $q->where('active', $f['active'] === '1');
        }
        if (($f['origin'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        } elseif (! empty($f['origin'])) {
            $q->where('source_system', $f['origin']);
        }
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (! empty($f['nature'])) {
            $q->where('nature', $f['nature']);
        }
        if (! empty($f['bank'])) {
            $id = $this->banks->find($f['bank'])->id;
            $q->where(fn ($w) => $w->whereNull('bank_account_id')->orWhere('bank_account_id', $id));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('label', 'like', $like)->orWhere('match_text', 'like', $like));
        }
        $column = match ($page->sort) {
            'name' => 'name_ar', 'limit' => 'max_amount', 'created' => 'created_at', default => 'sequence'
        };
        $q->orderBy($column, $page->sort ? $page->order : 'asc')->orderBy('created_at')->orderBy('id');

        return $page->paginate($q, fn (WriteoffRule $r) => Shape::rule($r));
    }

    public function get(string $id): array
    {
        return Shape::rule($this->find($id));
    }

    private function find(string $id, bool $lock = false): WriteoffRule
    {
        $q = WriteoffRule::with(['account:id,code,name_ar,name_en,is_postable,active', 'bankAccount:id,code,name_ar,name_en,kind'])->whereKey($id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw AppError::notFound('BANK_RULE_NOT_FOUND', 'قاعدة التسوية غير موجودة', 'Write-off rule not found');
    }

    /** @param  array{nameAr:string, nameEn?:?string, nature?:?string, account:string, label?:?string, maxAmount:string|int|float, matchText?:?string, bankAccount?:?string, sequence?:?int}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $name = trim((string) $dto['nameAr']);
            $this->refuseTwin($name, null);
            $account = $this->account((string) $dto['account']);
            $bank = ! empty($dto['bankAccount']) ? $this->banks->find((string) $dto['bankAccount']) : null;
            $rule = WriteoffRule::create([
                'name_ar' => $name, 'name_en' => trim((string) ($dto['nameEn'] ?? '')) ?: $name, 'kind' => 'writeoff', 'nature' => $dto['nature'] ?? 'both', 'account_id' => $account->id,
                'label' => self::text($dto['label'] ?? null, 190), 'max_amount' => self::limit($dto['maxAmount']), 'match_text' => self::text($dto['matchText'] ?? null, 250), 'bank_account_id' => $bank?->id,
                'sequence' => $dto['sequence'] ?? ((int) WriteoffRule::max('sequence') + 10), 'active' => true, 'created_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'BANK.RULE.CREATE', 'entityType' => 'BankWriteoffRule', 'entityId' => $rule->id, 'entityNumber' => $rule->name_ar,
                'newValue' => ['account' => $account->code, 'nature' => $rule->nature, 'maxAmount' => (string) $rule->max_amount, 'bank' => $bank?->code, 'matchText' => $rule->match_text]]);

            return Shape::rule($this->find($rule->id));
        });
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        return DB::transaction(function () use ($user, $id, $dto) {
            $rule = $this->find($id, lock: true);
            // the OPS ledger account and the limit of a rule are OPS's own (Odoo's chart is not OPS's, and Odoo gives a
            // limit only as a fixed tolerance); everything else of a rule Odoo owns is changed in Odoo
            if (array_diff(array_keys($dto), ['account', 'maxAmount'])) {
                Owned::refuse($rule, 'rule');
            }
            $data = [];
            if (array_key_exists('nameAr', $dto)) {
                $data['name_ar'] = trim((string) $dto['nameAr']);
                $this->refuseTwin($data['name_ar'], $rule->id);
            }
            if (array_key_exists('nameEn', $dto)) {
                $data['name_en'] = trim((string) ($dto['nameEn'] ?? '')) ?: ($data['name_ar'] ?? $rule->name_ar);
            }
            if (array_key_exists('account', $dto)) {
                $data['account_id'] = $dto['account'] ? $this->account((string) $dto['account'])->id : null;
            }
            if (array_key_exists('bankAccount', $dto)) {
                $data['bank_account_id'] = $dto['bankAccount'] ? $this->banks->find((string) $dto['bankAccount'])->id : null;
            }
            foreach (['nature' => 'nature', 'kind' => 'kind', 'sequence' => 'sequence', 'active' => 'active'] as $key => $column) {
                if (isset($dto[$key])) {
                    $data[$column] = $dto[$key];
                }
            }
            if (array_key_exists('label', $dto)) {
                $data['label'] = self::text($dto['label'], 190);
            }
            if (array_key_exists('matchText', $dto)) {
                $data['match_text'] = self::text($dto['matchText'], 250);
            }
            if (array_key_exists('maxAmount', $dto)) {
                $data['max_amount'] = $dto['maxAmount'] === null || $dto['maxAmount'] === '' ? null : self::limit($dto['maxAmount']);
            }
            // a rule that may post always names its account and its limit
            $after = fn (string $column) => array_key_exists($column, $data) ? $data[$column] : $rule->getAttribute($column);
            if ($after('kind') === 'writeoff' && $after('active') && ($after('account_id') === null || $after('max_amount') === null) && ! Owned::bySource($rule)) {
                throw AppError::validation('BANK_RULE_INCOMPLETE', 'قاعدة التسوية الفعّالة تحتاج حساب الأستاذ وحدّ المبلغ', 'An active write-off rule needs its ledger account and its limit',
                    [['path' => $after('account_id') === null ? 'account' : 'maxAmount', 'message' => 'هذا الحقل إلزامي']]);
            }
            $changes = [];
            foreach ($data as $column => $value) {
                $current = $rule->getAttribute($column);
                $same = is_bool($current) || is_bool($value) ? (bool) $current === (bool) $value : (string) ($current ?? '') === (string) ($value ?? '');
                if (! $same) {
                    $changes[$column] = [$current, $value];
                }
            }
            if ($changes) {
                $rule->update(array_map(fn (array $c) => $c[1], $changes) + ['updated_by' => $user->username]);
                $codes = Account::whereIn('id', array_filter([$changes['account_id'][0] ?? null, $changes['account_id'][1] ?? null]))->pluck('code', 'id');
                $show = fn (string $column, mixed $v) => $column === 'account_id' ? ($codes[$v] ?? null) : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v);
                $this->audit->log($user, ['action' => 'BANK.RULE.UPDATE', 'entityType' => 'BankWriteoffRule', 'entityId' => $rule->id, 'entityNumber' => $rule->name_ar,
                    'oldValue' => array_map(fn (string $c) => $show($c, $changes[$c][0]), array_combine(array_keys($changes), array_keys($changes))),
                    'newValue' => array_map(fn (string $c) => $show($c, $changes[$c][1]), array_combine(array_keys($changes), array_keys($changes)))]);
            }

            return Shape::rule($this->find($rule->id));
        });
    }

    /** The account a rule may post to: postable, active, not a control account that needs a party, not a bank's own account. */
    private function account(string $codeOrId): Account
    {
        $account = Account::where('code', $codeOrId)->orWhere('id', $codeOrId)->first() ?? throw AppError::notFound('ACCOUNT_NOT_FOUND', "الحساب {$codeOrId} غير موجود", "Account {$codeOrId} not found");
        if (! $account->is_postable || ! $account->active) {
            throw AppError::rule('BANK_RULE_ACCOUNT', "الحساب {$account->code} — {$account->name_ar} لا يقبل الترحيل", "Account {$account->code} is not postable");
        }
        if (isset(LedgerService::CONTROL_SUBTYPES[$account->subtype])) {
            throw AppError::rule('BANK_RULE_ACCOUNT', "الحساب {$account->code} حساب تحكم ({$account->name_ar}) يحتاج طرفًا — لا يصلح لتسوية فروق البنك", "Account {$account->code} is a control account that needs a party — it cannot take bank differences");
        }
        if (BankAccount::where('account_id', $account->id)->exists()) {
            throw AppError::rule('BANK_RULE_ACCOUNT', "الحساب {$account->code} هو حساب بنك أو صندوق — الفرق يُرحَّل إلى حساب مصروف أو إيراد", "Account {$account->code} is the ledger account of a bank or cash box — a difference goes to an expense or income account");
        }

        return $account;
    }

    private function refuseTwin(string $name, ?string $exceptId): void
    {
        if (WriteoffRule::where('name_ar', $name)->where('active', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw AppError::conflict('BANK_RULE_EXISTS', "توجد قاعدة تسوية فعّالة بالاسم «{$name}»", "An active write-off rule named “{$name}” already exists");
        }
    }

    private static function limit(mixed $value): string
    {
        $cents = Money::cents($value);
        if ($cents <= 0 || $cents > Money::cents(self::MAX_LIMIT)) {
            throw AppError::validation('BANK_RULE_LIMIT', 'حدّ القاعدة مبلغ أكبر من صفر ولا يتجاوز '.self::MAX_LIMIT, 'The limit of a rule is an amount above zero and at most '.self::MAX_LIMIT, [['path' => 'maxAmount', 'message' => 'مبلغ أكبر من صفر']]);
        }

        return Money::str($cents);
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    // ───────────────────────────── payment terms (a dictionary the section keeps) ─────────────────────────────

    /** @param  array{active?:?string, origin?:?string}  $f */
    public function terms(Paging $page, array $f): array
    {
        $q = PaymentTerm::query();
        if (($f['active'] ?? '') !== '') {
            $q->where('active', $f['active'] === '1');
        }
        if (($f['origin'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        } elseif (! empty($f['origin'])) {
            $q->where('source_system', $f['origin']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('note', 'like', $like));
        }
        $column = match ($page->sort) {
            'name' => 'name', 'days' => 'due_days', default => 'sequence'
        };
        $q->orderBy($column, $page->sort ? $page->order : 'asc')->orderBy('name')->orderBy('id');

        return $page->paginate($q, fn (PaymentTerm $t) => Shape::term($t));
    }
}
