<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionStatement;
use App\Commissions\Support\Owned;
use App\Commissions\Support\People;
use App\Commissions\Support\Period;
use App\Commissions\Support\Shape;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * The summary of the section: what is pending (draft or waiting for approval), approved (owed, not paid yet) and paid —
 * in total, month by month and earner by earner. A user who only sees his own statements gets his own summary.
 * Amounts are the statements' totals (lines + adjustments); Odoo's history keeps Odoo's own figures.
 */
class SummaryService
{
    private const BUCKET = ['draft' => 'pending', 'submitted' => 'pending', 'approved' => 'approved', 'paid' => 'paid'];

    /** @param  array{from?:?string, to?:?string, beneficiary?:?string, source?:?string}  $f  `from` / `to` are periods (YYYY-MM) */
    public function summary(AuthUser $user, array $f): array
    {
        $seesAll = People::seesAll($user);
        $to = Period::valid($f['to'] ?? null) ? $f['to'] : null;
        $from = Period::valid($f['from'] ?? null) ? $f['from'] : null;
        $base = fn () => DB::table('commission_statements')
            ->when(! $seesAll, fn ($q) => $q->where('beneficiary_id', $user->id))
            ->when($from, fn ($q) => $q->where('period', '>=', $from))->when($to, fn ($q) => $q->where('period', '<=', $to))
            ->when(! empty($f['beneficiary']), fn ($q) => str_starts_with($f['beneficiary'], 'name:') ? $q->whereNull('beneficiary_id')->where('beneficiary_name', substr($f['beneficiary'], 5)) : $q->where('beneficiary_id', $f['beneficiary']))
            ->when(($f['source'] ?? null) === 'odoo', fn ($q) => $q->where('source_system', 'odoo'))->when(($f['source'] ?? null) === 'ops', fn ($q) => $q->whereNull('source_system'));
        $empty = fn () => ['pending' => ['count' => 0, 'amount' => 0.0], 'approved' => ['count' => 0, 'amount' => 0.0], 'paid' => ['count' => 0, 'amount' => 0.0]];
        $add = function (array &$into, object $row): void {
            $bucket = self::BUCKET[$row->status] ?? 'pending';
            $into[$bucket]['count'] += (int) $row->n;
            $into[$bucket]['amount'] += (float) $row->amount;
        };
        $close = fn (array $b) => array_map(fn (array $x) => ['count' => $x['count'], 'amount' => Shape::money($x['amount'])], $b)
            + ['count' => array_sum(array_column($b, 'count')), 'total' => Shape::money(array_sum(array_column($b, 'amount')))];

        $totals = $empty();
        $months = [];
        $byStatus = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'paid' => 0];
        foreach ($base()->selectRaw('period, status, count(*) as n, coalesce(sum(total), 0) as amount')->groupBy('period', 'status')->orderBy('period')->get() as $row) {
            $months[$row->period] ??= $empty();
            $add($months[$row->period], $row);
            $add($totals, $row);
            $byStatus[$row->status] = ($byStatus[$row->status] ?? 0) + (int) $row->n;
        }
        $people = [];
        foreach ($base()->selectRaw('beneficiary_id, beneficiary_name, status, count(*) as n, coalesce(sum(total), 0) as amount, coalesce(sum(base_total), 0) as base')
            ->groupBy('beneficiary_id', 'beneficiary_name', 'status')->get() as $row) {
            $key = $row->beneficiary_id ? (string) $row->beneficiary_id : 'name:'.$row->beneficiary_name;
            $people[$key] ??= ['id' => $row->beneficiary_id ? (string) $row->beneficiary_id : null, 'name' => $row->beneficiary_name, 'base' => 0.0, 'buckets' => $empty()];
            $people[$key]['base'] += (float) $row->base;
            $add($people[$key]['buckets'], $row);
        }
        $users = People::byId(array_column($people, 'id'));
        $byBeneficiary = array_map(fn (array $p) => ['beneficiary' => People::shape($p['id'], $users[$p['id']] ?? null, $p['name']), 'base' => Shape::money($p['base'])] + $close($p['buckets']), array_values($people));
        usort($byBeneficiary, fn (array $a, array $b) => (float) $b['total'] <=> (float) $a['total']);
        krsort($months);

        return [
            'scope' => $seesAll ? 'all' : 'mine', 'from' => $from, 'to' => $to, 'currentPeriod' => Period::current(), 'following' => Owned::following(),
            'totals' => $close($totals), 'statements' => $byStatus,
            'byMonth' => array_map(fn (string $period, array $b) => ['period' => $period] + $close($b), array_keys($months), array_values($months)),
            'byBeneficiary' => $byBeneficiary,
            'periods' => $this->periods($user, $seesAll),
        ];
    }

    /**
     * What the page needs to draw itself: whether the section follows Odoo, what this user may do, and — for those who
     * manage or approve — the people, the product categories and the plans its pickers offer.
     */
    public function lookups(AuthUser $user): array
    {
        $seesAll = People::seesAll($user);
        $rows = fn (string $table, callable $where) => $where(DB::table($table))->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en'])
            ->map(fn ($r) => ['id' => (string) $r->id, 'code' => (string) $r->code, 'nameAr' => (string) $r->name_ar, 'nameEn' => (string) $r->name_en])->all();

        return [
            'following' => Owned::following(), 'currentPeriod' => Period::current(), 'scope' => $seesAll ? 'all' : 'mine',
            'can' => ['manage' => $user->can(People::MANAGE), 'approve' => $user->can(People::APPROVE)],
            'me' => ['id' => $user->id, 'username' => $user->username],
            'users' => $seesAll ? People::selectable() : [],
            'categories' => $seesAll ? $rows('product_categories', fn ($q) => $q->where('active', true)) : [],
            'plans' => $seesAll ? DB::table('commission_plans')->orderByDesc('created_at')->orderBy('code')->limit(300)->get(['id', 'code', 'name_ar', 'name_en', 'status', 'source_system'])
                ->map(fn ($p) => ['id' => (string) $p->id, 'code' => (string) $p->code, 'nameAr' => (string) $p->name_ar, 'nameEn' => (string) $p->name_en, 'status' => (string) $p->status, 'sourceSystem' => $p->source_system])->all() : [],
            'periods' => $this->periods($user, $seesAll),
        ];
    }

    /** The months that hold a statement this user may see, latest first (the choices of the period filters). @return string[] */
    private function periods(AuthUser $user, bool $seesAll): array
    {
        return CommissionStatement::query()->when(! $seesAll, fn ($q) => $q->where('beneficiary_id', $user->id))->distinct()->orderByDesc('period')->limit(120)->pluck('period')->all();
    }
}
