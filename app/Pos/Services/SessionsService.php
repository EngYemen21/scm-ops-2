<?php

namespace App\Pos\Services;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Finance\Services\AccountMap;
use App\Finance\Services\LedgerService;
use App\Pos\Models\PosCashMove;
use App\Pos\Models\PosDenomination;
use App\Pos\Models\PosHeldSale;
use App\Pos\Models\PosOrder;
use App\Pos\Models\PosOrderPayment;
use App\Pos\Models\PosPaymentMethod;
use App\Pos\Models\PosRegister;
use App\Pos\Models\PosSession;
use App\Pos\Support\Money;
use App\Pos\Support\Owned;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sessions: a cashier opens a register with the float he counted, sells, puts cash in or takes it out, and closes it
 * with the cash counted by denomination. The difference between what is counted and what should be there is shown,
 * must be explained when it is not zero, and is posted (Dr / Cr the cash account against the register's difference
 * account). open → closed only; a closed session refuses every change and keeps its end-of-day report as it was at
 * closing. One open session per register and one per cashier — enforced by unique keys, so two people opening at the
 * same moment cannot both succeed.
 */
class SessionsService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly LedgerService $ledger,
        private readonly AccountMap $map,
        private readonly ReportService $reports,
    ) {}

    // ───────────── open ─────────────

    /** @param  array{register:string, counts?:?array, openingCash?:mixed, note?:?string}  $dto */
    public function open(AuthUser $user, array $dto): array
    {
        $register = PosRegister::with(['methods.bankAccount', 'warehouse:id,code,active', 'customer:id,code,active'])->where(fn ($w) => $w->where('id', $dto['register'])->orWhere('code', $dto['register']))->first()
            ?? throw AppError::notFound('POS_REGISTER_NOT_FOUND', 'الصندوق غير موجود', 'Register not found');
        Owned::refuse($register, 'register');
        if (! $register->active) {
            throw AppError::rule('POS_REGISTER_INACTIVE', "الصندوق {$register->code} موقوف", "Register {$register->code} is inactive");
        }
        if ($missing = self::notReady($register)) {
            throw AppError::rule('POS_REGISTER_NOT_READY', 'الصندوق غير جاهز للبيع: '.implode('، ', array_column($missing, 'ar')), 'The register is not ready: '.implode(', ', array_column($missing, 'en')), ['missing' => array_column($missing, 'key')]);
        }
        if ($user->warehouses && ! in_array($register->warehouse_id, $user->warehouses, true)) {
            throw AppError::forbidden('POS_WAREHOUSE_SCOPE', 'الصندوق في مستودع خارج نطاقك', "The register's warehouse is outside your scope");
        }
        [$counts, $opening] = $this->counted($dto['counts'] ?? null, $dto['openingCash'] ?? null);
        $this->assertNoOpen($user, $register);
        try {
            $session = DB::transaction(function () use ($user, $register, $counts, $opening, $dto) {
                $s = PosSession::create([
                    'number' => $this->numbering->next('PSS'), 'register_id' => $register->id, 'cashier_id' => $user->id, 'cashier_name' => $user->nameAr, 'state' => 'open',
                    'opened_at' => now(), 'opening_cash' => Money::str($opening), 'opening_counts' => $counts, 'opening_note' => ($dto['note'] ?? null) ?: null,
                    'open_register_key' => $register->id, 'open_cashier_key' => $user->id, 'created_by' => $user->username,
                ]);
                $this->audit->log($user, ['action' => 'POS.SESSION.OPEN', 'entityType' => 'PosSession', 'entityId' => $s->id, 'entityNumber' => $s->number,
                    'newValue' => ['register' => $register->code, 'openingCash' => $s->opening_cash]]);
                $this->notify->activity($user, 'PosSession', $s->id, $s->number, "فُتحت جلسة {$s->number} على الصندوق {$register->name_ar} بنقد افتتاحي ".Money::str($opening).' ر.س',
                    "Session {$s->number} opened on {$register->name_en}");

                return $s;
            });
        } catch (UniqueConstraintViolationException) {
            $this->assertNoOpen($user, $register); // the twin that won names itself
            throw AppError::conflict('POS_SESSION_OPEN', 'جلسة مفتوحة بالفعل', 'A session is already open');
        }

        return $this->get($session->id);
    }

    /** What a register still lacks before it can sell. @return array<int, array{key:string, ar:string, en:string}> */
    public static function notReady(PosRegister $register): array
    {
        $out = [];
        if (! $register->warehouse_id || ! ($register->warehouse?->active ?? false)) {
            $out[] = ['key' => 'warehouse', 'ar' => 'مستودع نشط', 'en' => 'an active warehouse'];
        }
        if (! $register->default_customer_id || ! ($register->customer?->active ?? false)) {
            $out[] = ['key' => 'customer', 'ar' => 'عميل افتراضي نشط', 'en' => 'an active default customer'];
        }
        $usable = $register->methods->filter(fn (PosPaymentMethod $m) => $m->active && $m->kind !== 'account' && ($m->bankAccount?->active ?? false));
        if ($usable->isEmpty()) {
            $out[] = ['key' => 'methods', 'ar' => 'طريقة دفع واحدة على الأقل مربوطة بحساب في المالية', 'en' => 'at least one payment method bound to a finance account'];
        }

        return $out;
    }

    private function assertNoOpen(AuthUser $user, PosRegister $register): void
    {
        if ($s = PosSession::where('open_register_key', $register->id)->first(['number', 'cashier_name'])) {
            throw AppError::conflict('POS_SESSION_OPEN', "على الصندوق {$register->code} جلسة مفتوحة ({$s->number} — {$s->cashier_name}) — جلسة واحدة لكل صندوق", "Register {$register->code} already has an open session ({$s->number})", ['session' => $s->number]);
        }
        if ($s = PosSession::where('open_cashier_key', $user->id)->first(['number'])) {
            throw AppError::conflict('POS_CASHIER_BUSY', "لديك جلسة مفتوحة ({$s->number}) — أغلقها قبل فتح أخرى", "You already have an open session ({$s->number})", ['session' => $s->number]);
        }
    }

    // ───────────── cash in / out ─────────────

    /** @param  array{direction:string, amount:mixed, reason:string, bankAccount:string}  $dto */
    public function cashMove(AuthUser $user, string $id, array $dto): array
    {
        $reason = trim((string) $dto['reason']);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الحركة النقدية (5 أحرف على الأقل)', 'A reason is required');
        }
        DB::transaction(function () use ($user, $id, $dto, $reason) {
            $s = $this->lock($id);
            SaleService::assertSells($user, $s);
            $cash = $this->cashMethod($s);
            $amount = Money::cents($dto['amount']);
            if ($amount <= 0) {
                throw AppError::validation('POS_AMOUNT', 'المبلغ يجب أن يكون أكبر من صفر', 'Amount must be positive');
            }
            $other = BankAccount::where('id', $dto['bankAccount'])->orWhere('code', $dto['bankAccount'])->first()
                ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي غير موجود', 'Bank account not found');
            if (! $other->active || $other->id === $cash->bank_account_id) {
                throw AppError::rule('POS_CASH_MOVE_ACCOUNT', 'اختر حسابًا نشطًا غير صندوق هذا الدرج', 'Choose an active account other than this drawer');
            }
            if ($dto['direction'] === 'out' && $amount > $this->expectedCash($s)) {
                throw AppError::rule('POS_CASH_SHORT', 'النقد في الدرج ('.Money::str($this->expectedCash($s)).') أقل من المبلغ المسحوب', 'The drawer holds less than the amount taken out');
            }
            $move = PosCashMove::create(['session_id' => $s->id, 'direction' => $dto['direction'], 'amount' => Money::str($amount), 'reason' => mb_substr($reason, 0, 500),
                'bank_account_id' => $other->id, 'created_by' => $user->username]);
            $drawer = ['account' => $cash->bankAccount->account_id, 'description' => "{$s->number} · {$cash->name_ar}", 'referenceType' => 'PosSession', 'referenceId' => $s->id];
            $bank = ['account' => $other->account_id, 'description' => "{$s->number} · {$other->name_ar}", 'referenceType' => 'PosSession', 'referenceId' => $s->id];
            $journal = $this->ledger->post($user, [
                'eventKey' => "pos:move:{$move->id}", 'source' => ['type' => 'PosSession', 'id' => $s->id, 'number' => $s->number],
                'memo' => ($dto['direction'] === 'in' ? 'إيداع نقد في درج ' : 'سحب نقد من درج ')."{$s->number}: {$reason}",
                'lines' => $dto['direction'] === 'in'
                    ? [['debit' => Money::str($amount)] + $drawer, ['credit' => Money::str($amount)] + $bank]
                    : [['debit' => Money::str($amount)] + $bank, ['credit' => Money::str($amount)] + $drawer],
            ]);
            $move->update(['journal_id' => $journal->id]);
            $this->audit->log($user, ['action' => 'POS.CASH.'.strtoupper($dto['direction']), 'entityType' => 'PosSession', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['amount' => Money::str($amount), 'reason' => $reason, 'account' => $other->code, 'journal' => $journal->number]]);
        });

        return $this->get($id);
    }

    // ───────────── close ─────────────

    /** @param  array{counts:array, reason?:?string, note?:?string, discardHeld?:?bool}  $dto */
    public function close(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $s = $this->lock($id);
            Owned::refuseHistory($s, 'session');
            if ($s->state !== 'open') {
                throw AppError::rule('POS_SESSION_CLOSED', "الجلسة {$s->number} مغلقة مسبقًا — الجلسة المغلقة لا تتغير", "Session {$s->number} is already closed");
            }
            if ($s->cashier_id !== $user->id && ! $user->can('pos.manage')) {
                throw AppError::forbidden('POS_NOT_YOUR_SESSION', 'يغلق الجلسة صاحبها أو مدير نقطة البيع', 'Only the cashier or a point-of-sale manager closes a session');
            }
            $held = PosHeldSale::where('session_id', $s->id)->where('status', 'held')->count();
            if ($held > 0 && empty($dto['discardHeld'])) {
                throw AppError::rule('POS_HELD_OPEN', "في الجلسة {$held} بيع معلّق — استرجعه أو ألغِه قبل الإغلاق", "{$held} held sale(s) remain in the session", ['held' => $held]);
            }
            if ($held > 0) {
                PosHeldSale::where('session_id', $s->id)->where('status', 'held')->update(['status' => 'discarded', 'discard_reason' => 'أُلغي عند إغلاق الجلسة', 'updated_at' => now()]);
            }
            [$counts, $counted] = $this->counted($dto['counts'] ?? [], null, true);
            $expected = $this->expectedCash($s);
            $diff = $counted - $expected;
            $reason = trim((string) ($dto['reason'] ?? ''));
            if ($diff !== 0 && mb_strlen($reason) < 5) {
                throw AppError::validation('POS_DIFF_REASON', 'في النقد فرق '.Money::str($diff).' ر.س — اكتب سببه (5 أحرف على الأقل)', 'The cash differs by '.Money::str($diff).' — a reason is required',
                    ['expected' => Money::str($expected), 'counted' => Money::str($counted), 'difference' => Money::str($diff)]);
            }
            $journalId = null;
            if ($diff !== 0) {
                $cash = $this->cashMethod($s);
                $register = PosRegister::findOrFail($s->register_id);
                $diffAccount = $register->difference_account_id ? Account::findOrFail($register->difference_account_id) : $this->map->role('admin_expenses');
                $drawer = ['account' => $cash->bankAccount->account_id, 'description' => "{$s->number} · {$cash->name_ar}", 'referenceType' => 'PosSession', 'referenceId' => $s->id];
                $other = ['account' => $diffAccount->id, 'description' => "فرق صندوق {$s->number}: {$reason}", 'referenceType' => 'PosSession', 'referenceId' => $s->id];
                $abs = Money::str(abs($diff));
                $journal = $this->ledger->post($user, [
                    'eventKey' => "pos:diff:{$s->id}", 'source' => ['type' => 'PosSession', 'id' => $s->id, 'number' => $s->number],
                    'memo' => ($diff > 0 ? 'زيادة' : 'عجز')." نقد في إغلاق الجلسة {$s->number}: {$reason}",
                    'lines' => $diff > 0 ? [['debit' => $abs] + $drawer, ['credit' => $abs] + $other] : [['debit' => $abs] + $other, ['credit' => $abs] + $drawer],
                ]);
                $journalId = $journal->id;
            }
            $s->update([
                'state' => 'closed', 'closed_at' => now(), 'closed_by' => $user->username, 'closing_counts' => $counts, 'counted_cash' => Money::str($counted), 'expected_cash' => Money::str($expected),
                'difference' => Money::str($diff), 'difference_reason' => $diff !== 0 ? mb_substr($reason, 0, 500) : null, 'difference_journal_id' => $journalId, 'closing_note' => ($dto['note'] ?? null) ?: null,
                'open_register_key' => null, 'open_cashier_key' => null,
            ]);
            // the end-of-day report as it stands at closing — kept, and never computed again
            $s->update(['report' => $this->reports->build($s->refresh())]);
            $this->audit->status($user, 'PosSession', $s->id, $s->number, 'open', 'closed', $diff !== 0 ? 'فرق النقد '.Money::str($diff).": {$reason}" : null);
            $this->audit->log($user, ['action' => 'POS.SESSION.CLOSE', 'entityType' => 'PosSession', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['expected' => Money::str($expected), 'counted' => Money::str($counted), 'difference' => Money::str($diff), 'reason' => $diff !== 0 ? $reason : null]]);
            $this->notify->activity($user, 'PosSession', $s->id, $s->number, "أُغلقت جلسة {$s->number}: المتوقع ".Money::str($expected).' · المعدود '.Money::str($counted).' · الفرق '.Money::str($diff),
                "Session {$s->number} closed: expected ".Money::str($expected).', counted '.Money::str($counted).', difference '.Money::str($diff), $diff !== 0 ? ['finance'] : []);
        });

        return $this->get($id);
    }

    /**
     * The cash that should be in the drawer now: the opening float + cash kept from sales (after change) − cash paid
     * back on refunds + cash put in − cash taken out. In halalas.
     */
    public function expectedCash(PosSession $s): int
    {
        $orders = PosOrder::where('session_id', $s->id)->get(['id', 'kind'])->keyBy('id');
        $cash = 0;
        foreach (PosOrderPayment::whereIn('order_id', $orders->keys())->where('kind', 'cash')->get(['order_id', 'applied']) as $p) {
            $cash += ($orders[$p->order_id]->kind === 'refund' ? -1 : 1) * Money::cents($p->applied);
        }
        foreach (PosCashMove::where('session_id', $s->id)->get(['direction', 'amount']) as $m) {
            $cash += ($m->direction === 'in' ? 1 : -1) * Money::cents($m->amount);
        }

        return Money::cents($s->opening_cash) + $cash;
    }

    /**
     * Counted cash: by denomination (every value must be a known coin or note, every count a whole number ≥ 0) or, for
     * the opening float only, as one amount.
     *
     * @return array{0: array<int, array{value:string, count:int, total:string}>, 1: int}
     */
    private function counted(?array $counts, mixed $amount, bool $required = false): array
    {
        if ($counts === null || $counts === []) {
            if ($required) {
                return [[], 0]; // nothing counted: an empty drawer
            }
            $a = $amount === null || $amount === '' ? 0 : Money::cents($amount);
            if ($a < 0) {
                throw AppError::validation('POS_AMOUNT', 'النقد الافتتاحي لا يكون سالبًا', 'The opening cash cannot be negative');
            }

            return [[], $a];
        }
        $known = PosDenomination::where('active', true)->pluck('value')->map(fn ($v) => Money::cents($v))->flip();
        $rows = [];
        $total = 0;
        foreach ($counts as $i => $c) {
            $value = Money::cents($c['value'] ?? 0);
            $count = $c['count'] ?? null;
            if (! isset($known[$value])) {
                throw AppError::validation('POS_DENOMINATION', 'فئة غير معروفة: '.Money::str($value), 'Unknown denomination: '.Money::str($value), ['line' => $i + 1]);
            }
            if (! is_int($count) && ! (is_string($count) && ctype_digit($count))) {
                throw AppError::validation('POS_COUNT', 'العدد يكون عددًا صحيحًا لا يقل عن صفر', 'A count is a whole number, zero or more', ['line' => $i + 1]);
            }
            $count = (int) $count;
            if ($count === 0) {
                continue;
            }
            $rows[] = ['value' => Money::str($value), 'count' => $count, 'total' => Money::str($value * $count)];
            $total += $value * $count;
        }

        return [$rows, $total];
    }

    /** The register's cash method (its drawer). */
    private function cashMethod(PosSession $s): PosPaymentMethod
    {
        $register = PosRegister::with('methods.bankAccount')->findOrFail($s->register_id);

        return $register->methods->first(fn (PosPaymentMethod $m) => $m->kind === 'cash' && $m->bankAccount)
            ?? throw AppError::rule('POS_NO_CASH_METHOD', 'لا طريقة دفع نقدية مربوطة بصندوق في هذا الصندوق — لا حركة نقدية بلا درج', 'This register has no cash method bound to a cash box');
    }

    private function lock(string $id): PosSession
    {
        return PosSession::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->lockForUpdate()->first()
            ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
    }

    // ───────────── reads ─────────────

    public function current(AuthUser $user): ?array
    {
        $id = PosSession::where('open_cashier_key', $user->id)->value('id');

        return $id ? $this->get($id) : null;
    }

    public function get(string $id): array
    {
        $s = PosSession::with(['register:id,code,name_ar,name_en,warehouse_id,source_system', 'cashMoves.bankAccount:id,code,name_ar,name_en'])->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('POS_SESSION_NOT_FOUND', 'الجلسة غير موجودة', 'Session not found');
        $row = $s->toArray();
        unset($row['report'], $row['openRegisterKey'], $row['openCashierKey']);
        $row['expectedCashNow'] = $s->state === 'open' ? Money::str($this->expectedCash($s)) : $s->expected_cash;
        $row['summary'] = $this->reports->totals($s);
        $row['held'] = PosHeldSale::where('session_id', $s->id)->where('status', 'held')->count();
        $row['history'] = $s->source_system !== null;

        return $row;
    }

    /** @param  array{state?:?string, register?:?string, cashier?:?string, from?:?string, to?:?string, source?:?string}  $f */
    public function list(Paging $page, array $f, AuthUser $user): array
    {
        $q = PosSession::query()->with('register:id,code,name_ar,name_en');
        if (! empty($f['state'])) {
            $q->where('state', $f['state']);
        }
        if (! empty($f['register'])) {
            $q->whereHas('register', fn ($r) => $r->where('id', $f['register'])->orWhere('code', $f['register']));
        }
        if (($f['cashier'] ?? null) === 'me') {
            $q->where('cashier_id', $user->id);
        } elseif (! empty($f['cashier'])) {
            $q->where('cashier_id', $f['cashier']);
        }
        if (! empty($f['from'])) {
            $q->where('opened_at', '>=', $f['from'].' 00:00:00');
        }
        if (! empty($f['to'])) {
            $q->where('opened_at', '<=', $f['to'].' 23:59:59.999');
        }
        if (($f['source'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('cashier_name', 'like', $like));
        }
        $column = match ($page->sort) {
            'number' => 'number', 'closedAt' => 'closed_at', 'difference' => 'difference', default => 'opened_at'
        };
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, function (PosSession $s) {
            $row = $s->toArray();
            unset($row['report'], $row['openingCounts'], $row['closingCounts'], $row['openRegisterKey'], $row['openCashierKey']);
            $row['summary'] = $this->reports->totals($s);

            return $row;
        });
    }
}
