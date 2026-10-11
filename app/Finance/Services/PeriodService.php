<?php

namespace App\Finance\Services;

use App\Finance\Models\Journal;
use App\Finance\Models\Period;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal periods (calendar months). A period is created open the first time something posts in it; closing refuses
 * further postings (automatic ones included — the operational action fails loudly with PERIOD_CLOSED instead of
 * landing in the wrong month); reopening needs gl.close and a written reason, both audited.
 */
class PeriodService
{
    public function __construct(private readonly AuditService $audit) {}

    public static function codeOf(CarbonInterface $d): string
    {
        return $d->format('Y-m');
    }

    /** The period that contains the date, created open when missing. */
    public function forDate(CarbonInterface $d): Period
    {
        $code = self::codeOf($d);
        $p = Period::where('code', $code)->first();
        if ($p) {
            return $p;
        }
        $p = Period::create(['code' => $code, 'year' => (int) $d->format('Y'), 'month' => (int) $d->format('m'), 'starts_on' => $d->copy()->startOfMonth()->toDateString(), 'ends_on' => $d->copy()->endOfMonth()->toDateString(), 'status' => 'open']);

        return $p->refresh();
    }

    /** Open period for posting on this date, or PERIOD_CLOSED. */
    public function openFor(CarbonInterface $d): Period
    {
        $p = $this->forDate($d);
        if ($p->status !== 'open') {
            throw AppError::rule('PERIOD_CLOSED', "الفترة المالية {$p->code} مغلقة — لا ترحيل فيها؛ اطلب من المالية إعادة فتحها أو رحّل بتاريخ في فترة مفتوحة", "Fiscal period {$p->code} is closed");
        }

        return $p;
    }

    public function list(): array
    {
        $periods = Period::orderByDesc('code')->get();
        $stats = Journal::selectRaw('period_id, count(*) as journals, coalesce(sum(total_debit), 0) as debit')->where('status', '!=', 'draft')->groupBy('period_id')->get()->keyBy('period_id');
        $drafts = Journal::selectRaw('period_id, count(*) as drafts')->where('status', 'draft')->groupBy('period_id')->pluck('drafts', 'period_id');

        return $periods->map(fn (Period $p) => $p->toArray() + [
            'journals' => (int) ($stats[$p->id]->journals ?? 0), 'debit' => round((float) ($stats[$p->id]->debit ?? 0), 2), 'drafts' => (int) ($drafts[$p->id] ?? 0),
        ])->all();
    }

    /** Close: every earlier period must be closed and no draft journal may remain in it. */
    public function close(AuthUser $user, string $code, ?string $notes = null): array
    {
        return DB::transaction(function () use ($user, $code, $notes) {
            $p = Period::where('code', $code)->lockForUpdate()->first() ?? throw AppError::notFound('PERIOD_NOT_FOUND', "الفترة {$code} غير موجودة", "Period {$code} not found");
            if ($p->status === 'closed') {
                throw AppError::rule('PERIOD_STATE', "الفترة {$code} مغلقة مسبقًا", 'Period already closed');
            }
            $earlierOpen = Period::where('code', '<', $code)->where('status', 'open')->orderBy('code')->value('code');
            if ($earlierOpen) {
                throw AppError::rule('PERIOD_ORDER', "أغلق الفترة {$earlierOpen} أولًا — الإقفال يكون بالترتيب", "Close {$earlierOpen} first");
            }
            $drafts = Journal::where('period_id', $p->id)->where('status', 'draft')->count();
            if ($drafts > 0) {
                throw AppError::rule('PERIOD_DRAFTS', "في الفترة {$code} {$drafts} قيد يدوي غير معتمد — اعتمده أو احذفه قبل الإقفال", "{$drafts} draft journal(s) remain in {$code}");
            }
            $p->update(['status' => 'closed', 'closed_by' => $user->username, 'closed_at' => now(), 'notes' => $notes ?: $p->notes]);
            $this->audit->log($user, ['action' => 'PERIOD.CLOSE', 'entityType' => 'FiscalPeriod', 'entityId' => $p->id, 'entityNumber' => $code, 'newValue' => ['notes' => $notes]]);

            return $p->refresh()->toArray();
        });
    }

    public function reopen(AuthUser $user, string $code, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب إعادة فتح الفترة (5 أحرف على الأقل)', 'A reason is required');
        }

        return DB::transaction(function () use ($user, $code, $reason) {
            $p = Period::where('code', $code)->lockForUpdate()->first() ?? throw AppError::notFound('PERIOD_NOT_FOUND', "الفترة {$code} غير موجودة", "Period {$code} not found");
            if ($p->status !== 'closed') {
                throw AppError::rule('PERIOD_STATE', "الفترة {$code} مفتوحة", 'Period is open');
            }
            $laterClosed = Period::where('code', '>', $code)->where('status', 'closed')->orderByDesc('code')->value('code');
            if ($laterClosed) {
                throw AppError::rule('PERIOD_ORDER', "أعد فتح الفترة {$laterClosed} أولًا — إعادة الفتح تكون من الأحدث", "Reopen {$laterClosed} first");
            }
            $p->update(['status' => 'open', 'reopened_by' => $user->username, 'reopened_at' => now(), 'notes' => trim(($p->notes ? $p->notes."\n" : '')."إعادة فتح: {$reason}")]);
            $this->audit->log($user, ['action' => 'PERIOD.REOPEN', 'entityType' => 'FiscalPeriod', 'entityId' => $p->id, 'entityNumber' => $code, 'newValue' => ['reason' => $reason]]);

            return $p->refresh()->toArray();
        });
    }
}
