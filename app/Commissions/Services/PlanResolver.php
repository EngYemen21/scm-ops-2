<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionPlan;

/**
 * Which plan decides for whom in a month. ONE plan per earner and month:
 *   - the active plan that names the person — as the manager of a manager plan, or as a member of a salesperson plan —
 *     and covers at least one day of the month;
 *   - otherwise the active salesperson plan for "everyone" (the default), for whoever sold something;
 *   - when two plans name the same person in one month (one ended, the next began), the one that starts LATER decides
 *     the whole month.
 * Activation refuses overlaps (PlansService), so the last rule only ever settles a month in which a plan was replaced.
 */
class PlanResolver
{
    /**
     * @return array{explicit: array<string, CommissionPlan>, default: ?CommissionPlan}
     *                                                                                  explicit: earner user id → the plan that names him
     */
    public function forPeriod(string $from, string $to): array
    {
        $plans = CommissionPlan::with(['rules', 'members'])->where('status', 'active')->whereDate('valid_from', '<=', $to)
            ->where(fn ($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
            ->orderByDesc('valid_from')->orderByDesc('created_at')->orderByDesc('id')->get();
        $explicit = [];
        $default = null;
        foreach ($plans as $p) {
            if ($p->earner_type === 'manager') {
                if ($p->beneficiary_id) {
                    $explicit[(string) $p->beneficiary_id] ??= $p;
                }

                continue;
            }
            if ($p->everyone) {
                $default ??= $p;

                continue;
            }
            foreach ($p->members as $m) {
                if ($m->user_id) {
                    $explicit[(string) $m->user_id] ??= $p;
                }
            }
        }

        return ['explicit' => $explicit, 'default' => $default];
    }
}
