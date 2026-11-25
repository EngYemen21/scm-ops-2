<?php

namespace App\Portal\Services;

use App\Portal\Models\Application;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\Statement;
use App\Portal\Support\Owned;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the page's head and of its tab badges: how much the section holds, what waits for a person, and how
 * much of what came from another system still points at a customer OPS does not have. A handful of counts, each
 * answered by an index or by a table of a few thousand rows — the favourites are counted from the lists' own
 * `items_count`, never from the 156 k items.
 */
class PortalSummary
{
    public function get(): array
    {
        $branches = DB::table('portal_branches')->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN active THEN 1 ELSE 0 END), 0) AS live, COALESCE(SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END), 0) AS unlinked')->first();
        $lists = DB::table('portal_favorite_lists')->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN active THEN 1 ELSE 0 END), 0) AS live, COALESCE(SUM(CASE WHEN active THEN items_count ELSE 0 END), 0) AS items, COALESCE(SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END), 0) AS unlinked')->first();
        $applications = DB::table('portal_applications')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $requests = DB::table('portal_order_requests')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $statements = DB::table('portal_statements')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $byStatus = fn ($rows, array $all) => array_combine($all, array_map(fn (string $s) => (int) ($rows[$s] ?? 0), $all));

        return [
            // the section follows Odoo: what came from Odoo is Odoo's and is read-only here
            'following' => Owned::following(),
            'branches' => ['total' => (int) $branches->total, 'active' => (int) $branches->live, 'unlinked' => (int) $branches->unlinked],
            'favorites' => ['lists' => (int) $lists->total, 'active' => (int) $lists->live, 'items' => (int) $lists->items, 'unlinked' => (int) $lists->unlinked],
            'applications' => ['total' => (int) $applications->sum(), 'waiting' => (int) ($applications['submitted'] ?? 0) + (int) ($applications['accepted'] ?? 0), 'byStatus' => $byStatus($applications, Application::STATUSES)],
            'requests' => ['total' => (int) $requests->sum(), 'pending' => (int) ($requests['pending'] ?? 0), 'byStatus' => $byStatus($requests, OrderRequest::STATUSES)],
            'lineChanges' => ['total' => DB::table('portal_line_changes')->count()],
            'statements' => ['total' => (int) $statements->sum(), 'issued' => (int) ($statements['issued'] ?? 0), 'byStatus' => $byStatus($statements, Statement::STATUSES),
                'ops' => DB::table('portal_statements')->whereNull('source_system')->count()],
        ];
    }
}
