<?php

namespace App\Tax\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Tax\Models\VatReturn;
use App\Tax\Models\VatReturnAdjustment;
use App\Tax\Models\VatReturnEntry;
use App\Tax\Models\VatReturnNote;
use App\Tax\Support\Boxes;
use App\Tax\Support\Money;
use App\Tax\Support\Shape;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The VAT return of a period (a month or a quarter), on the lines of the authority's form.
 *
 * WHERE THE FIGURES COME FROM. `VatSources` reads the journals of the OPS documents posted in the period; each becomes
 * one row of `tax_return_entries` with its base, its VAT (the ledger's own figure) and the line of the form its tax
 * code feeds. A figure of the return is the sum of rows — so every figure opens on the documents behind it, and the
 * sum of what opens IS the figure. What a person adds is an adjustment line with a reason.
 *
 *   column "amount"       invoices, supplier invoices, vouchers
 *   column "adjustment"   credit notes, supplier debit notes, reversals (a cancelled document), adjustment lines
 *   line 13               output VAT − input VAT (a reverse-charge purchase is on both sides)
 *   line 14 corrections   documents booked into a period AFTER its return was filed, and adjustment lines of line 14
 *   line 15               the credit brought forward, typed by a person
 *   line 16               13 + 14 − 15
 *
 * THE LEDGER. The movement of the VAT accounts in the period equals the VAT of the documents plus the movements no
 * document stands behind (manual journals, payments to the authority, opening balances). Each of those is explained by
 * a person — taken into the return with an adjustment line, or named as not part of it — and a return is only reviewed
 * when nothing is left unexplained, to the halala.
 *
 * STATES. draft (figures follow the books) → reviewed (the rows are frozen: the return's own record) → filed
 * (reference and date) → locked. Back: reviewed → draft and filed → reviewed, with a reason. Anything else is refused
 * with VAT_RETURN_TRANSITION.
 */
class VatReturnService
{
    private const ENTRY_COLUMNS = ['entry_key', 'journal_id', 'journal_number', 'entry_date', 'doc_type', 'doc_id', 'doc_number', 'doc_kind', 'reversal', 'late', 'late_of_period', 'notional',
        'side', 'box', 'col', 'base', 'vat', 'doc_vat', 'rate', 'code_id', 'code', 'classified', 'partner_type', 'partner_id', 'partner_name'];

    public function __construct(
        private readonly VatSources $sources,
        private readonly TaxProfileService $profiles,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    // ───────────── periods ─────────────

    /** @return array{code:string, from:Carbon, to:Carbon} */
    public static function period(string $frequency, int $year, int $index): array
    {
        $max = $frequency === 'monthly' ? 12 : 4;
        if (! in_array($frequency, ['monthly', 'quarterly'], true) || $year < 2018 || $year > 2100 || $index < 1 || $index > $max) {
            throw AppError::validation('VAT_PERIOD_INVALID', 'الفترة الضريبية غير صحيحة (شهر 1–12 أو ربع 1–4)', 'Invalid tax period (month 1–12 or quarter 1–4)', [['path' => 'period', 'message' => 'فترة غير صحيحة']]);
        }
        $from = $frequency === 'monthly' ? Carbon::create($year, $index, 1)->startOfDay() : Carbon::create($year, ($index - 1) * 3 + 1, 1)->startOfDay();
        $to = $frequency === 'monthly' ? $from->copy()->endOfMonth()->startOfDay() : $from->copy()->addMonths(2)->endOfMonth()->startOfDay();

        return ['code' => $frequency === 'monthly' ? sprintf('%04d-%02d', $year, $index) : "{$year}-Q{$index}", 'from' => $from, 'to' => $to];
    }

    /** The period a day falls in, and whether a return exists for it — what the "new return" form proposes. */
    public function suggest(): array
    {
        $frequency = $this->profiles->profile()->filing_frequency;
        $last = Carbon::now()->startOfMonth()->subDay(); // the last day of the month before this one
        $index = $frequency === 'monthly' ? $last->month : (int) ceil($last->month / 3);
        if ($frequency === 'quarterly' && $last->month % 3 !== 0) {
            // the quarter that contains last month has not ended: propose the one before it
            $index--;
        }
        $year = $last->year;
        if ($index < 1) {
            $index = 4;
            $year--;
        }
        $p = self::period($frequency, $year, $index);

        return ['frequency' => $frequency, 'year' => $year, 'period' => $index, 'code' => $p['code'], 'from' => $p['from']->toDateString(), 'to' => $p['to']->toDateString(),
            'exists' => VatReturn::where('period_code', $p['code'])->where('status', '!=', 'cancelled')->exists()];
    }

    // ───────────── reading ─────────────

    /** @param  array{status?:?string, year?:?string, frequency?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = VatReturn::query();
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        } else {
            $q->where('status', '!=', 'cancelled');
        }
        if (! empty($f['year'])) {
            $q->whereBetween('period_from', [((int) $f['year']).'-01-01', ((int) $f['year']).'-12-31']);
        }
        if (! empty($f['frequency'])) {
            $q->where('frequency', $f['frequency']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('period_code', 'like', $like)->orWhere('filed_reference', 'like', $like)->orWhere('notes', 'like', $like));
        }
        $column = ['period' => 'period_from', 'netDue' => 'net_due', 'status' => 'status', 'filedOn' => 'filed_on'][$page->sort ?? ''] ?? 'period_from';
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (VatReturn $r) => Shape::vatReturn($r)) + ['suggest' => $this->suggest()];
    }

    /** The whole return: the form, what stands behind it and what may be done with it. A draft is brought up to date first. */
    public function get(string $idOrCode, ?AuthUser $user = null): array
    {
        $r = $this->find($idOrCode);
        if ($r->status === 'draft' && $r->signature !== $this->signature($r)) {
            $r = DB::transaction(fn () => $this->sync($this->find($r->id, true)));
        }

        return $this->present($r, $user);
    }

    /** Brings a draft up to date with the books, whatever its signature says. */
    public function refresh(AuthUser $user, string $idOrCode): array
    {
        return DB::transaction(function () use ($user, $idOrCode) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'refresh');

            return $this->present($this->sync($r), $user);
        });
    }

    /**
     * The documents behind a figure.
     *
     * @param  array{box?:?string, column?:?string, side?:?string, docKind?:?string, classified?:?string, mismatch?:?string, late?:?string}  $f
     */
    public function entries(string $idOrCode, Paging $page, array $f): array
    {
        $r = $this->find($idOrCode);
        $q = $this->entryQuery($r, $f, $page->q);
        $sums = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(base), 0) as base, coalesce(sum(vat), 0) as vat')->first();
        $column = ['date' => 'entry_date', 'number' => 'doc_number', 'base' => 'base', 'vat' => 'vat', 'partner' => 'partner_name', 'box' => 'box'][$page->sort ?? ''] ?? 'entry_date';
        $q->orderBy($column, $page->sort ? $page->order : 'asc')->orderBy('doc_number')->orderBy('id');

        return $page->paginate($q, fn (VatReturnEntry $e) => Shape::entry($e)) + ['totals' => ['count' => (int) $sums->n, 'base' => Money::text(Money::minor($sums->base)), 'vat' => Money::text(Money::minor($sums->vat))],
            'return' => ['id' => $r->id, 'periodCode' => $r->period_code, 'status' => $r->status]];
    }

    /** The same rows as a CSV (UTF-8 with BOM), at most 50,000. */
    public function export(string $idOrCode, array $f): string
    {
        $r = $this->find($idOrCode);
        $out = "\u{FEFF}".implode(',', ['date', 'document', 'kind', 'journal', 'partner', 'side', 'line', 'column', 'tax code', 'rate', 'base', 'vat', 'late of'])."\n";
        foreach ($this->entryQuery($r, $f, null)->orderBy('entry_date')->orderBy('doc_number')->orderBy('id')->limit(50000)->cursor() as $e) {
            $cells = [Shape::day($e->entry_date), $e->doc_number, $e->doc_kind.($e->reversal ? ' (reversal)' : ''), $e->journal_number, $e->partner_name, $e->side, $e->late ? Boxes::CORRECTIONS : $e->box, $e->col, $e->code,
                Money::rate($e->rate), (string) $e->base, (string) $e->vat, $e->late_of_period];
            $out .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) ($c ?? '')).'"', $cells))."\n";
        }

        return $out;
    }

    private function entryQuery(VatReturn $r, array $f, ?string $search)
    {
        $q = VatReturnEntry::query()->where('return_id', $r->id);
        $box = (string) ($f['box'] ?? '');
        if ($box === 'corrections' || $box === (string) Boxes::CORRECTIONS) {
            $q->where('late', true);
        } elseif ($box === 'unclassified') {
            $q->where('late', false)->whereIn('classified', ['none', 'nobox']);
        } elseif ($box === 'outside') {
            $q->where('late', false)->whereNull('box')->whereNotIn('classified', ['none', 'nobox']);
        } elseif ($box === '6' || $box === '12') {
            $q->where('late', false)->whereNotNull('box')->where('side', $box === '6' ? 'sale' : 'purchase');
        } elseif ($box !== '') {
            $q->where('late', false)->where('box', (int) $box);
        }
        if (in_array($f['column'] ?? null, ['amount', 'adjustment'], true)) {
            $q->where('col', $f['column']);
        }
        if (in_array($f['side'] ?? null, ['sale', 'purchase'], true)) {
            $q->where('side', $f['side']);
        }
        if (! empty($f['docKind'])) {
            $q->whereIn('doc_kind', array_map('trim', explode(',', $f['docKind'])));
        }
        if (! empty($f['classified'])) {
            $q->whereIn('classified', array_map('trim', explode(',', $f['classified'])));
        }
        if (filter_var($f['mismatch'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereColumn('vat', '!=', 'doc_vat');
        }
        if (($f['late'] ?? '') !== '' && ($f['late'] ?? null) !== null) {
            $q->where('late', filter_var($f['late'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($search) {
            $like = "%{$search}%";
            $q->where(fn ($w) => $w->where('doc_number', 'like', $like)->orWhere('journal_number', 'like', $like)->orWhere('partner_name', 'like', $like)->orWhere('code', 'like', $like));
        }

        return $q;
    }

    // ───────────── making and changing ─────────────

    /** @param  array{frequency?:?string, year:int|string, period:int|string, notes?:?string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $profile = $this->profiles->profile(true); // one return is made at a time: two people cannot open the same period
            $frequency = (string) (($dto['frequency'] ?? null) ?: $profile->filing_frequency);
            $p = self::period($frequency, (int) $dto['year'], (int) $dto['period']);
            $clash = VatReturn::where('status', '!=', 'cancelled')->where('period_from', '<=', $p['to']->toDateString())->where('period_to', '>=', $p['from']->toDateString())->first();
            if ($clash) {
                throw AppError::conflict('VAT_RETURN_EXISTS', "للفترة {$p['code']} إقرار قائم ({$clash->period_code}) — افتحه بدل إنشاء آخر", "A return already covers this period ({$clash->period_code})");
            }
            $r = VatReturn::create(['period_code' => $p['code'], 'uniq' => $p['code'], 'frequency' => $frequency, 'period_from' => $p['from']->toDateString(), 'period_to' => $p['to']->toDateString(),
                'status' => 'draft', 'notes' => ($dto['notes'] ?? null) ?: null, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'VATRETURN.CREATE', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'newValue' => ['from' => $p['from']->toDateString(), 'to' => $p['to']->toDateString(), 'frequency' => $frequency]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, null, 'draft');

            return $this->present($this->sync($r), $user);
        });
    }

    /** @param  array{box:int|string, base?:mixed, vat?:mixed, reason:string, ledgerLineId?:?string}  $dto */
    public function addAdjustment(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'adjust');
            $box = (int) $dto['box'];
            if (! Boxes::exists($box) && $box !== Boxes::CORRECTIONS) {
                throw AppError::validation('VAT_ADJUSTMENT_BOX', 'سطر التسوية يكون على أحد بنود الإقرار 1–5 و7–11 أو بند التصحيحات 14', 'An adjustment stands on a line 1–5, 7–11 or on the corrections line 14', [['path' => 'box', 'message' => 'بند غير صحيح']]);
            }
            $reason = trim((string) $dto['reason']);
            if (mb_strlen($reason) < 5) {
                throw AppError::validation('REASON_REQUIRED', 'اكتب سبب التسوية (5 أحرف على الأقل)', 'A reason is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
            }
            $base = self::amount($dto['base'] ?? 0, 'base');
            $vat = self::amount($dto['vat'] ?? 0, 'vat');
            if ($base === 0 && $vat === 0) {
                throw AppError::validation('VAT_ADJUSTMENT_EMPTY', 'سطر التسوية يحتاج مبلغًا أو ضريبة', 'An adjustment needs an amount or a VAT figure', [['path' => 'base', 'message' => 'أدخل مبلغًا']]);
            }
            if ($vat !== 0 && $box !== Boxes::CORRECTIONS && ! Boxes::LINES[$box]['vat']) {
                throw AppError::validation('VAT_ADJUSTMENT_NO_VAT', "البند {$box} لا يحمل ضريبة", "Line {$box} carries no VAT", [['path' => 'vat', 'message' => 'هذا البند بلا ضريبة']]);
            }
            $lineId = ($dto['ledgerLineId'] ?? null) ?: null;
            if ($lineId) {
                $line = collect($this->otherLines($r))->firstWhere('journalLineId', $lineId)
                    ?? throw AppError::validation('VAT_LEDGER_LINE', 'هذه ليست حركة غير مفسَّرة على حسابات الضريبة في هذا الإقرار', 'Not an unexplained VAT-account movement of this return', [['path' => 'ledgerLineId', 'message' => 'حركة غير معروفة']]);
                if ($box !== Boxes::CORRECTIONS && Boxes::side($box) !== ($line['side'] === 'output' ? 'sale' : 'purchase')) {
                    throw AppError::validation('VAT_ADJUSTMENT_SIDE', 'حركة على حساب ضريبة المخرجات تُسوّى على بنود المبيعات، وحركة المدخلات على بنود المشتريات (أو بند التصحيحات)',
                        'A movement of the output account is adjusted on a sales line, one of the input account on a purchases line (or on the corrections line)', [['path' => 'box', 'message' => 'جانب غير موافق']]);
                }
            }
            $a = VatReturnAdjustment::create(['return_id' => $r->id, 'box' => $box, 'base' => Money::text($base), 'vat' => Money::text($vat), 'reason' => $reason, 'ledger_line_id' => $lineId, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'VATRETURN.ADJUST', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code,
                'newValue' => ['adjustment' => $a->id, 'box' => $box, 'base' => Money::text($base), 'vat' => Money::text($vat), 'reason' => $reason, 'ledgerLine' => $lineId]]);

            return $this->present($this->sync($r), $user);
        });
    }

    public function voidAdjustment(AuthUser $user, string $idOrCode, string $adjustmentId, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $adjustmentId, $reason) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'adjust');
            $a = VatReturnAdjustment::where('return_id', $r->id)->whereKey($adjustmentId)->lockForUpdate()->first() ?? throw AppError::notFound('VAT_ADJUSTMENT_NOT_FOUND', 'سطر التسوية غير موجود', 'Adjustment not found');
            if ($a->voided_at) {
                throw AppError::rule('VAT_ADJUSTMENT_VOID', 'سطر التسوية ملغى مسبقًا', 'The adjustment is already voided');
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإلغاء (5 أحرف على الأقل)', 'A reason is required');
            }
            $a->update(['voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => trim($reason)]);
            $this->audit->log($user, ['action' => 'VATRETURN.ADJUST.VOID', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code,
                'oldValue' => ['adjustment' => $a->id, 'box' => $a->box, 'base' => (string) $a->base, 'vat' => (string) $a->vat], 'newValue' => ['reason' => trim($reason)]]);

            return $this->present($this->sync($r), $user);
        });
    }

    /** A movement on a VAT account that is no document: a person says what it is. */
    public function explain(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'explain');
            $line = collect($this->otherLines($r))->firstWhere('journalLineId', (string) $dto['journalLineId'])
                ?? throw AppError::notFound('VAT_LEDGER_LINE', 'هذه ليست حركة غير مفسَّرة على حسابات الضريبة في هذا الإقرار', 'Not a VAT-account movement of this return without a document');
            if (! isset(VatReturnNote::TREATMENTS[$dto['treatment'] ?? ''])) {
                throw AppError::validation('VAT_LEDGER_TREATMENT', 'اختر نوع التفسير', 'Pick what the movement is', [['path' => 'treatment', 'message' => 'هذا الحقل إلزامي']]);
            }
            $reason = trim((string) ($dto['reason'] ?? ''));
            if (mb_strlen($reason) < 5) {
                throw AppError::validation('REASON_REQUIRED', 'اكتب تفسير الحركة (5 أحرف على الأقل)', 'An explanation is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
            }
            if (VatReturnNote::where('return_id', $r->id)->where('journal_line_id', $line['journalLineId'])->whereNull('voided_at')->exists()) {
                throw AppError::conflict('VAT_LEDGER_EXPLAINED', 'هذه الحركة مفسَّرة مسبقًا', 'This movement is already explained');
            }
            $n = VatReturnNote::create(['return_id' => $r->id, 'journal_line_id' => $line['journalLineId'], 'treatment' => $dto['treatment'], 'reason' => $reason, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'VATRETURN.EXPLAIN', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code,
                'newValue' => ['note' => $n->id, 'journal' => $line['journal'], 'amount' => Money::text($line['amount']), 'treatment' => $dto['treatment'], 'reason' => $reason]]);

            return $this->present($this->sync($r), $user);
        });
    }

    public function withdrawExplanation(AuthUser $user, string $idOrCode, string $noteId): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $noteId) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'explain');
            $n = VatReturnNote::where('return_id', $r->id)->whereKey($noteId)->whereNull('voided_at')->lockForUpdate()->first() ?? throw AppError::notFound('VAT_LEDGER_NOTE_NOT_FOUND', 'التفسير غير موجود', 'Explanation not found');
            $n->update(['voided_at' => now(), 'voided_by' => $user->username]);
            $this->audit->log($user, ['action' => 'VATRETURN.EXPLAIN.WITHDRAW', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'oldValue' => ['note' => $n->id, 'treatment' => $n->treatment, 'reason' => $n->reason]]);

            return $this->present($this->sync($r), $user);
        });
    }

    /** Line 15 (the credit brought forward) and the notes of the return. */
    public function update(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $r = $this->find($idOrCode, true);
            $this->mustBe($r, ['draft'], 'edit');
            $old = $new = [];
            if (array_key_exists('creditCarried', $dto) && $dto['creditCarried'] !== null) {
                $credit = self::amount($dto['creditCarried'], 'creditCarried');
                if ($credit < 0) {
                    throw AppError::validation('VAT_CREDIT_NEGATIVE', 'الرصيد المرحَّل لا يكون سالبًا', 'The credit brought forward cannot be negative', [['path' => 'creditCarried', 'message' => 'قيمة موجبة']]);
                }
                if ($credit !== Money::minor($r->credit_carried)) {
                    $old['creditCarried'] = (string) $r->credit_carried;
                    $new['credit_carried'] = Money::text($credit);
                }
            }
            if (array_key_exists('notes', $dto)) {
                $notes = trim((string) $dto['notes']) === '' ? null : trim((string) $dto['notes']);
                if ($notes !== $r->notes) {
                    $old['notes'] = $r->notes;
                    $new['notes'] = $notes;
                }
            }
            if ($new) {
                $r->update($new);
                $this->audit->log($user, ['action' => 'VATRETURN.UPDATE', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'oldValue' => $old, 'newValue' => $new]);
            }

            return $this->present($this->sync($r), $user);
        });
    }

    // ───────────── the state machine ─────────────

    /** draft → reviewed: the figures are brought up to date one last time, checked, and frozen. */
    public function review(AuthUser $user, string $idOrCode, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $note) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'reviewed');
            $r = $this->sync($r);
            $blockers = array_values(array_filter($this->checks($r, (array) $r->figures), fn (array $c) => $c['level'] === 'blocker'));
            if ($blockers) {
                throw AppError::rule($blockers[0]['code'], $blockers[0]['ar'], $blockers[0]['en'], ['blockers' => $blockers]);
            }
            $r->update(['status' => 'reviewed', 'reviewed_by' => $user->username, 'reviewed_at' => now()]);
            $this->audit->log($user, ['action' => 'VATRETURN.REVIEW', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code,
                'newValue' => ['outputVat' => (string) $r->output_vat, 'inputVat' => (string) $r->input_vat, 'corrections' => (string) $r->corrections_vat, 'netDue' => (string) $r->net_due, 'note' => $note]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'draft', 'reviewed', $note);
            $this->notify->activity($user, 'VatReturn', $r->id, $r->period_code, "رُوجع إقرار ضريبة القيمة المضافة للفترة {$r->period_code} — صافي المستحق ".number_format((float) $r->net_due, 2).' ر.س، بانتظار التقديم',
                "VAT return {$r->period_code} reviewed — net due {$r->net_due} SAR, awaiting filing", ['gm', 'finance']);

            return $this->present($r->refresh(), $user);
        });
    }

    public function reopen(AuthUser $user, string $idOrCode, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $reason) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'draft');
            $reason = $this->reason($reason);
            $r->update(['status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null]);
            $this->audit->log($user, ['action' => 'VATRETURN.REOPEN', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'newValue' => ['reason' => $reason]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'reviewed', 'draft', $reason);

            return $this->present($this->sync($r), $user);
        });
    }

    /** reviewed → filed, by somebody other than the reviewer: the reference the authority gave and the day it was filed. */
    public function file(AuthUser $user, string $idOrCode, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $dto) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'filed');
            $reference = trim((string) ($dto['reference'] ?? ''));
            if ($reference === '') {
                throw AppError::validation('VAT_RETURN_REFERENCE', 'رقم مرجع التقديم لدى الهيئة إلزامي', 'The authority\'s filing reference is required', [['path' => 'reference', 'message' => 'هذا الحقل إلزامي']]);
            }
            $filedOn = Carbon::parse((string) ($dto['filedOn'] ?? Carbon::today()->toDateString()))->startOfDay();
            if ($filedOn->gt(Carbon::today()) || $filedOn->lte($r->period_to)) {
                throw AppError::validation('VAT_RETURN_FILED_ON', 'تاريخ التقديم بعد نهاية الفترة ولا يتجاوز اليوم', 'The filing date is after the end of the period and not in the future', [['path' => 'filedOn', 'message' => 'تاريخ غير صحيح']]);
            }
            if ($r->reviewed_by === $user->username && ! (bool) $this->settings->get('procurement.allowSelfApproval')) {
                throw AppError::rule('VAT_RETURN_SELF_FILING', "من راجع إقرار {$r->period_code} لا يقدّمه — فصل المسؤوليات؛ يقدّمه شخص آخر بصلاحية التقديم", 'The person who reviewed the return cannot file it');
            }
            // what was reviewed must still be what the books say
            if ($this->driftOf($r)['changed']) {
                throw AppError::rule('VAT_RETURN_STALE', "تغيّرت الدفاتر في الفترة {$r->period_code} بعد المراجعة — أعد الإقرار إلى مسودة وراجعه من جديد", 'The books of the period changed after the review — reopen the return and review it again');
            }
            $r->update(['status' => 'filed', 'filed_reference' => mb_substr($reference, 0, 80), 'filed_on' => $filedOn->toDateString(), 'filed_by' => $user->username, 'filed_at' => now()]);
            $this->audit->log($user, ['action' => 'VATRETURN.FILE', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'newValue' => ['reference' => $reference, 'filedOn' => $filedOn->toDateString(), 'netDue' => (string) $r->net_due]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'reviewed', 'filed', $reference);
            $this->notify->activity($user, 'VatReturn', $r->id, $r->period_code, "قُدِّم إقرار ضريبة القيمة المضافة للفترة {$r->period_code} — مرجع {$reference}", "VAT return {$r->period_code} filed — reference {$reference}", ['finance']);

            return $this->present($r->refresh(), $user);
        });
    }

    public function unfile(AuthUser $user, string $idOrCode, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $reason) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'reviewed');
            $reason = $this->reason($reason);
            $was = ['reference' => $r->filed_reference, 'filedOn' => Shape::day($r->filed_on), 'filedBy' => $r->filed_by];
            $r->update(['status' => 'reviewed', 'filed_reference' => null, 'filed_on' => null, 'filed_by' => null, 'filed_at' => null]);
            $this->audit->log($user, ['action' => 'VATRETURN.UNFILE', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'oldValue' => $was, 'newValue' => ['reason' => $reason]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'filed', 'reviewed', $reason);

            return $this->present($r->refresh(), $user);
        });
    }

    public function lock(AuthUser $user, string $idOrCode): array
    {
        return DB::transaction(function () use ($user, $idOrCode) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'locked');
            $r->update(['status' => 'locked', 'locked_by' => $user->username, 'locked_at' => now()]);
            $this->audit->log($user, ['action' => 'VATRETURN.LOCK', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'newValue' => ['reference' => $r->filed_reference]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'filed', 'locked');

            return $this->present($r->refresh(), $user);
        });
    }

    /** A draft made by mistake: it stays, cancelled, and its period is free again. */
    public function cancel(AuthUser $user, string $idOrCode, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrCode, $reason) {
            $r = $this->find($idOrCode, true);
            $this->move($r, 'cancelled');
            $reason = $this->reason($reason);
            $r->update(['status' => 'cancelled', 'uniq' => "{$r->period_code}:{$r->id}", 'cancelled_by' => $user->username, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->log($user, ['action' => 'VATRETURN.CANCEL', 'entityType' => 'VatReturn', 'entityId' => $r->id, 'entityNumber' => $r->period_code, 'newValue' => ['reason' => $reason]]);
            $this->audit->status($user, 'VatReturn', $r->id, $r->period_code, 'draft', 'cancelled', $reason);

            return $this->present($r->refresh(), $user);
        });
    }

    private function move(VatReturn $r, string $to): void
    {
        if (! in_array($to, VatReturn::TRANSITIONS[$r->status] ?? [], true)) {
            $from = VatReturn::LABELS[$r->status] ?? [$r->status, $r->status];
            $next = VatReturn::LABELS[$to] ?? [$to, $to];
            throw AppError::rule('VAT_RETURN_TRANSITION', "إقرار {$r->period_code} حالته «{$from[0]}» — لا ينتقل منها إلى «{$next[0]}»", "Return {$r->period_code} is {$from[1]}: it cannot become {$next[1]}", ['from' => $r->status, 'to' => $to]);
        }
    }

    private function mustBe(VatReturn $r, array $states, string $what): void
    {
        if (! in_array($r->status, $states, true)) {
            $label = VatReturn::LABELS[$r->status] ?? [$r->status, $r->status];
            throw AppError::rule('VAT_RETURN_FROZEN', "إقرار {$r->period_code} حالته «{$label[0]}» — لا يُعدَّل إلا وهو مسودة", "Return {$r->period_code} is {$label[1]}: it is only changed while it is a draft", ['status' => $r->status, 'action' => $what]);
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب السبب (5 أحرف على الأقل)', 'A reason is required', [['path' => 'reason', 'message' => 'هذا الحقل إلزامي']]);
        }

        return $reason;
    }

    // ───────────── from the books to the return ─────────────

    /** Rebuilds the rows of a draft from the books and recomputes its figures. The caller holds the row lock. */
    private function sync(VatReturn $r): VatReturn
    {
        $from = $r->period_from->toDateString();
        $to = $r->period_to->toDateString();
        $data = $this->sources->collect($from, $to);
        $late = $this->late($r);
        DB::table('tax_return_entries')->where('return_id', $r->id)->delete(); // the rows of a draft are a view of the books, rebuilt
        $rows = [];
        foreach (array_merge($data['entries'], $late['entries']) as $e) {
            $row = ['id' => (string) Str::ulid(), 'return_id' => $r->id];
            foreach (self::ENTRY_COLUMNS as $column) {
                $row[$column] = $e[$column] ?? null;
            }
            $row['late'] = (bool) ($e['late'] ?? false);
            $row['reversal'] = (bool) $e['reversal'];
            $row['notional'] = (bool) $e['notional'];
            foreach (['base', 'vat', 'doc_vat'] as $money) {
                $row[$money] = Money::text((int) $e[$money]);
            }
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('tax_return_entries')->insert($chunk);
        }
        $figures = $this->figures($r, $data['ledger'], $data['other'], $late['other']);
        $r->update([
            'figures' => $figures, 'output_vat' => $figures['totals']['outputVat'], 'input_vat' => $figures['totals']['inputVat'], 'corrections_vat' => $figures['totals']['corrections'],
            'net_due' => $figures['totals']['netDue'], 'ledger_output' => $figures['reconciliation']['output']['ledger'], 'ledger_input' => $figures['reconciliation']['input']['ledger'],
            'unexplained' => $figures['reconciliation']['unexplained'], 'computed_at' => now(),
        ]);
        $r->update(['signature' => $this->signature($r)]);

        return $r->refresh();
    }

    /**
     * What reached an EARLIER period after its return was filed: those journals were declared by nobody, so the first
     * later return takes them — the documents as corrections (line 14), the bare ledger movements as lines to explain.
     *
     * @return array{entries: array<int, array>, other: array<int, array>}
     */
    private function late(VatReturn $r): array
    {
        $out = ['entries' => [], 'other' => []];
        $earlier = VatReturn::whereIn('status', VatReturn::DECLARED)->where('period_to', '<', $r->period_from->toDateString())->whereNotNull('reviewed_at')->orderBy('period_from')->get();
        foreach ($earlier as $e) {
            $data = $this->sources->collect($e->period_from->toDateString(), $e->period_to->toDateString(), $e->reviewed_at->format('Y-m-d H:i:s.v'));
            if ($data['entries']) {
                $keys = array_column($data['entries'], 'entry_key');
                $taken = DB::table('tax_return_entries as x')->join('tax_returns as t', 't.id', '=', 'x.return_id')->whereIn('t.status', VatReturn::FROZEN)->where('t.id', '!=', $r->id)
                    ->whereIn('x.entry_key', $keys)->pluck('x.entry_key')->flip();
                foreach ($data['entries'] as $entry) {
                    if (! isset($taken[$entry['entry_key']])) {
                        $out['entries'][] = ['late' => true, 'late_of_period' => $e->period_code, 'col' => 'adjustment'] + $entry;
                    }
                }
            }
            if ($data['other']) {
                $ids = array_column($data['other'], 'journalLineId');
                $done = DB::table('tax_return_ledger_notes as n')->join('tax_returns as t', 't.id', '=', 'n.return_id')->whereIn('t.status', VatReturn::FROZEN)->where('t.id', '!=', $r->id)->whereNull('n.voided_at')
                    ->whereIn('n.journal_line_id', $ids)->pluck('n.journal_line_id')
                    ->merge(DB::table('tax_return_adjustments as a')->join('tax_returns as t', 't.id', '=', 'a.return_id')->whereIn('t.status', VatReturn::FROZEN)->where('t.id', '!=', $r->id)->whereNull('a.voided_at')
                        ->whereIn('a.ledger_line_id', $ids)->pluck('a.ledger_line_id'))->flip();
                foreach ($data['other'] as $line) {
                    if (! isset($done[$line['journalLineId']])) {
                        $out['other'][] = ['late' => true, 'lateOfPeriod' => $e->period_code] + $line;
                    }
                }
            }
        }

        return $out;
    }

    /** The VAT-account movements of a draft that are no document (of its period, and late ones of earlier periods). */
    private function otherLines(VatReturn $r): array
    {
        $data = $this->sources->collect($r->period_from->toDateString(), $r->period_to->toDateString());

        return array_merge(array_map(fn ($l) => $l + ['late' => false, 'lateOfPeriod' => null], $data['other']), $this->late($r)['other']);
    }

    /**
     * The form, the corrections, and the comparison with the ledger — from the rows of the return, its adjustment lines
     * and its explanations. Every amount is a decimal text.
     */
    private function figures(VatReturn $r, array $ledger, array $other, array $lateOther): array
    {
        $blank = fn () => ['amount' => 0, 'adjustment' => 0, 'vat' => 0, 'count' => 0];
        $lines = array_map($blank, Boxes::LINES);
        $unclassified = $outside = ['count' => 0, 'base' => 0, 'vat' => 0];
        $late = ['count' => 0, 'saleBase' => 0, 'saleVat' => 0, 'purchaseBase' => 0, 'purchaseVat' => 0];
        $docs = ['output' => 0, 'input' => 0];
        $notional = 0;
        $mismatch = 0;
        $sums = DB::table('tax_return_entries')->where('return_id', $r->id)
            ->selectRaw('box, col, late, notional, side, classified, count(*) as n, coalesce(sum(base), 0) as base, coalesce(sum(vat), 0) as vat, sum(case when vat <> doc_vat then 1 else 0 end) as mismatch')
            ->groupBy('box', 'col', 'late', 'notional', 'side', 'classified')->get();
        foreach ($sums as $s) {
            $base = Money::minor($s->base);
            $vat = Money::minor($s->vat);
            $mismatch += (int) $s->mismatch;
            if ($s->late) {
                $late['count'] += (int) $s->n;
                $late[$s->side.'Base'] += $base;
                $late[$s->side.'Vat'] += $vat;

                continue;
            }
            if (! $s->notional) {
                $docs[$s->side === 'sale' ? 'output' : 'input'] += $vat;
            }
            if (in_array($s->classified, ['none', 'nobox'], true)) {
                $unclassified = ['count' => $unclassified['count'] + (int) $s->n, 'base' => $unclassified['base'] + $base, 'vat' => $unclassified['vat'] + $vat];
            } elseif ($s->box === null) {
                $outside = ['count' => $outside['count'] + (int) $s->n, 'base' => $outside['base'] + $base, 'vat' => $outside['vat'] + $vat];
            } else {
                $box = (int) $s->box;
                $lines[$box][$s->col === 'amount' ? 'amount' : 'adjustment'] += $base;
                $lines[$box]['vat'] += $vat;
                $lines[$box]['count'] += (int) $s->n;
                if ($s->notional) {
                    $notional += $vat;
                }
            }
        }
        // what people added
        $adjustments = VatReturnAdjustment::where('return_id', $r->id)->whereNull('voided_at')->orderBy('created_at')->orderBy('id')->get();
        $manualCorrections = 0;
        $free = ['output' => 0, 'input' => 0];
        $covered = [];  // ledger line id → halalas of it an adjustment line takes
        foreach ($adjustments as $a) {
            $base = Money::minor($a->base);
            $vat = Money::minor($a->vat);
            $side = $a->box === Boxes::CORRECTIONS ? null : (Boxes::side($a->box) === 'sale' ? 'output' : 'input');
            if ($a->box === Boxes::CORRECTIONS) {
                $manualCorrections += $vat;
            } else {
                $lines[$a->box]['adjustment'] += $base;
                $lines[$a->box]['vat'] += $vat;
            }
            if ($a->ledger_line_id) {
                $covered[$a->ledger_line_id][] = ['box' => $a->box, 'vat' => $vat];
            } elseif ($side) {
                $free[$side] += $vat;
            }
        }
        $sum = function (array $boxes) use ($lines) {
            $t = ['amount' => 0, 'adjustment' => 0, 'vat' => 0, 'count' => 0];
            foreach ($boxes as $b) {
                foreach ($t as $k => $v) {
                    $t[$k] = $v + $lines[$b][$k];
                }
            }

            return $t;
        };
        $sales = $sum([1, 2, 3, 4, 5]);
        $purchases = $sum([7, 8, 9, 10, 11]);
        $reverseCharge = $lines[9]['vat'];                  // due and deductible at once
        $outputVat = $sales['vat'] + $reverseCharge;
        $inputVat = $purchases['vat'];
        $due = $outputVat - $inputVat;
        $corrections = $late['saleVat'] - $late['purchaseVat'] + $manualCorrections;
        $credit = Money::minor($r->credit_carried);
        $net = $due + $corrections - $credit;

        // ── the ledger: every movement that is no document, and who explained it
        $notes = VatReturnNote::where('return_id', $r->id)->whereNull('voided_at')->get()->keyBy('journal_line_id');
        $side = ['output' => ['outside' => 0, 'corrections' => 0, 'tied' => 0, 'unexplained' => 0], 'input' => ['outside' => 0, 'corrections' => 0, 'tied' => 0, 'unexplained' => 0]];
        $lateUnexplained = 0;
        $items = [];
        $seen = [];
        foreach (array_merge(array_map(fn ($l) => $l + ['late' => false, 'lateOfPeriod' => null], $other), $lateOther) as $l) {
            $amount = (int) $l['amount'];
            $seen[$l['journalLineId']] = true;
            $taken = 0;      // by adjustment lines on a line of the same side
            $asCorrection = 0; // by adjustment lines of line 14 (sign: what it adds to the VAT due)
            foreach ($covered[$l['journalLineId']] ?? [] as $c) {
                if ($c['box'] === Boxes::CORRECTIONS) {
                    $asCorrection += $l['side'] === 'output' ? $c['vat'] : -$c['vat'];
                } else {
                    $taken += $c['vat'];
                }
            }
            $rest = $amount - $taken - $asCorrection;
            $note = $notes->get($l['journalLineId']);
            if (! $l['late']) {
                $side[$l['side']]['tied'] += $taken;
                $side[$l['side']]['corrections'] += $asCorrection;
                $side[$l['side']][$note ? 'outside' : 'unexplained'] += $rest;
            } else {
                // a movement of an earlier period is not in this period's ledger figure: what a line of the form takes of it
                // is, for this period, a figure the books of the period do not hold
                $free[$l['side']] += $taken;
                $lateUnexplained += $note ? 0 : abs($rest);
            }
            $items[] = [
                'journalLineId' => $l['journalLineId'], 'journalId' => $l['journalId'], 'journal' => $l['journal'], 'date' => $l['date'], 'side' => $l['side'], 'account' => $l['account'],
                'accountNameAr' => $l['accountNameAr'], 'accountNameEn' => $l['accountNameEn'], 'debit' => Money::text($l['debit']), 'credit' => Money::text($l['credit']), 'amount' => Money::text($amount),
                'text' => $l['text'], 'sourceType' => $l['sourceType'], 'sourceNumber' => $l['sourceNumber'], 'kind' => $l['kind'], 'late' => (bool) $l['late'], 'lateOfPeriod' => $l['lateOfPeriod'],
                'adjusted' => Money::text($taken + $asCorrection), 'rest' => Money::text($rest), 'explained' => $note !== null || $rest === 0,
                'note' => $note ? Shape::ledgerNote($note) : null,
            ];
        }
        // an adjustment line tied to a movement that is no longer among them counts as one typed freely
        foreach ($covered as $lineId => $parts) {
            if (! isset($seen[$lineId])) {
                foreach ($parts as $c) {
                    if ($c['box'] !== Boxes::CORRECTIONS) {
                        $free[Boxes::side($c['box']) === 'sale' ? 'output' : 'input'] += $c['vat'];
                    }
                }
            }
        }
        $bridge = function (string $s, int $returnVat, int $notInBooks) use ($ledger, $docs, $side, $free) {
            $x = $side[$s];
            $computed = $ledger[$s] - $x['outside'] - $x['corrections'] - $x['unexplained'] + $notInBooks + $free[$s];

            return [
                'ledger' => Money::text($ledger[$s]), 'documents' => Money::text($docs[$s]), 'outside' => Money::text($x['outside']), 'asCorrections' => Money::text($x['corrections']),
                'adjustedFromLedger' => Money::text($x['tied']), 'unexplained' => Money::text($x['unexplained']), 'reverseCharge' => Money::text($notInBooks), 'adjustments' => Money::text($free[$s]),
                'return' => Money::text($returnVat),
                // ledger − outside − corrections − unexplained + reverse charge + free adjustments = the VAT of the return, and
                // ledger = documents + every movement that is no document: both hold, or something is wrong with the figures
                'holds' => $computed === $returnVat && $ledger[$s] === $docs[$s] + $x['outside'] + $x['corrections'] + $x['tied'] + $x['unexplained'],
            ];
        };
        // on the purchases side the reverse-charge VAT is the part of line 9 that is not in the books
        $out = $bridge('output', $outputVat, $reverseCharge);
        $in = $bridge('input', $inputVat, $notional);
        $unexplained = abs($side['output']['unexplained']) + abs($side['input']['unexplained']) + $lateUnexplained;

        $text = fn (array $l) => ['amount' => Money::text($l['amount']), 'adjustment' => Money::text($l['adjustment']), 'total' => Money::text($l['amount'] + $l['adjustment']), 'vat' => Money::text($l['vat']), 'count' => $l['count']];
        $form = [];
        foreach (Boxes::LINES as $no => $def) {
            $form[] = ['box' => $no, 'key' => $def['key'], 'side' => $def['side'], 'ar' => $def['ar'], 'en' => $def['en'], 'hasVat' => $def['vat'], 'sum' => false] + $text($lines[$no]);
        }
        $form[] = ['box' => 6, 'key' => 'total_sales', 'side' => 'sale', 'ar' => Boxes::SUMS[6]['ar'], 'en' => Boxes::SUMS[6]['en'], 'hasVat' => true, 'sum' => true] + $text($sales);
        $form[] = ['box' => 12, 'key' => 'total_purchases', 'side' => 'purchase', 'ar' => Boxes::SUMS[12]['ar'], 'en' => Boxes::SUMS[12]['en'], 'hasVat' => true, 'sum' => true] + $text($purchases);
        usort($form, fn ($a, $b) => $a['box'] <=> $b['box']);

        return [
            'lines' => $form,
            'totals' => [
                'outputVat' => Money::text($outputVat), 'inputVat' => Money::text($inputVat), 'reverseChargeVat' => Money::text($reverseCharge), 'vatDuePeriod' => Money::text($due),
                'corrections' => Money::text($corrections), 'creditCarried' => Money::text($credit), 'netDue' => Money::text($net),
            ],
            'unclassified' => ['count' => $unclassified['count'], 'base' => Money::text($unclassified['base']), 'vat' => Money::text($unclassified['vat'])],
            'outside' => ['count' => $outside['count'], 'base' => Money::text($outside['base']), 'vat' => Money::text($outside['vat'])],
            'corrections' => [
                'late' => ['count' => $late['count'], 'saleBase' => Money::text($late['saleBase']), 'saleVat' => Money::text($late['saleVat']), 'purchaseBase' => Money::text($late['purchaseBase']), 'purchaseVat' => Money::text($late['purchaseVat'])],
                'manual' => Money::text($manualCorrections), 'total' => Money::text($corrections), 'overLimit' => abs($corrections) > Boxes::CORRECTION_LIMIT_MINOR,
            ],
            'reconciliation' => ['output' => $out, 'input' => $in, 'items' => $items, 'unexplained' => Money::text($unexplained), 'balanced' => $unexplained === 0 && $out['holds'] && $in['holds']],
            'mismatch' => $mismatch,
        ];
    }

    /** What stands between a return and its next step, and what a person should look at. */
    private function checks(VatReturn $r, array $figures): array
    {
        $out = [];
        if ($r->period_to->gte(Carbon::today())) {
            $out[] = ['key' => 'periodOpen', 'level' => 'blocker', 'code' => 'VAT_RETURN_PERIOD_OPEN', 'ar' => "الفترة {$r->period_code} لم تنتهِ بعد (تنتهي في {$r->period_to->toDateString()})", 'en' => "Period {$r->period_code} has not ended yet"];
        }
        $n = (int) ($figures['unclassified']['count'] ?? 0);
        if ($n > 0) {
            $out[] = ['key' => 'unclassified', 'level' => 'blocker', 'code' => 'VAT_RETURN_UNCLASSIFIED', 'count' => $n,
                'ar' => "{$n} مستندًا بلا رمز ضريبي يضعه على بند من الإقرار — حدّد رموزها أولًا", 'en' => "{$n} documents have no tax code that puts them on a line of the return — classify them first"];
        }
        if (Money::minor($figures['reconciliation']['unexplained'] ?? '0') !== 0 || ! ($figures['reconciliation']['balanced'] ?? false)) {
            $out[] = ['key' => 'unreconciled', 'level' => 'blocker', 'code' => 'VAT_RETURN_UNRECONCILED', 'amount' => $figures['reconciliation']['unexplained'] ?? '0.00',
                'ar' => 'في حسابات الضريبة حركات بلا مستند لم تُفسَّر ('.($figures['reconciliation']['unexplained'] ?? '0.00').' ر.س) — فسّر كل حركة أو أدخلها بسطر تسوية',
                'en' => 'VAT-account movements without a document are not explained yet ('.($figures['reconciliation']['unexplained'] ?? '0.00').' SAR) — explain each or take it in with an adjustment'];
        }
        if (($figures['mismatch'] ?? 0) > 0) {
            $out[] = ['key' => 'mismatch', 'level' => 'warning', 'code' => 'VAT_RETURN_DOCUMENT_MISMATCH', 'count' => $figures['mismatch'],
                'ar' => "{$figures['mismatch']} مستندًا ضريبته في الدفاتر لا تساوي ضريبته المكتوبة عليه — الإقرار يأخذ رقم الدفاتر", 'en' => "{$figures['mismatch']} documents whose VAT in the books differs from the VAT written on them — the return takes the books' figure"];
        }
        if ($figures['corrections']['overLimit'] ?? false) {
            $out[] = ['key' => 'correctionsLimit', 'level' => 'warning', 'code' => 'VAT_RETURN_CORRECTIONS_LIMIT',
                'ar' => 'تصحيحات الفترات السابقة تتجاوز ±5,000 ريال — ما زاد يُصحَّح بتعديل الإقرار الأصلي لدى الهيئة', 'en' => 'Corrections of earlier periods exceed SAR ±5,000 — beyond that the original return is amended with the authority'];
        }
        if (($figures['outside']['count'] ?? 0) > 0) {
            $out[] = ['key' => 'outside', 'level' => 'info', 'code' => 'VAT_RETURN_OUTSIDE', 'count' => $figures['outside']['count'],
                'ar' => "{$figures['outside']['count']} مستندًا خارج نطاق الإقرار (رمزها بلا بند)", 'en' => "{$figures['outside']['count']} documents are outside the return (their code feeds no line)"];
        }

        return $out;
    }

    /** What changed in the books of a frozen return's period since it was reviewed. */
    private function driftOf(VatReturn $r): array
    {
        if (! $r->frozen() || ! $r->reviewed_at) {
            return ['changed' => false, 'entries' => 0, 'ledgerLines' => 0];
        }
        $data = $this->sources->collect($r->period_from->toDateString(), $r->period_to->toDateString(), $r->reviewed_at->format('Y-m-d H:i:s.v'));
        $keys = array_column($data['entries'], 'entry_key');
        $known = $keys ? DB::table('tax_return_entries')->where('return_id', $r->id)->whereIn('entry_key', $keys)->pluck('entry_key')->flip() : collect();
        $entries = count(array_filter($keys, fn ($k) => ! isset($known[$k])));
        $knownLines = collect((array) ($r->figures['reconciliation']['items'] ?? []))->pluck('journalLineId')->flip();
        $lines = count(array_filter($data['other'], fn ($l) => ! isset($knownLines[$l['journalLineId']])));

        return ['changed' => $entries + $lines > 0, 'entries' => $entries, 'ledgerLines' => $lines];
    }

    /** A cheap fingerprint of everything a draft's figures depend on: when it moves, the draft is rebuilt. */
    private function signature(VatReturn $r): string
    {
        $from = $r->period_from->toDateString();
        $to = $r->period_to->toDateString();
        $stamp = fn (string $table, string $column, ?callable $where = null) => (array) DB::table($table)->when($where, $where)->selectRaw("count(*) as n, max({$column}) as m")->first();
        $parts = [
            (array) DB::table('fin_journals')->where('status', '!=', 'draft')->whereBetween('journal_date', [$from, $to])->selectRaw('count(*) as n, max(created_at) as m, coalesce(sum(total_debit), 0) as s')->first(),
            (array) DB::table('fin_journals')->where('status', '!=', 'draft')->where('journal_date', '<', $from)->selectRaw('count(*) as n, max(created_at) as m')->first(),
            $stamp('fin_debit_notes', 'created_at', fn ($q) => $q->whereNull('journal_id')),
            $stamp('tax_codes', 'updated_at'), $stamp('tax_document_codes', 'set_at'), $stamp('tax_parties', 'updated_at'), $stamp('tax_fiscal_position_lines', 'created_at'), $stamp('tax_fiscal_positions', 'updated_at'),
            (array) DB::table('tax_returns')->where('id', '!=', $r->id)->selectRaw('count(*) as n, max(updated_at) as m')->first(),
            $stamp('tax_return_adjustments', 'created_at', fn ($q) => $q->where('return_id', $r->id)), $stamp('tax_return_adjustments', 'voided_at', fn ($q) => $q->where('return_id', $r->id)),
            $stamp('tax_return_ledger_notes', 'created_at', fn ($q) => $q->where('return_id', $r->id)), $stamp('tax_return_ledger_notes', 'voided_at', fn ($q) => $q->where('return_id', $r->id)),
            [(string) $r->credit_carried, $this->settings->get('sales.vatPct'), (bool) $this->settings->get('finance.fleetExpensesIncludeVat'), DB::table('fin_accounts')->where('subtype', 'tax')->count()],
        ];

        return hash('sha256', json_encode($parts));
    }

    private function present(VatReturn $r, ?AuthUser $user): array
    {
        $figures = (array) $r->figures;
        $checks = $r->status === 'cancelled' ? [] : $this->checks($r, $figures);
        $blocked = array_values(array_filter($checks, fn ($c) => $c['level'] === 'blocker'));
        $drift = $this->driftOf($r);
        if ($drift['changed']) {
            $checks[] = ['key' => 'drift', 'level' => $r->status === 'reviewed' ? 'blocker' : 'warning', 'code' => 'VAT_RETURN_STALE', 'count' => $drift['entries'] + $drift['ledgerLines'],
                'ar' => $r->status === 'reviewed' ? 'تغيّرت الدفاتر في هذه الفترة بعد المراجعة — أعده إلى مسودة وراجعه من جديد' : 'قُيّدت مستندات في هذه الفترة بعد تقديم الإقرار — تدخل تصحيحاتٍ في الإقرار التالي',
                'en' => $r->status === 'reviewed' ? 'The books of this period changed after the review — reopen and review again' : 'Documents were booked into this period after the return was filed — they enter the next return as corrections'];
        }
        $can = fn (string $permission) => $user === null || $user->can($permission);
        $selfFiling = $user !== null && $r->reviewed_by === $user->username && ! (bool) $this->settings->get('procurement.allowSelfApproval');

        return Shape::vatReturn($r) + $figures + [
            'adjustments' => $r->adjustments()->orderBy('created_at')->orderBy('id')->get()->map(fn (VatReturnAdjustment $a) => Shape::adjustment($a))->all(),
            'checks' => $checks,
            'drift' => $drift,
            'can' => [
                'edit' => $r->status === 'draft' && $can('vatreturn.manage'),
                'review' => $r->status === 'draft' && ! $blocked && $can('vatreturn.manage'),
                'reopen' => $r->status === 'reviewed' && $can('vatreturn.manage'),
                'file' => $r->status === 'reviewed' && ! $drift['changed'] && ! $selfFiling && $can('vatreturn.file'),
                'unfile' => $r->status === 'filed' && $can('vatreturn.file'),
                'lock' => $r->status === 'filed' && $can('vatreturn.file'),
                'cancel' => $r->status === 'draft' && $can('vatreturn.manage'),
            ],
            'selfFiling' => $r->status === 'reviewed' && $selfFiling,
        ];
    }

    private function find(string $idOrCode, bool $lock = false): VatReturn
    {
        // by id, or by the period people name it by (the live return of that period)
        return VatReturn::query()->where(fn ($w) => $w->where('id', $idOrCode)->orWhere('uniq', $idOrCode))->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('VAT_RETURN_NOT_FOUND', "إقرار الفترة {$idOrCode} غير موجود", "VAT return {$idOrCode} not found");
    }

    /** A typed amount → halalas. Two decimals at most: a third is a typing error, not something to round away. */
    private static function amount(mixed $v, string $path): int
    {
        $s = is_string($v) ? trim($v) : (is_int($v) || is_float($v) ? (string) $v : '');
        if ($s === '') {
            return 0;
        }
        if (! preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $s)) {
            throw AppError::validation('AMOUNT_INVALID', 'المبلغ رقم بخانتين عشريتين على الأكثر', 'The amount is a number with at most two decimals', [['path' => $path, 'message' => 'مبلغ غير صحيح']]);
        }

        return Money::minor($s);
    }
}
