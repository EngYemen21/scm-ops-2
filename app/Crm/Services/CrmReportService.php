<?php

namespace App\Crm\Services;

use App\Crm\Models\CrmLostReason;
use App\Crm\Models\CrmStage;
use App\Crm\Support\Shape;
use App\Support\AuthUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The funnel: what is in the pipeline now, stage by stage, and how opportunities ended.
 *
 * Two different clocks, on purpose. The OPEN pipeline is a picture of now — a period does not apply to it. WON and
 * LOST are counted by the day they were closed (`closed_at`) inside the period asked for; with no period, all of
 * them. The win rate is won ÷ (won + lost) of that same set. Revenue is the expected revenue people entered on the
 * opportunity; "weighted" multiplies it by the opportunity's chance.
 */
class CrmReportService
{
    private const SUMS = 'COUNT(*) AS n, COALESCE(SUM(expected_revenue), 0) AS revenue, COALESCE(SUM(expected_revenue * probability / 100), 0) AS weighted';

    public function __construct(private readonly LeadsService $leads) {}

    /** @param  array{team?:?string, owner?:?string, from?:?string, to?:?string}  $f */
    public function funnel(AuthUser $user, array $f): array
    {
        $scope = array_filter(['team' => $f['team'] ?? null, 'owner' => $f['owner'] ?? null]);
        $from = ! empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : null;
        $to = ! empty($f['to']) ? Carbon::parse($f['to'])->startOfDay() : null;
        $open = fn (): Builder => $this->leads->filtered($user, ['state' => 'open'] + $scope, null);
        $closed = fn (string $status): Builder => $this->leads->filtered($user, ['state' => $status] + $scope, null)
            ->when($from, fn ($q) => $q->where('closed_at', '>=', $from))->when($to, fn ($q) => $q->where('closed_at', '<', $to->copy()->addDay()));

        // ── by stage: the open opportunities standing in it; a won stage shows what was won into it in the period
        $inStage = $open()->toBase()->selectRaw('stage_id, '.self::SUMS)->groupBy('stage_id')->get()->keyBy('stage_id');
        $wonInStage = $closed('won')->toBase()->selectRaw('stage_id, '.self::SUMS)->groupBy('stage_id')->get()->keyBy('stage_id');
        $stages = CrmStage::query()->where(fn ($w) => $w->where('active', true)->orWhereIn('id', $inStage->keys()->merge($wonInStage->keys())->unique()->all()))->ordered()->get()
            ->map(function (CrmStage $s) use ($inStage, $wonInStage) {
                $sum = ($s->is_won ? $wonInStage : $inStage)[$s->id] ?? null;

                return ['id' => $s->id, 'nameAr' => $s->name_ar, 'nameEn' => $s->name_en, 'isWon' => $s->is_won, 'active' => $s->active] + self::figures($sum);
            })->all();

        // ── totals and the win rate
        $one = fn (Builder $q): array => self::figures($q->toBase()->selectRaw(self::SUMS)->first());
        $totals = ['open' => $one($open()), 'won' => $one($closed('won')), 'lost' => $one($closed('lost'))];
        $decided = $totals['won']['count'] + $totals['lost']['count'];
        $totals['winRate'] = $decided ? round($totals['won']['count'] * 100 / $decided, 1) : null;

        // ── by salesperson (an OPS user, or the name Odoo gives)
        $people = [];
        foreach (['open' => $open(), 'won' => $closed('won'), 'lost' => $closed('lost')] as $status => $query) {
            foreach ($query->toBase()->selectRaw('owner_id, owner_name, '.self::SUMS)->groupBy('owner_id', 'owner_name')->get() as $r) {
                $key = $r->owner_id ?: ($r->owner_name !== null ? 'name:'.$r->owner_name : 'none');
                $people[$key] ??= ['ownerId' => $r->owner_id, 'ownerName' => $r->owner_name, 'open' => 0, 'openRevenue' => 0.0, 'weighted' => 0.0, 'won' => 0, 'wonRevenue' => 0.0, 'lost' => 0];
                if ($status === 'open') {
                    $people[$key] = ['open' => (int) $r->n, 'openRevenue' => round((float) $r->revenue, 2), 'weighted' => round((float) $r->weighted, 2)] + $people[$key];
                } elseif ($status === 'won') {
                    $people[$key] = ['won' => (int) $r->n, 'wonRevenue' => round((float) $r->revenue, 2)] + $people[$key];
                } else {
                    $people[$key] = ['lost' => (int) $r->n] + $people[$key];
                }
            }
        }
        $users = DB::table('users')->whereIn('id', array_values(array_filter(array_column($people, 'ownerId'))))->get(['id', 'username', 'name_ar', 'name_en'])->keyBy('id');
        $bySalesperson = collect($people)->map(function (array $p) use ($users) {
            $decided = $p['won'] + $p['lost'];

            return [
                'owner' => $p['ownerId'] || $p['ownerName'] !== null ? Shape::person($p['ownerId'], $p['ownerId'] ? ($users[$p['ownerId']] ?? null) : null, $p['ownerName']) : null,
                'open' => $p['open'], 'openRevenue' => $p['openRevenue'], 'weighted' => $p['weighted'], 'won' => $p['won'], 'wonRevenue' => $p['wonRevenue'], 'lost' => $p['lost'],
                'winRate' => $decided ? round($p['won'] * 100 / $decided, 1) : null,
            ];
        })->sortBy([['open', 'desc'], ['won', 'desc'], ['lost', 'desc']])->values()->all();

        // ── why opportunities were lost
        $reasons = CrmLostReason::get()->keyBy('id');
        $lostReasons = $closed('lost')->toBase()->selectRaw('lost_reason_id, '.self::SUMS)->groupBy('lost_reason_id')->get()
            ->map(fn ($r) => ['reason' => $r->lost_reason_id && isset($reasons[$r->lost_reason_id]) ? ['id' => $r->lost_reason_id, 'nameAr' => $reasons[$r->lost_reason_id]->name_ar, 'nameEn' => $reasons[$r->lost_reason_id]->name_en] : null,
                'count' => (int) $r->n, 'revenue' => round((float) $r->revenue, 2)])
            ->sortByDesc('count')->values()->all();

        return [
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'appliesTo' => 'closedAt'],
            'stages' => $stages, 'totals' => $totals, 'bySalesperson' => $bySalesperson, 'lostReasons' => $lostReasons,
        ];
    }

    /** @return array{count:int, revenue:float, weighted:float} */
    private static function figures(?object $sum): array
    {
        return ['count' => (int) ($sum->n ?? 0), 'revenue' => round((float) ($sum->revenue ?? 0), 2), 'weighted' => round((float) ($sum->weighted ?? 0), 2)];
    }
}
