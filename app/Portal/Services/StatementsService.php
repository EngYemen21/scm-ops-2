<?php

namespace App\Portal\Services;

use App\Models\Customer;
use App\Portal\Models\PortalBranch;
use App\Portal\Models\Statement;
use App\Portal\Models\StatementLine;
use App\Portal\Support\Owned;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer statements of account.
 *
 * TWO ORIGINS, ONE LIST. The past is Odoo's: the statements Odoo generated are kept as Odoo printed them — its own
 * opening and closing balance, its own lines and running balance; nothing of them is recomputed. Going forward a
 * statement is worked out from the OPS receivables (StatementBuilder: invoices, credit notes, receipts and their
 * cancellations) for a customer or one of its branches and a period: first as a preview that writes nothing, then
 * ISSUED — numbered and stored line by line, so what the customer was given on that day can always be shown again
 * even after later documents changed the account.
 *
 * A statement is a snapshot and is never edited: one that should not stand is voided with a reason (it stays in the
 * list, marked) and a new one is issued. While the section follows Odoo, Odoo's statements are Odoo's.
 */
class StatementsService
{
    private const WITH = [Parties::CUSTOMER, Parties::BRANCH];

    private const SORTS = ['dateTo' => 'date_to', 'dateFrom' => 'date_from', 'number' => 'number', 'closingBalance' => 'closing_balance', 'issuedAt' => 'issued_at', 'linesCount' => 'lines_count'];

    /** How many lines a preview carries; the totals always cover the whole period. */
    public const PREVIEW_LINES = 500;

    public function __construct(
        private readonly StatementBuilder $builder,
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
    ) {}

    // ───────────── reading ─────────────

    /** @param  array{customer?:?string, branch?:?string, origin?:?string, status?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with(self::WITH);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByDesc('date_to')->orderByDesc('id');
        } else {
            if ($column === 'issued_at') {
                $query->orderByRaw('issued_at IS NULL');
            }
            $query->orderBy($column, $page->order)->orderBy('id', $page->order);
        }

        return $page->paginate($query, fn (Statement $s) => Shape::statement($s));
    }

    public function get(string $id): array
    {
        $s = $this->find($id);
        $owned = Owned::bySource($s);
        // the branches its documents name — a statement of the whole customer can be read branch by branch
        $branchIds = StatementLine::where('statement_id', $s->id)->whereNotNull('branch_id')->distinct()->pluck('branch_id')->all();

        return Shape::statement($s, true) + [
            'branches' => $branchIds ? PortalBranch::whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])->map(fn (PortalBranch $b) => ['id' => $b->id, 'name' => $b->name])->all() : [],
            'events' => PortalLog::of('statement', $s->id, 50),
            'can' => ['void' => ! $owned && $s->status === 'issued'],
        ];
    }

    /**
     * One page of a statement's lines, in their order, with the totals of what is filtered.
     *
     * @param  array{kind?:?string, branch?:?string}  $f
     */
    public function lines(string $id, Paging $page, array $f): array
    {
        $s = $this->find($id, []);
        $base = function () use ($s, $f) {
            $q = StatementLine::where('statement_id', $s->id);
            if (in_array($f['kind'] ?? null, StatementLine::KINDS, true)) {
                $q->where('kind', $f['kind']);
            }
            if (! empty($f['branch'])) {
                $f['branch'] === 'none' ? $q->whereNull('branch_id') : $q->where('branch_id', $f['branch']);
            }

            return $q;
        };
        $sums = $base()->toBase()->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();

        return $page->paginate($base()->with([Parties::BRANCH])->orderBy('date')->orderBy('seq')->orderBy('id'), fn (StatementLine $l) => Shape::statementLine($l))
            + ['totals' => ['debit' => Text::money($sums->d ?? 0), 'credit' => Text::money($sums->c ?? 0)]];
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = Statement::query();
        $status = trim((string) ($f['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $states = array_values(array_intersect(Statement::STATUSES, explode(',', $status)));
            $states ? $query->whereIn('status', $states) : $query->whereRaw('1 = 0');
        }
        Parties::filterCustomer($query, $f['customer'] ?? null);
        Parties::filterOrigin($query, $f['origin'] ?? null);
        if (! empty($f['branch'])) {
            // issued for the branch, or a statement of the customer whose documents name the branch
            $branch = (string) $f['branch'];
            $query->where(fn ($w) => $w->where('branch_id', $branch)->orWhereIn('id', StatementLine::query()->where('branch_id', $branch)->select('statement_id')));
        }
        // the statements whose period touches the days asked for
        if (! empty($f['from'])) {
            $query->where('date_to', '>=', substr((string) $f['from'], 0, 10));
        }
        if (! empty($f['to'])) {
            $query->where('date_from', '<=', substr((string) $f['to'], 0, 10));
        }
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op) {
                $w->where('number', $op, $like);
                Parties::searchCustomer($w, $like);
            });
        }

        return $query;
    }

    // ───────────── from the OPS receivables ─────────────

    /**
     * The statement of a customer (or one branch) for a period, worked out now and not stored.
     *
     * @param  array{customer:string, branch?:?string, from:string, to:string}  $dto
     */
    public function preview(array $dto): array
    {
        [$customer, $branch, $from, $to] = $this->scope($dto);
        $built = $this->builder->build($customer, $branch, $from, $to);
        $lines = array_slice($built['lines'], 0, self::PREVIEW_LINES);

        return [
            'customer' => ['id' => $customer->id, 'code' => $customer->code, 'nameAr' => $customer->name_ar, 'nameEn' => $customer->name_en, 'city' => $customer->city, 'active' => (bool) $customer->active, 'linked' => true, 'ref' => null],
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name, 'active' => (bool) $branch->active] : null,
            'dateFrom' => $built['from'], 'dateTo' => $built['to'], 'openingBalance' => Text::money($built['opening']), 'totalDebit' => Text::money($built['totalDebit']),
            'totalCredit' => Text::money($built['totalCredit']), 'closingBalance' => Text::money($built['closing']), 'currency' => $built['currency'],
            'linesCount' => count($built['lines']), 'counts' => $built['counts'],
            'lines' => array_map(fn (array $l) => ['date' => $l['date'], 'kind' => $l['kind'], 'reference' => $l['reference'], 'description' => $l['description'],
                'debit' => Text::money($l['debit']), 'credit' => Text::money($l['credit']), 'balance' => Text::money($l['balance']), 'docType' => $l['docType'], 'docId' => $l['docId']], $lines),
            'more' => count($built['lines']) > count($lines),
            'books' => ['closing' => $built['books']['closing'] === null ? null : Text::money($built['books']['closing']), 'agrees' => $built['books']['agrees']],
            'existing' => $this->standing($customer->id, $branch?->id, $built['from'], $built['to'])?->number,
        ];
    }

    /**
     * Issues the statement: numbered and stored line by line, as the customer is given it.
     *
     * @param  array{customer:string, branch?:?string, from:string, to:string, note?:?string}  $dto
     */
    public function issue(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            [$customer, $branch, $from, $to] = $this->scope($dto);
            if ($twin = $this->standing($customer->id, $branch?->id, $from->toDateString(), $to->toDateString())) {
                throw AppError::rule('PORTAL_STATEMENT_EXISTS', "كشف الحساب {$twin->number} صادر للعميل نفسه عن الفترة نفسها — ألغِه أولًا إن كان يجب أن يُستبدل", "Statement {$twin->number} already stands for the same customer and period — void it first if it has to be replaced",
                    ['statementId' => $twin->id, 'number' => $twin->number]);
            }
            $built = $this->builder->build($customer, $branch, $from, $to);
            $s = Statement::create([
                'number' => $this->numbering->next('SOA'), 'status' => 'issued', 'customer_id' => $customer->id, 'branch_id' => $branch?->id, 'date_from' => $built['from'], 'date_to' => $built['to'],
                'opening_balance' => $built['opening'], 'total_debit' => $built['totalDebit'], 'total_credit' => $built['totalCredit'], 'closing_balance' => $built['closing'],
                'currency' => $built['currency'], 'lines_count' => count($built['lines']), 'books_closing' => $built['books']['closing'], 'note' => Text::clean($dto['note'] ?? null, 2000),
                'issued_at' => now(), 'issued_by' => $user->username, 'created_by' => $user->username,
            ]);
            $now = now()->format('Y-m-d H:i:s.v');
            foreach (array_chunk($built['lines'], 500) as $chunk) {
                DB::table('portal_statement_lines')->insert(array_map(fn (array $l) => [
                    'id' => (string) Str::ulid(), 'statement_id' => $s->id, 'date' => $l['date'], 'seq' => $l['seq'], 'kind' => $l['kind'], 'reference' => $l['reference'],
                    'description' => $l['description'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'balance' => $l['balance'], 'branch_id' => $branch?->id,
                    'doc_type' => $l['docType'], 'doc_id' => $l['docId'], 'created_at' => $now,
                ], $chunk));
            }
            PortalLog::add('statement', $s->id, 'issued', $user, ['data' => ['lines' => count($built['lines']), 'closing' => Text::money($built['closing'])]]);
            $this->audit->log($user, ['action' => 'PORTAL.STATEMENT.ISSUE', 'entityType' => 'PortalStatement', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['customer' => $customer->code, 'branch' => $branch?->name, 'from' => $built['from'], 'to' => $built['to'], 'closing' => $built['closing'], 'lines' => count($built['lines'])]]);
            $this->notify->activity($user, 'PortalStatement', $s->id, $s->number, "صدر كشف الحساب {$s->number} للعميل {$customer->name_ar}".($branch ? " (فرع {$branch->name})" : '')." عن {$built['from']} — {$built['to']}",
                "Statement {$s->number} issued for {$customer->name_en}");

            return $s->id;
        });

        return $this->get($id);
    }

    public function void(AuthUser $user, string $id, string $reason): array
    {
        DB::transaction(function () use ($user, $id, $reason) {
            $s = Statement::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->lockForUpdate()->first()
                ?? throw AppError::notFound('PORTAL_STATEMENT_NOT_FOUND', 'كشف الحساب غير موجود', 'Statement not found');
            Owned::refuse($s, 'statement');
            if ($s->status !== 'issued') {
                throw AppError::rule('PORTAL_STATEMENT_STATE', "كشف الحساب {$s->number} ".($s->status === 'void' ? 'ملغى أصلًا' : 'ليس صادرًا'), "Statement {$s->number} is ".($s->status === 'void' ? 'already void' : 'not issued'), ['status' => $s->status]);
            }
            $s->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => mb_substr(trim($reason), 0, 500)]);
            PortalLog::add('statement', $s->id, 'voided', $user, ['note' => $reason]);
            $this->audit->status($user, 'PortalStatement', $s->id, $s->number, 'issued', 'void', mb_substr(trim($reason), 0, 500));
        });

        return $this->get($id);
    }

    /** The lines of a statement as a spreadsheet: UTF-8 with a BOM, one row per line, at most 50,000. @return array{name:string, body:string} */
    public function export(string $id): array
    {
        $s = $this->find($id);
        $c = Parties::customerOf($s);
        $row = fn (array $cells) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $cells))."\r\n";
        // text that a person (or another system) typed is never a formula: a spreadsheet would run one that starts a cell
        $text = fn (?string $v) => $v !== null && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : (string) $v;
        $body = "\xEF\xBB\xBF";
        $body .= $row(['كشف حساب / Statement', $text($s->number), $text($c['nameAr'] ?? ''), $text($c['code'] ?? ''), Shape::day($s->date_from), Shape::day($s->date_to)]);
        $body .= $row(['التاريخ / Date', 'النوع / Type', 'المرجع / Reference', 'البيان / Description', 'مدين / Debit', 'دائن / Credit', 'الرصيد / Balance']);
        $body .= $row(['', '', '', 'رصيد افتتاحي / Opening balance', '', '', Text::money($s->opening_balance)]);
        foreach (StatementLine::where('statement_id', $s->id)->orderBy('date')->orderBy('seq')->orderBy('id')->limit(50000)->cursor() as $l) {
            $body .= $row([Shape::day($l->date), $l->kind, $text($l->reference), $text($l->description), Text::money($l->debit), Text::money($l->credit), Text::money($l->balance)]);
        }
        $body .= $row(['', '', '', 'الإجمالي / Totals', Text::money($s->total_debit), Text::money($s->total_credit), Text::money($s->closing_balance)]);

        return ['name' => 'statement-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $s->number).'.csv', 'body' => $body];
    }

    // ───────────── pieces ─────────────

    public function find(string $id, array $with = self::WITH): Statement
    {
        return Statement::with($with)->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('PORTAL_STATEMENT_NOT_FOUND', 'كشف الحساب غير موجود', 'Statement not found');
    }

    /**
     * Who and what period a statement is asked for.
     *
     * @return array{0:Customer, 1:?PortalBranch, 2:Carbon, 3:Carbon}
     */
    private function scope(array $dto): array
    {
        $customer = Parties::customer($dto['customer'] ?? null, 'customer');
        $branch = ! empty($dto['branch']) ? Parties::branch($dto['branch'], $customer, 'branch') : null;
        $from = Carbon::parse(substr((string) $dto['from'], 0, 10))->startOfDay();
        $to = Carbon::parse(substr((string) $dto['to'], 0, 10))->startOfDay();
        if ($from->gt($to)) {
            throw AppError::validation('PORTAL_PERIOD', 'بداية الفترة بعد نهايتها', 'The period starts after it ends', [['path' => 'to', 'message' => 'يجب ألا تسبق البداية']]);
        }
        if ($from->diffInDays($to) > Statement::MAX_DAYS) {
            throw AppError::validation('PORTAL_PERIOD', 'أطول فترة لكشف واحد سنتان', 'One statement covers two years at most', [['path' => 'to', 'message' => 'الفترة أطول من سنتين']]);
        }

        return [$customer, $branch, $from, $to];
    }

    /** The statement issued in OPS that still stands for exactly this customer, branch and period. */
    private function standing(string $customerId, ?string $branchId, string $from, string $to): ?Statement
    {
        return Statement::whereNull('source_system')->where('status', 'issued')->where('customer_id', $customerId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->where('date_from', $from)->where('date_to', $to)->first();
    }
}
