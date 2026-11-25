<?php

namespace App\Banking\Support;

use App\Banking\Models\BankMatch;
use App\Banking\Models\BankMatchItem;
use App\Banking\Models\ImportProfile;
use App\Banking\Models\ImportRun;
use App\Banking\Models\PaymentTerm;
use App\Banking\Models\SourceJournal;
use App\Banking\Models\Statement;
use App\Banking\Models\StatementLine;
use App\Banking\Models\WriteoffRule;
use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The JSON of the section (camelCase, the contract the Vue client reads). Money leaves as a decimal string like every
 * amount of the API, a day as `YYYY-MM-DD`, a moment as ISO-8601. `owned` says the record is Odoo's right now.
 * Account numbers leave in full only for a reader who may see them (`Mask`).
 */
final class Shape
{
    public static function bank(?BankAccount $b, bool $full): ?array
    {
        if (! $b) {
            return null;
        }
        $gl = $b->relationLoaded('account') ? $b->account : null;

        return [
            'id' => $b->id, 'code' => $b->code, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'kind' => $b->kind, 'bankName' => $b->bank_name, 'iban' => Mask::number($b->iban, $full),
            'currency' => $b->currency, 'active' => (bool) $b->active, 'ledgerAccount' => $gl ? self::account($gl) : null,
        ];
    }

    /** The few facts of a bank account a row of a list carries. */
    public static function bankRef(?BankAccount $b): ?array
    {
        return $b ? ['id' => $b->id, 'code' => $b->code, 'nameAr' => $b->name_ar, 'nameEn' => $b->name_en, 'kind' => $b->kind] : null;
    }

    public static function account(?Account $a): ?array
    {
        return $a ? ['id' => $a->id, 'code' => $a->code, 'nameAr' => $a->name_ar, 'nameEn' => $a->name_en] : null;
    }

    /**
     * @param  array{lines:int, in:int, out:int, net:int, matched:int, all:int, opening:?StatementLine|null}|null  $totals  hundredths, from StatementsService::totals()
     */
    public static function statement(Statement $s, ?array $totals = null): array
    {
        $out = [
            'id' => $s->id, 'number' => $s->number, 'name' => $s->name, 'bank' => self::bankRef($s->relationLoaded('bankAccount') ? $s->bankAccount : null),
            'sourceJournal' => $s->relationLoaded('sourceJournal') && $s->sourceJournal ? ['id' => $s->sourceJournal->id, 'code' => $s->sourceJournal->code, 'name' => $s->sourceJournal->name, 'kind' => $s->sourceJournal->kind] : null,
            'dateFrom' => self::day($s->date_from), 'dateTo' => self::day($s->date_to), 'currency' => $s->currency,
            'openingBalance' => Money::out($s->opening_balance), 'closingBalance' => Money::out($s->closing_balance),
            'status' => $s->status, 'history' => $s->status === 'history', 'notes' => $s->notes,
            'reconciledAt' => self::at($s->reconciled_at), 'reconciledBy' => $s->reconciled_by, 'reopenedAt' => self::at($s->reopened_at), 'reopenedBy' => $s->reopened_by, 'reopenReason' => $s->reopen_reason,
            'voidedAt' => self::at($s->voided_at), 'voidedBy' => $s->voided_by, 'voidReason' => $s->void_reason,
            'createdBy' => $s->created_by, 'createdAt' => self::at($s->created_at), 'updatedAt' => self::at($s->updated_at),
        ] + self::origin($s);
        if ($totals !== null) {
            $out['totals'] = self::totals($s, $totals);
        }

        return $out;
    }

    /** opening + lines = closing, and how much of the statement is explained. */
    public static function totals(Statement $s, array $t): array
    {
        $opening = $s->opening_balance === null ? null : Money::cents((string) $s->opening_balance);
        $closing = $s->closing_balance === null ? null : Money::cents((string) $s->closing_balance);
        $computed = $opening === null ? null : $opening + $t['net'];
        $difference = $closing === null || $computed === null ? null : $closing - $computed;

        return [
            'lines' => $t['lines'], 'in' => Money::str($t['in']), 'out' => Money::str($t['out']), 'net' => Money::str($t['net']),
            'computedClosing' => $computed === null ? null : Money::str($computed), 'difference' => $difference === null ? null : Money::str($difference), 'balanced' => $difference === 0,
            'matched' => $t['matched'], 'unmatched' => $t['all'] - $t['matched'], 'hasOpeningLine' => $t['hasOpening'], 'openingMatched' => $t['openingMatched'],
        ];
    }

    public static function line(StatementLine $l, bool $full): array
    {
        $cents = Money::cents((string) $l->amount);
        $match = $l->relationLoaded('match') ? $l->match : null;

        return [
            'id' => $l->id, 'statementId' => $l->statement_id, 'statementNumber' => $l->relationLoaded('statement') ? $l->statement?->number : null, 'lineNo' => $l->line_no, 'kind' => $l->kind,
            'date' => self::day($l->txn_date), 'valueDate' => self::day($l->value_date), 'amount' => Money::str($cents), 'direction' => $cents >= 0 ? 'in' : 'out',
            'balanceAfter' => Money::out($l->balance_after), 'reference' => $l->reference, 'description' => Mask::text($l->description, $full), 'partnerName' => $l->partner_name,
            'partnerAccount' => Mask::number($l->partner_account, $full), 'type' => $l->txn_type, 'imported' => $l->import_id !== null,
            'matched' => $l->match_id !== null,
            'match' => $l->match_id === null ? null : ['id' => $l->match_id, 'number' => $match?->number, 'kind' => $match?->kind, 'clearedDate' => self::day($match?->cleared_date)],
            'sourceState' => $l->source_state, 'sourceDocument' => $l->source_document, 'createdBy' => $l->created_by, 'createdAt' => self::at($l->created_at),
        ] + self::origin($l);
    }

    /**
     * A match with its two sides.
     *
     * @param  array<string, array<string, mixed>>  $ledger  the shaped ledger entries by id (BookItems::byIds), when the caller has them
     * @param  array<string, string>  $journals  journal id → number, for the write-off journal and its reversal
     */
    public static function match(BankMatch $m, bool $full, array $ledger = [], array $journals = []): array
    {
        $statement = [];
        $entries = [];
        foreach ($m->relationLoaded('items') ? $m->items : [] as $item) {
            /** @var BankMatchItem $item */
            $row = ['itemId' => $item->id, 'amount' => Money::out($item->amount), 'date' => self::day($item->item_date), 'label' => $item->label];
            if ($item->side === BankMatchItem::SIDE_STATEMENT) {
                $line = $item->relationLoaded('statementLine') ? $item->getRelation('statementLine') : null;
                $statement[] = $row + ['statementLineId' => $item->statement_line_id, 'line' => $line ? self::line($line, $full) : null];
            } else {
                $entries[] = $row + ['ledgerLineId' => $item->journal_line_id, 'journalId' => $item->journal_id, 'isWriteoff' => (bool) $item->is_writeoff,
                    'document' => ['type' => $item->doc_type, 'id' => $item->doc_id, 'number' => $item->doc_number], 'entry' => $ledger[$item->journal_line_id] ?? null];
            }
        }
        $rule = $m->relationLoaded('rule') ? $m->rule : null;

        return [
            'id' => $m->id, 'number' => $m->number, 'bank' => self::bankRef($m->relationLoaded('bankAccount') ? $m->bankAccount : null), 'status' => $m->status, 'kind' => $m->kind, 'origin' => $m->origin,
            'statementTotal' => Money::out($m->statement_total), 'ledgerTotal' => Money::out($m->ledger_total), 'clearedDate' => self::day($m->cleared_date), 'note' => $m->note,
            'writeOff' => $m->writeoff_journal_id === null ? null : [
                'amount' => Money::out($m->writeoff_amount), 'rule' => $rule ? ['id' => $rule->id, 'nameAr' => $rule->name_ar, 'nameEn' => $rule->name_en] : null,
                'journalId' => $m->writeoff_journal_id, 'journalNumber' => $journals[$m->writeoff_journal_id] ?? null, 'reversalJournalId' => $m->writeoff_reversal_journal_id,
                'reversalJournalNumber' => $m->writeoff_reversal_journal_id ? ($journals[$m->writeoff_reversal_journal_id] ?? null) : null,
            ],
            'statementLines' => $statement, 'ledgerLines' => $entries,
            'createdBy' => $m->created_by, 'createdAt' => self::at($m->created_at), 'undoneBy' => $m->undone_by, 'undoneAt' => self::at($m->undone_at), 'undoReason' => $m->undo_reason,
            'can' => ['undo' => $m->status === 'active' && $m->kind !== 'system'],
        ];
    }

    public static function rule(WriteoffRule $r): array
    {
        $account = $r->relationLoaded('account') ? $r->account : null;
        $usable = self::ruleBlock($r, $account);

        return [
            'id' => $r->id, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'kind' => $r->kind, 'nature' => $r->nature, 'account' => self::account($account), 'label' => $r->label,
            'maxAmount' => Money::out($r->max_amount), 'matchText' => $r->match_text, 'bank' => self::bankRef($r->relationLoaded('bankAccount') ? $r->bankAccount : null), 'sequence' => $r->sequence,
            'active' => (bool) $r->active, 'usable' => $usable === null, 'unusable' => $usable, 'extra' => $r->extra ?: null,
            'createdBy' => $r->created_by, 'createdAt' => self::at($r->created_at), 'updatedAt' => self::at($r->updated_at), 'sourceUpdatedAt' => self::at($r->source_updated_at),
        ] + self::origin($r);
    }

    /** Why a rule cannot write a difference off right now (null = it can). @return array{code:string, ar:string, en:string}|null */
    public static function ruleBlock(WriteoffRule $r, ?Account $account): ?array
    {
        return match (true) {
            $r->kind !== 'writeoff' => ['code' => 'MATCHING_MODEL', 'ar' => 'نموذج مطابقة من النظام المصدر بلا حساب مقابل — لا يرحّل فرقًا', 'en' => 'A matching model of the source system with no counterpart account — it never posts'],
            ! $r->active => ['code' => 'INACTIVE', 'ar' => 'القاعدة موقوفة', 'en' => 'The rule is switched off'],
            $r->account_id === null => ['code' => 'NO_ACCOUNT', 'ar' => 'حدّد حساب الأستاذ الذي يُرحَّل إليه الفرق', 'en' => 'Choose the ledger account the difference is posted to'],
            $account !== null && (! $account->is_postable || ! $account->active) => ['code' => 'ACCOUNT_NOT_POSTABLE', 'ar' => 'حساب القاعدة لا يقبل الترحيل', 'en' => 'The account of the rule is not postable'],
            $r->max_amount === null => ['code' => 'NO_LIMIT', 'ar' => 'حدّد أقصى مبلغ تسوّيه القاعدة', 'en' => 'Set the largest amount the rule may write off'],
            default => null,
        };
    }

    public static function journal(SourceJournal $j, bool $full): array
    {
        return [
            'id' => $j->id, 'code' => $j->code, 'name' => $j->name, 'kind' => $j->kind, 'currency' => $j->currency, 'bankName' => $j->bank_name, 'accountNumber' => Mask::number($j->account_number, $full),
            'ledgerAccount' => $j->ledger_account, 'active' => (bool) $j->active, 'bank' => self::bankRef($j->relationLoaded('bankAccount') ? $j->bankAccount : null), 'linked' => $j->bank_account_id !== null,
            'linkedBy' => $j->linked_by, 'linkedAt' => self::at($j->linked_at), 'linkedUser' => $j->linked_user, 'statements' => (int) ($j->getAttribute('statements_count') ?? 0),
            'lines' => (int) ($j->getAttribute('lines_count') ?? 0), 'sourceUpdatedAt' => self::at($j->source_updated_at), 'sourceDeletedAt' => self::at($j->source_deleted_at),
        ] + self::origin($j);
    }

    public static function profile(ImportProfile $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'bank' => self::bankRef($p->relationLoaded('bankAccount') ? $p->bankAccount : null), 'bankAccountId' => $p->bank_account_id, 'options' => (object) ($p->options ?: []),
            'mapping' => (object) ($p->mapping ?: []), 'isDefault' => (bool) $p->is_default, 'active' => (bool) $p->active, 'createdBy' => $p->created_by, 'updatedBy' => $p->updated_by,
            'createdAt' => self::at($p->created_at), 'updatedAt' => self::at($p->updated_at),
        ];
    }

    public static function import(ImportRun $i): array
    {
        return [
            'id' => $i->id, 'fileName' => $i->file_name, 'format' => $i->format, 'total' => $i->rows_total, 'taken' => $i->rows_taken, 'duplicates' => $i->rows_duplicate, 'refused' => $i->rows_refused,
            'profileId' => $i->profile_id, 'createdBy' => $i->created_by, 'createdAt' => self::at($i->created_at),
        ];
    }

    public static function term(PaymentTerm $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'note' => $t->note, 'sequence' => $t->sequence, 'earlyDiscount' => (bool) $t->early_discount,
            'discountPercent' => $t->discount_percent === null ? null : (string) $t->discount_percent, 'discountDays' => $t->discount_days, 'dueDays' => $t->due_days,
            'schedule' => array_values((array) ($t->schedule ?: [])), 'active' => (bool) $t->active, 'sourceUpdatedAt' => self::at($t->source_updated_at),
        ] + self::origin($t);
    }

    /** @return array{sourceSystem:?string, externalRef:?string, owned:bool} */
    public static function origin(Model $row): array
    {
        return ['sourceSystem' => $row->getAttribute('source_system'), 'externalRef' => $row->getAttribute('external_ref'), 'owned' => Owned::bySource($row)];
    }

    public static function day(?DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    public static function at(?DateTimeInterface $d): ?string
    {
        return $d instanceof Carbon ? $d->toJSON() : $d?->format(DATE_ATOM);
    }
}
