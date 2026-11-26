<?php

namespace App\Banking\Services;

use App\Banking\Models\SourceJournal;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Support\Mask;
use App\Banking\Support\Shape;
use App\Finance\Models\BankAccount;
use App\Finance\Services\AccountsService;
use App\Integration\Models\IntExternalRef;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Services\ExternalRefs;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * The bank and cash journals of the source system and the OPS bank account each of them IS.
 *
 * The projection links a journal by identity only — the same IBAN, the same account number, exactly the same name —
 * and never makes a second bank account beside an existing one. What it could not link is listed here for a person,
 * who either names the OPS bank account it is (`link`) or — holding finance's `bank.manage` as well — has one made
 * for it through finance's own service (`adopt`). The link is OPS's own: it may be made and removed while the section
 * follows Odoo; everything else of a journal is Odoo's.
 *
 * A link is one-to-one in both directions and is also kept in `int_external_refs` (system `odoo`, entity
 * `bank_account`), where the other sections find the OPS bank account of an Odoo journal.
 */
class SourceJournalsService
{
    public const REF_ENTITY = 'bank_account';

    public function __construct(private readonly Banks $banks, private readonly ExternalRefs $refs, private readonly AuditService $audit) {}

    /** @param  array{linked?:?string, kind?:?string, active?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $full = Mask::sees($user);
        $q = SourceJournal::query()->with('bankAccount:id,code,name_ar,name_en,kind')->withCount('statements')
            ->selectSub(StatementLine::query()->toBase()->selectRaw('count(*)')->whereIn('statement_id', Statement::query()->toBase()->select('id')->whereColumn('source_journal_id', 'bank_source_journals.id')), 'lines_count');
        if (($f['linked'] ?? '') !== '') {
            $f['linked'] === '1' ? $q->whereNotNull('bank_account_id') : $q->whereNull('bank_account_id');
        }
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (($f['active'] ?? '') !== '') {
            $q->where('active', $f['active'] === '1');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('bank_name', 'like', $like)->orWhere('ledger_account', 'like', $like)
                ->when($full, fn ($x) => $x->orWhere('account_number', 'like', $like)));
        }
        $column = match ($page->sort) {
            'name' => 'name', 'kind' => 'kind', 'linked' => 'bank_account_id', default => 'code'
        };
        $q->orderBy($column, $page->sort ? $page->order : 'asc')->orderBy('id');

        return $page->paginate($q, fn (SourceJournal $j) => Shape::journal($j, $full))
            + ['summary' => ['all' => SourceJournal::count(), 'linked' => SourceJournal::whereNotNull('bank_account_id')->count(), 'unlinked' => SourceJournal::whereNull('bank_account_id')->where('active', true)->count()]];
    }

    /** A person says which OPS bank account the journal is. */
    public function link(AuthUser $user, string $id, string $bankIdOrCode): array
    {
        return DB::transaction(function () use ($user, $id, $bankIdOrCode) {
            $journal = $this->find($id);
            $bank = $this->banks->find($bankIdOrCode);
            $this->attach($journal, $bank, 'manual', $user->username);
            $this->audit->log($user, ['action' => 'BANK.JOURNAL.LINK', 'entityType' => 'BankSourceJournal', 'entityId' => $journal->id, 'entityNumber' => $journal->code ?: $journal->name,
                'newValue' => ['bank' => $bank->code, 'by' => 'manual', 'source' => "{$journal->source_system} #{$journal->external_ref}"]]);

            return Shape::journal($journal->refresh()->load('bankAccount:id,code,name_ar,name_en,kind'), Mask::sees($user));
        });
    }

    public function unlink(AuthUser $user, string $id, string $reason): array
    {
        $reason = StatementsService::reason($reason, 'اكتب سبب فك الربط (5 أحرف على الأقل)');

        return DB::transaction(function () use ($user, $id, $reason) {
            $journal = $this->find($id);
            if ($journal->bank_account_id === null) {
                throw AppError::rule('BANK_JOURNAL_NOT_LINKED', 'الدفتر غير مربوط بحساب بنكي', 'The journal is not linked to a bank account');
            }
            $code = BankAccount::whereKey($journal->bank_account_id)->value('code');
            $this->detach($journal);
            // a link a person removed is not made again by the projection: the journal now waits for a person
            $journal->update(['auto_link' => false]);
            $this->audit->log($user, ['action' => 'BANK.JOURNAL.UNLINK', 'entityType' => 'BankSourceJournal', 'entityId' => $journal->id, 'entityNumber' => $journal->code ?: $journal->name,
                'oldValue' => ['bank' => $code], 'newValue' => ['reason' => $reason]]);

            return Shape::journal($journal->refresh(), Mask::sees($user));
        });
    }

    /**
     * No OPS bank account answers to the journal: one is made for it by finance's own service (its ledger account is
     * created under the bank / cash header, as finance does for every new bank) and linked. Needs `bank.manage` as
     * well — it is finance's master data. The account number is NOT copied: it is typed in finance by a person.
     *
     * @param  array{code?:?string, nameAr?:?string, nameEn?:?string}  $dto
     */
    public function adopt(AuthUser $user, string $id, array $dto): array
    {
        if (! $user->can('bank.manage')) {
            throw AppError::forbidden('FORBIDDEN', 'إنشاء حساب بنكي من بيانات المالية الأساسية ويحتاج صلاحية bank.manage', 'Creating a bank account is finance master data and needs bank.manage');
        }

        return DB::transaction(function () use ($user, $id, $dto) {
            $journal = $this->find($id);
            if ($journal->bank_account_id !== null) {
                throw AppError::rule('BANK_JOURNAL_LINKED', 'الدفتر مربوط بحساب بنكي مسبقًا', 'The journal is already linked to a bank account');
            }
            $code = strtoupper(trim((string) ($dto['code'] ?? $journal->code ?? '')));
            if (! preg_match('/^[A-Z0-9][A-Z0-9_\-]{1,19}$/', $code)) {
                throw AppError::validation('BANK_JOURNAL_CODE', 'رمز الحساب البنكي: من حرفين إلى عشرين (حروف لاتينية وأرقام)', 'The bank account code is 2 to 20 Latin letters and digits', [['path' => 'code', 'message' => 'رمز غير صالح']]);
            }
            $name = trim((string) ($dto['nameAr'] ?? '')) ?: $journal->name;
            // finance's service: it refuses a code that exists (BANK_ACCOUNT_EXISTS) and audits the creation itself
            $made = app(AccountsService::class)->createBank($user, ['code' => $code, 'nameAr' => $name, 'nameEn' => trim((string) ($dto['nameEn'] ?? '')) ?: $name, 'kind' => $journal->kind === 'cash' ? 'cash' : 'bank', 'bankName' => $journal->bank_name]);
            $this->banks->flush();
            $bank = $this->banks->find((string) $made['id']);
            $this->attach($journal, $bank, 'adopted', $user->username);
            $this->audit->log($user, ['action' => 'BANK.JOURNAL.ADOPT', 'entityType' => 'BankSourceJournal', 'entityId' => $journal->id, 'entityNumber' => $journal->code ?: $journal->name,
                'newValue' => ['bank' => $bank->code, 'source' => "{$journal->source_system} #{$journal->external_ref}"]]);

            return Shape::journal($journal->refresh()->load('bankAccount:id,code,name_ar,name_en,kind'), Mask::sees($user));
        });
    }

    private function find(string $id): SourceJournal
    {
        return SourceJournal::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('BANK_JOURNAL_NOT_FOUND', 'دفتر البنك غير موجود', 'Bank journal not found');
    }

    /**
     * Makes the link — one journal, one bank account — and gives the journal's history statements their bank account.
     * Used by a person (`manual`, `adopted`) and by the projection (`iban`, `number`, `name`).
     */
    public function attach(SourceJournal $journal, BankAccount $bank, string $by, ?string $username): void
    {
        if ($journal->bank_account_id === $bank->id) {
            return;
        }
        if ($journal->bank_account_id !== null) {
            throw AppError::rule('BANK_JOURNAL_LINKED', 'الدفتر مربوط بحساب بنكي آخر — فكّ الربط أولًا', 'The journal is linked to another bank account — unlink it first');
        }
        $other = SourceJournal::where('bank_account_id', $bank->id)->where('id', '!=', $journal->id)->first();
        if ($other) {
            throw AppError::conflict('BANK_ACCOUNT_LINKED', "الحساب {$bank->code} مربوط بالدفتر «{$other->name}» — الحساب البنكي الواحد يقابل دفترًا واحدًا", "Bank account {$bank->code} is linked to journal “{$other->name}” — one bank account answers to one journal");
        }
        $journal->update(['bank_account_id' => $bank->id, 'linked_by' => $by, 'linked_at' => now(), 'linked_user' => $username]);
        Statement::where('source_journal_id', $journal->id)->update(['bank_account_id' => $bank->id]);
        StatementLine::whereIn('statement_id', Statement::where('source_journal_id', $journal->id)->select('id'))->update(['bank_account_id' => $bank->id]);
        if ($journal->source_system === OdooProjection::SYSTEM && $journal->external_ref !== null) {
            $this->refs->link(OdooProjection::SYSTEM, self::REF_ENTITY, (string) $journal->external_ref, $bank->id, $bank->code, null, $username);
        }
    }

    public function detach(SourceJournal $journal): void
    {
        Statement::where('source_journal_id', $journal->id)->update(['bank_account_id' => null]);
        StatementLine::whereIn('statement_id', Statement::where('source_journal_id', $journal->id)->select('id'))->update(['bank_account_id' => null]);
        if ($journal->source_system === OdooProjection::SYSTEM && $journal->external_ref !== null) {
            IntExternalRef::where('system', OdooProjection::SYSTEM)->where('entity', self::REF_ENTITY)->where('external_id', (string) $journal->external_ref)->where('internal_id', $journal->bank_account_id)->delete();
        }
        $journal->update(['bank_account_id' => null, 'linked_by' => null, 'linked_at' => null, 'linked_user' => null]);
    }
}
