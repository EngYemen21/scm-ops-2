<?php

namespace App\Finance\Services;

use App\Finance\Models\Account;
use App\Finance\Models\CostCenter;
use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The double-entry engine. Every journal:
 *  - is written inside the caller's database transaction (same atomicity as the operational document),
 *  - must balance to the cent and name a postable account on every line,
 *  - needs an OPEN fiscal period for its date,
 *  - is idempotent per accounting event (`event_key` unique: a retry returns the journal already posted),
 *  - carries its source document and analysis dimensions,
 *  - is immutable once posted — corrections are reversal journals linked both ways.
 * Manual journals go draft → approved by someone else (maker-checker) → posted.
 */
class LedgerService
{
    public const CONTROL_SUBTYPES = ['receivable' => 'customer', 'payable' => 'supplier'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly PeriodService $periods,
        private readonly AccountMap $map,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
    ) {}

    // ───────────────────────────── posting ─────────────────────────────

    /**
     * Post an automatic journal for an accounting event.
     *
     * @param  array{
     *   date?: CarbonInterface|string|null, kind?: string, memo: string, eventKey?: ?string,
     *   source?: array{type: string, id?: ?string, number?: ?string},
     *   lines: array<int, array{role?: string, account?: string, debit?: float|int|string, credit?: float|int|string, description?: ?string,
     *     costCenterId?: ?string, costCenter?: ?string, partnerType?: ?string, partnerId?: ?string, productId?: ?string, warehouseId?: ?string,
     *     vehicleId?: ?string, tripId?: ?string, referenceType?: ?string, referenceId?: ?string}>
     * } $e
     */
    public function post(?AuthUser $user, array $e): Journal
    {
        $this->requireTransaction();
        $eventKey = $e['eventKey'] ?? null;
        if ($eventKey) {
            $existing = Journal::where('event_key', $eventKey)->first();
            if ($existing) {
                return $existing->load('lines'); // idempotent: the event was already posted
            }
        }
        $date = $this->date($e['date'] ?? null);
        $period = $this->periods->openFor($date);
        $lines = $this->normalizeLines($e['lines']);
        if (! $lines) {
            throw AppError::rule('JOURNAL_EMPTY', 'القيد بلا سطور ذات قيمة', 'Journal has no non-zero lines');
        }
        [$debit, $credit] = $this->totals($lines);

        $journal = Journal::create([
            'number' => $this->numbering->next('JV'), 'journal_date' => $date->toDateString(), 'period_id' => $period->id, 'status' => 'posted', 'kind' => $e['kind'] ?? 'auto',
            'source_type' => $e['source']['type'] ?? null, 'source_id' => $e['source']['id'] ?? null, 'source_number' => $e['source']['number'] ?? null,
            'event_key' => $eventKey, 'memo' => mb_substr((string) $e['memo'], 0, 500), 'total_debit' => $debit, 'total_credit' => $credit,
            'created_by_id' => $user?->id, 'created_by' => $user?->username ?? 'system', 'posted_at' => now(),
        ]);
        $this->writeLines($journal, $lines);

        return $journal->load('lines');
    }

    /** Reverse a posted journal (all lines swapped) on the given date; both journals link to each other. */
    public function reverse(AuthUser $user, string $idOrNumber, string $reason, CarbonInterface|string|null $date = null): Journal
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب العكس (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $idOrNumber, $reason, $date) {
            $j = $this->lock($idOrNumber);
            if ($j->status !== 'posted') {
                throw AppError::rule('JOURNAL_STATE', "القيد {$j->number} ليس مرحَّلًا (الحالة: {$j->status})", "Journal {$j->number} is not posted");
            }
            $d = $this->date($date);
            $period = $this->periods->openFor($d);
            $rev = Journal::create([
                'number' => $this->numbering->next('JV'), 'journal_date' => $d->toDateString(), 'period_id' => $period->id, 'status' => 'posted', 'kind' => 'reversal',
                'source_type' => $j->source_type, 'source_id' => $j->source_id, 'source_number' => $j->source_number, 'event_key' => "reversal:{$j->id}",
                'memo' => mb_substr("عكس {$j->number}: {$reason}", 0, 500), 'total_debit' => $j->total_credit, 'total_credit' => $j->total_debit,
                'created_by_id' => $user->id, 'created_by' => $user->username, 'posted_at' => now(), 'reversal_of_id' => $j->id, 'reversal_reason' => $reason,
            ]);
            foreach ($j->lines()->orderBy('line_no')->get() as $l) {
                JournalLine::create(['journal_id' => $rev->id, 'line_no' => $l->line_no, 'account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit,
                    'description' => $l->description, 'cost_center_id' => $l->cost_center_id, 'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id,
                    'product_id' => $l->product_id, 'warehouse_id' => $l->warehouse_id, 'vehicle_id' => $l->vehicle_id, 'trip_id' => $l->trip_id,
                    'reference_type' => $l->reference_type, 'reference_id' => $l->reference_id]);
            }
            $j->update(['status' => 'reversed', 'reversed_by_id' => $rev->id, 'reversal_reason' => $reason]);
            $this->audit->log($user, ['action' => 'JOURNAL.REVERSE', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number, 'newValue' => ['reversal' => $rev->number, 'reason' => $reason]]);

            return $rev->load('lines');
        });
    }

    // ───────────────────────────── manual journals (maker-checker) ─────────────────────────────

    /** @param  array{date: string, memo: string, lines: array}  $dto */
    public function createDraft(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $date = $this->date($dto['date'] ?? null);
            $period = $this->periods->openFor($date);
            $lines = $this->normalizeLines($dto['lines']);
            if (count($lines) < 2) {
                throw AppError::rule('JOURNAL_LINES', 'القيد يحتاج سطرين على الأقل', 'A journal needs at least two lines');
            }
            [$debit, $credit] = $this->totals($lines);
            $j = Journal::create([
                'number' => $this->numbering->next('JV'), 'journal_date' => $date->toDateString(), 'period_id' => $period->id, 'status' => 'draft', 'kind' => 'manual',
                'memo' => mb_substr(trim((string) $dto['memo']), 0, 500), 'total_debit' => $debit, 'total_credit' => $credit, 'created_by_id' => $user->id, 'created_by' => $user->username,
            ]);
            $this->writeLines($j, $lines);
            $this->audit->log($user, ['action' => 'JOURNAL.DRAFT', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number, 'newValue' => ['debit' => $debit, 'lines' => count($lines)]]);

            return $this->get($j->id);
        });
    }

    public function updateDraft(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $j = $this->lock($idOrNumber);
            if ($j->status !== 'draft') {
                throw AppError::rule('JOURNAL_STATE', "القيد {$j->number} مرحَّل — التعديل بقيد عكسي فقط", 'Posted journals are immutable');
            }
            $date = $this->date($dto['date'] ?? $j->journal_date);
            $period = $this->periods->openFor($date);
            $lines = $this->normalizeLines($dto['lines']);
            if (count($lines) < 2) {
                throw AppError::rule('JOURNAL_LINES', 'القيد يحتاج سطرين على الأقل', 'A journal needs at least two lines');
            }
            [$debit, $credit] = $this->totals($lines);
            $j->lines()->delete();
            $j->update(['journal_date' => $date->toDateString(), 'period_id' => $period->id, 'memo' => mb_substr(trim((string) ($dto['memo'] ?? $j->memo)), 0, 500), 'total_debit' => $debit, 'total_credit' => $credit]);
            $this->writeLines($j, $lines);
            $this->audit->log($user, ['action' => 'JOURNAL.EDIT', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number, 'newValue' => ['debit' => $debit, 'lines' => count($lines)]]);

            return $this->get($j->id);
        });
    }

    public function deleteDraft(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $j = $this->lock($idOrNumber);
            if ($j->status !== 'draft') {
                throw AppError::rule('JOURNAL_STATE', 'لا يُحذف إلا القيد غير المعتمد', 'Only drafts can be deleted');
            }
            $j->lines()->delete();
            $j->delete();
            $this->audit->log($user, ['action' => 'JOURNAL.DISCARD', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number]);

            return ['ok' => true, 'messageAr' => "حُذفت مسودة {$j->number}", 'messageEn' => "Draft {$j->number} deleted"];
        });
    }

    /** Approve + post a draft: the approver must differ from the maker (procurement.allowSelfApproval switches it off, audited). */
    public function approve(AuthUser $user, string $idOrNumber, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $j = $this->lock($idOrNumber);
            if ($j->status !== 'draft') {
                throw AppError::rule('JOURNAL_STATE', "القيد {$j->number} ليس بانتظار الاعتماد", 'Journal is not a draft');
            }
            if ($j->created_by_id === $user->id && ! (bool) $this->settings->get('procurement.allowSelfApproval')) {
                throw AppError::rule('JOURNAL_SELF_APPROVAL', "لا يعتمد صانع القيد {$j->number} قيده — فصل المسؤوليات؛ يعتمده شخص آخر بصلاحية الاعتماد", 'The maker cannot approve their own journal');
            }
            $period = $this->periods->openFor(Carbon::parse($j->journal_date));
            [$debit, $credit] = $this->totals($j->lines->map(fn ($l) => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit])->all());
            $j->update(['status' => 'posted', 'period_id' => $period->id, 'approved_by_id' => $user->id, 'approved_by' => $user->username, 'approved_at' => now(), 'posted_at' => now(), 'total_debit' => $debit, 'total_credit' => $credit]);
            $this->audit->log($user, ['action' => 'JOURNAL.APPROVE', 'entityType' => 'Journal', 'entityId' => $j->id, 'entityNumber' => $j->number, 'newValue' => ['note' => $note]]);
            $this->audit->status($user, 'Journal', $j->id, $j->number, 'draft', 'posted', $note);

            return $this->get($j->id);
        });
    }

    // ───────────────────────────── reads ─────────────────────────────

    public function get(string $idOrNumber): array
    {
        $j = Journal::with(['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.account:id,code,name_ar,name_en,type', 'lines.costCenter:id,code,name_ar,name_en', 'period:id,code,status', 'reversalOf:id,number', 'reversedBy:id,number'])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('JOURNAL_NOT_FOUND', "القيد {$idOrNumber} غير موجود", "Journal {$idOrNumber} not found");
        $row = $j->toArray();
        $row['lines'] = $this->withPartnerNames($row['lines']);

        return $row;
    }

    /** @param  array{status?:?string, kind?:?string, period?:?string, from?:?string, to?:?string, account?:?string, sourceType?:?string, sourceNumber?:?string, partnerType?:?string, partnerId?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = Journal::query()->with('period:id,code,status')->withCount('lines');
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['kind'])) {
            $q->whereIn('kind', array_map('trim', explode(',', $f['kind'])));
        }
        if (! empty($f['period'])) {
            $q->whereHas('period', fn ($p) => $p->where('code', $f['period']));
        }
        if (! empty($f['from'])) {
            $q->whereDate('journal_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('journal_date', '<=', $f['to']);
        }
        if (! empty($f['sourceType'])) {
            $q->where('source_type', $f['sourceType']);
        }
        if (! empty($f['sourceNumber'])) {
            $q->where('source_number', $f['sourceNumber']);
        }
        if (! empty($f['account'])) {
            $acct = $this->map->code($f['account']);
            $q->whereHas('lines', fn ($l) => $l->where('account_id', $acct->id));
        }
        if (! empty($f['partnerType']) && ! empty($f['partnerId'])) {
            $q->whereHas('lines', fn ($l) => $l->where('partner_type', $f['partnerType'])->where('partner_id', $f['partnerId']));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('memo', 'like', $like)->orWhere('source_number', 'like', $like));
        }
        $column = match ($page->sort) {
            'date' => 'journal_date', 'debit' => 'total_debit', default => 'created_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, function (Journal $j) {
            $row = $j->toArray();
            $row['_count'] = ['lines' => (int) $j->lines_count];
            unset($row['linesCount']);

            return $row;
        });
    }

    /** Journals posted for one source document (deep link from the document page). */
    public function forSource(string $type, string $id): array
    {
        return Journal::where('source_type', $type)->where('source_id', $id)->orderBy('created_at')->get(['id', 'number', 'journal_date', 'status', 'kind', 'memo', 'total_debit', 'total_credit'])->map->toArray()->all();
    }

    // ───────────────────────────── internals ─────────────────────────────

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('LedgerService::post() must run inside a database transaction (together with the operational document).');
        }
    }

    private function date(CarbonInterface|string|null $d): Carbon
    {
        if ($d === null || $d === '') {
            return Carbon::now()->startOfDay();
        }

        return $d instanceof CarbonInterface ? Carbon::instance($d)->startOfDay() : Carbon::parse($d)->startOfDay();
    }

    private function lock(string $idOrNumber): Journal
    {
        return Journal::with('lines')->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('JOURNAL_NOT_FOUND', "القيد {$idOrNumber} غير موجود", "Journal {$idOrNumber} not found");
    }

    /** Resolve accounts / cost centers, round to cents, drop zero lines, enforce control-account partners. */
    private function normalizeLines(array $lines): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $debit = round((float) ($l['debit'] ?? 0), 2);
            $credit = round((float) ($l['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) {
                throw AppError::validation('JOURNAL_NEGATIVE', 'المبالغ لا تكون سالبة — استخدم الجانب الآخر', 'Amounts cannot be negative');
            }
            if ($debit > 0 && $credit > 0) {
                throw AppError::validation('JOURNAL_BOTH_SIDES', 'السطر لا يكون مدينًا ودائنًا معًا', 'A line is either debit or credit');
            }
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }
            $account = isset($l['role']) ? $this->map->role($l['role']) : $this->map->code((string) ($l['account'] ?? ''));
            if (! $account->is_postable || ! $account->active) {
                throw AppError::rule('ACCOUNT_NOT_POSTABLE', "الحساب {$account->code} — {$account->name_ar} لا يقبل الترحيل", "Account {$account->code} is not postable");
            }
            $partnerType = $l['partnerType'] ?? null;
            $partnerId = $l['partnerId'] ?? null;
            $needs = self::CONTROL_SUBTYPES[$account->subtype] ?? null;
            if ($needs && (! $partnerId || $partnerType !== $needs)) {
                throw AppError::rule('PARTNER_REQUIRED', "الحساب {$account->code} حساب تحكم ({$account->name_ar}) — السطر يحتاج ".($needs === 'customer' ? 'عميلًا' : 'مورّدًا'), "Control account {$account->code} needs a {$needs}");
            }
            $cc = null;
            if (! empty($l['costCenterId'])) {
                $cc = $l['costCenterId'];
            } elseif (! empty($l['costCenter'])) {
                $cc = (CostCenter::where('code', $l['costCenter'])->orWhere('id', $l['costCenter'])->first() ?? throw AppError::notFound('COST_CENTER_NOT_FOUND', "مركز التكلفة {$l['costCenter']} غير موجود", 'Cost center not found'))->id;
            }
            $out[] = [
                'line_no' => count($out) + 1, 'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => isset($l['description']) ? mb_substr((string) $l['description'], 0, 500) : null,
                'cost_center_id' => $cc, 'partner_type' => $partnerType, 'partner_id' => $partnerId, 'product_id' => $l['productId'] ?? null, 'warehouse_id' => $l['warehouseId'] ?? null,
                'vehicle_id' => $l['vehicleId'] ?? null, 'trip_id' => $l['tripId'] ?? null, 'reference_type' => $l['referenceType'] ?? null, 'reference_id' => $l['referenceId'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array{0: float, 1: float} */
    private function totals(array $lines): array
    {
        $debit = round(array_sum(array_map(fn ($l) => (float) $l['debit'], $lines)), 2);
        $credit = round(array_sum(array_map(fn ($l) => (float) $l['credit'], $lines)), 2);
        if (abs($debit - $credit) > 0.005) {
            throw AppError::rule('JOURNAL_UNBALANCED', 'القيد غير متوازن: مدين '.number_format($debit, 2).' ≠ دائن '.number_format($credit, 2), "Journal is unbalanced: debit {$debit} ≠ credit {$credit}", ['debit' => $debit, 'credit' => $credit]);
        }

        return [$debit, $credit];
    }

    private function writeLines(Journal $journal, array $lines): void
    {
        foreach ($lines as $l) {
            JournalLine::create(['journal_id' => $journal->id] + $l);
        }
    }

    /** Adds partner (customer / supplier) code + name to lines that carry one. */
    public function withPartnerNames(array $lines): array
    {
        $cust = collect($lines)->where('partnerType', 'customer')->pluck('partnerId')->filter()->unique()->all();
        $sup = collect($lines)->where('partnerType', 'supplier')->pluck('partnerId')->filter()->unique()->all();
        $customers = $cust ? Customer::whereIn('id', $cust)->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id') : collect();
        $suppliers = $sup ? Supplier::whereIn('id', $sup)->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id') : collect();
        foreach ($lines as $i => $l) {
            $p = ($l['partnerType'] ?? null) === 'customer' ? ($customers[$l['partnerId']] ?? null) : (($l['partnerType'] ?? null) === 'supplier' ? ($suppliers[$l['partnerId']] ?? null) : null);
            $lines[$i]['partner'] = $p ? ['code' => $p->code, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en] : null;
        }

        return $lines;
    }

    /** Balance helpers used by reports: signed by the account's normal side. */
    public static function natural(Account|array $account, float $debit, float $credit): float
    {
        $type = is_array($account) ? $account['type'] : $account->type;

        return round(in_array($type, Account::DEBIT_NORMAL, true) ? $debit - $credit : $credit - $debit, 2);
    }
}
