<?php

use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntMirror;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\OdooExport;
use App\Integration\Odoo\OdooFiles;
use App\Integration\Odoo\OdooMasterData;
use App\Integration\Odoo\OdooMirror;
use App\Integration\Odoo\OdooVerify;
use App\Integration\Odoo\OdooSync;
use App\Integration\Services\IntegrationRunner;
use App\Integration\Services\MappingService;
use App\Integration\Support\PackEstimator;
use App\Models\Bin;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOpsService;
use App\Services\Inventory\InventoryService;
use App\Services\Transport\GpsTrackingService;
use App\Support\AppError;
use App\Support\SnapshotImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Operational commands.
|   php artisan scm:reconcile [warehouseCode]        verify ledger = balances (exit code 1 on mismatch)
|   php artisan scm:import-snapshot <file> [--keep-passwords] [--append]
*/

// Live tracking: copies the telematics provider's last positions onto the vehicles. Runs every minute where a scheduler
// exists (`php artisan schedule:work` / cron); without one the map screens trigger the same throttled sync themselves.
Artisan::command('scm:gps-sync {--force}', function (GpsTrackingService $gps) {
    $r = $gps->sync(force: (bool) $this->option('force'));
    $this->line(json_encode($r, JSON_UNESCAPED_UNICODE));

    return ($r['ok'] ?? false) || ! ($r['ran'] ?? false) ? 0 : 1;
})->purpose('Sync vehicle positions from the GPS provider');
Schedule::command('scm:gps-sync')->everyMinute()->withoutOverlapping();

// Integration layer: retry due inbox events and deliver due outbox events (docs/integration/ARCHITECTURE.md §4, §8).
// Without a scheduler (Vercel) the same cycle runs from the signed POST /api/v1/ops/heartbeat.
Artisan::command('scm:integration-run {--limit=100}', function (IntegrationRunner $runner) {
    $r = $runner->run((int) $this->option('limit'), 'scheduler');
    $this->line(json_encode($r, JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Run one integration cycle (inbox retries + outbox deliveries)');
Schedule::command('scm:integration-run')->everyMinute()->withoutOverlapping();

// Pilot bootstrap (docs/integration/RUNBOOK.md §3): the other system sells products OPS does not have. Creates every
// still-unmapped mirrored product in OPS and links it — the same "create in OPS" action as the tower, in bulk — with
// physical attributes ESTIMATED from the pack text, and optionally posts a TRIAL opening balance (an audited manual
// adjustment whose reason says so). For pilots only: measured attributes and counted stock must replace both before
// real operation. Nothing is touched for products that are already mapped; --dry-run only prints the plan.
Artisan::command('scm:integration-adopt-products {system=sales} {--stock=0 : trial opening balance per product (units)} {--warehouse= : warehouse code (default: the integration default warehouse)} {--dry-run}', function (MappingService $mappings, InventoryOpsService $inventory) {
    $system = (string) $this->argument('system');
    $stock = max(0, (int) $this->option('stock'));
    $warehouse = Warehouse::where('code', mb_strtoupper((string) ($this->option('warehouse') ?: config('integration.default_warehouse'))))->first();
    if ($stock > 0 && ! $warehouse) {
        $this->error('warehouse not found');

        return 1;
    }
    $actor = IntegrationBootstrap::actor($system, 'console:adopt-products');
    $pending = IntMirror::where('system', $system)->where('entity', 'product')
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('int_external_refs as r')->whereColumn('r.external_id', 'int_mirror.external_id')->where('r.system', $system)->where('r.entity', 'product'))
        ->orderBy('external_id')->get();
    // storage bins of the warehouse per zone type, emptiest first — one bin per product, in turn
    $bins = [];
    $next = function (string $class) use (&$bins, $warehouse): ?string {
        $bins[$class] ??= Bin::where('bins.warehouse_id', $warehouse->id)->where('bins.status', 'active')->whereNull('bins.fixed_product_id')
            ->join('zones', 'zones.id', '=', 'bins.zone_id')->where('zones.type', $class)
            ->orderByRaw('(select count(*) from inventory_balances b where b.bin_id = bins.id)')->orderBy('bins.code')->pluck('bins.code')->all();
        if (! $bins[$class]) {
            return null;
        }
        $code = array_shift($bins[$class]);
        $bins[$class][] = $code;

        return $code;
    };
    $done = 0;
    foreach ($pending as $m) {
        $est = PackEstimator::estimate((string) $m->label, $m->data['unit'] ?? null, $m->data['category'] ?? null);
        $plan = "{$m->external_id} | {$m->label} | ".($m->data['unit'] ?? '—')." → {$est['weightKg']} kg, {$est['lengthCm']}×{$est['widthCm']}×{$est['heightCm']} cm, {$est['storageClass']}, weight from: {$est['basis']}";
        if ($this->option('dry-run')) {
            $this->line($plan);

            continue;
        }
        $r = $mappings->adopt($actor, $system, $m->external_id, $est + ['estimated' => true]);
        $line = "{$plan} → {$r['sku']}";
        if ($stock > 0) {
            $bin = $next($est['storageClass']);
            if ($bin === null) {
                $line .= " — no {$est['storageClass']} bin in {$warehouse->code}: no opening balance";
            } else {
                DB::transaction(fn () => $inventory->adjust($actor, ['sku' => $r['sku'], 'warehouseCode' => $warehouse->code, 'binCode' => $bin, 'qtyDelta' => $stock,
                    'reason' => 'رصيد افتتاحي تجريبي لتشغيل التكامل — يُصحَّح بالجرد قبل التشغيل الفعلي']));
                $line .= " — {$stock} @ {$warehouse->code}/{$bin}";
            }
        }
        $this->line($line);
        $done++;
    }
    $this->info($this->option('dry-run') ? count($pending).' product(s) would be created' : "{$done} product(s) created and linked".($stock > 0 ? " with a trial opening balance of {$stock}" : ''));

    return 0;
})->purpose('Pilot bootstrap: create the other system\'s unmapped products in OPS (estimated attributes) and link them');

// Access tables ← config/scm.php: inserts permissions a release introduced (with their default role grants); never removes.
Artisan::command('scm:sync-permissions', function () {
    $this->line(json_encode(\App\Support\PermissionSync::ensure(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Insert permissions missing from the database and grant them to their default roles');

// Finance — cut-over and period routines (docs/finance/ARCHITECTURE.ar.md §2). Nothing posts without --confirm.
Artisan::command('scm:finance-open-inventory {--confirm : post the opening journal and seed average costs} {--as-of= : journal date (default today)}', function (\App\Finance\Services\OpeningBalancesService $opening) {
    $r = $opening->valueInventory(null, $this->option('as-of') ?: null, (bool) $this->option('confirm'));
    $this->line(json_encode(['asOf' => $r['asOf'], 'products' => count(collect($r['rows'])->groupBy('productId')), 'total' => $r['total'], 'perWarehouse' => $r['perWarehouse'], 'skipped' => count($r['skipped']), 'posted' => $r['posted'], 'journal' => $r['journal'] ?? null], JSON_UNESCAPED_UNICODE));
    foreach ($r['skipped'] as $s) {
        $this->warn("skipped {$s['sku']} ({$s['qty']}): {$s['reason']}");
    }
    if (! $r['posted']) {
        $this->info('dry run — add --confirm after the CFO approves the valuation basis');
    }

    return 0;
})->purpose('Value pre-ledger stock at purchase price with one opening journal (dry run unless --confirm)');

Artisan::command('scm:finance-depreciate {period? : YYYY-MM, default = last month}', function (\App\Finance\Services\FixedAssetsService $assets) {
    $period = $this->argument('period') ?: now()->subMonth()->format('Y-m');
    $actor = \App\Integration\IntegrationBootstrap::actor('scheduler', 'console:finance-depreciate');
    $this->line(json_encode($assets->depreciate($actor, $period), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Post straight-line depreciation of every active asset up to the period');
Schedule::command('scm:finance-depreciate')->monthlyOn(1, '02:00');

Artisan::command('scm:finance-check', function (\App\Finance\Services\FinanceDashboardService $dash) {
    $r = $dash->reconciliation();
    $this->line(json_encode($r, JSON_UNESCAPED_UNICODE));
    $ok = $r['trialBalanceBalanced'] && collect($r['checks'])->every(fn ($c) => $c['ok']);
    $this->{$ok ? 'info' : 'error'}($ok ? 'finance OK: trial balance balanced, sub-ledgers = GL' : 'finance MISMATCH — see checks');

    return $ok ? 0 : 1;
})->purpose('Trial balance + sub-ledger (AR / AP / inventory) vs general ledger check (exit 1 on mismatch)');

// Procurement follow-up: late purchase orders and expiring supplier documents → one notification each to procurement.
Artisan::command('scm:procurement-followup', function (\App\Services\Procurement\ProcurementHousekeeping $hk) {
    $this->line(json_encode($hk->runNow(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Alert procurement about late POs and expiring supplier documents');
Schedule::command('scm:procurement-followup')->dailyAt('07:00');

Artisan::command('scm:reconcile {warehouse? : warehouse code, e.g. RYD}', function (InventoryService $inventory) {
    $warehouseId = null;
    if ($code = $this->argument('warehouse')) {
        $warehouseId = Warehouse::where('code', $code)->value('id');
        if (! $warehouseId) {
            $this->error("warehouse {$code} not found");

            return 1;
        }
    }
    $result = $inventory->reconcile($warehouseId);
    $this->line('checked '.$result['checked'].' balance rows — '.($result['ok'] ? 'OK: ledger = balances' : count($result['mismatches']).' MISMATCH(ES)'));
    foreach ($result['mismatches'] as $m) {
        $this->line("  {$m['sku']} @ {$m['bin']} batch {$m['batch']}: onHand {$m['onHand']} vs ledger {$m['ledger']}; reserved {$m['reserved']} vs allocations {$m['reservedExpected']}");
    }

    return $result['ok'] ? 0 : 1;
})->purpose('Verify that the inventory ledger equals the balances');

Artisan::command('scm:import-snapshot {file} {--keep-passwords : keep imported password hashes (live migration)} {--append : do not truncate tables first}', function (SnapshotImporter $importer) {
    $password = $this->option('keep-passwords') ? null : config('scm_auth.seed_password');
    if (! $this->option('keep-passwords') && ! $password) {
        $this->error('Set SEED_PASSWORD in .env, or pass --keep-passwords.');

        return 1;
    }
    $counts = $importer->import($this->argument('file'), $password, ! $this->option('append'));
    $this->info('imported '.array_sum($counts).' rows into '.count(array_filter($counts)).' tables');

    return 0;
})->purpose('Load a snapshot produced by tools/snapshot-reference-db.mjs (data migration from the old system)');

// ── Sales housekeeping (docs/ops-hardening/W4-sales.md) ──
// customers.balance is the customer's exposure (unpaid invoices + accepted orders not yet delivered), kept by
// CreditService::sync whenever one of its sources changes. This rewrites EVERY balance from those sources: run it once
// when the 2026-11 hardening is deployed (the column used to be a counter that only grew), and nightly as a safety net.
Artisan::command('scm:credit-sync', function (\App\Services\Sales\CreditService $credit) {
    $r = $credit->syncAll(fn (string $code, float $before, float $after) => $this->line("{$code}: {$before} → {$after}"));
    $this->info("{$r['checked']} customers checked, {$r['changed']} balances rewritten");

    return 0;
})->purpose('Rewrite every customer balance from its sources (unpaid invoices + open orders)');
Schedule::command('scm:credit-sync')->dailyAt('02:30');

// Quotations past their validity date → expired (send / approve / convert refuse them anyway; this keeps the lists honest).
Artisan::command('scm:quotations-expire', function (\App\Services\Sales\SalesService $sales) {
    $this->line(json_encode($sales->expireQuotations(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Move open quotations whose validity date has passed to expired');
Schedule::command('scm:quotations-expire')->dailyAt('00:10');

// Orders of ours that wait for stock (backorders, orders accepted with nothing available, orders put back by the
// warehouse) get it oldest first as soon as it is there. Orders of other systems: scm:integration-run (topUp).
Artisan::command('scm:backorders-allocate {--limit=50}', function (\App\Services\Sales\SalesService $sales) {
    $this->line(json_encode($sales->retryBackorders(max(1, (int) $this->option('limit'))), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Reserve stock for the OPS orders that wait for it (oldest first)');
Schedule::command('scm:backorders-allocate')->everyTenMinutes()->withoutOverlapping();

// Driver and vehicle documents: a licence or iqama that expired overnight blocks its driver before the first trip is planned.
Artisan::command('scm:fleet-documents', function (\App\Services\Transport\FleetService $fleet) {
    $this->line(json_encode($fleet->refreshDocumentAlerts(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Refresh driver / vehicle document alerts and the blocks that follow from them');
Schedule::command('scm:fleet-documents')->dailyAt('05:00');

// People & expenses (app/Hr): an identity paper or a contract about to end is told once per stage (60 / 30 / 7 days,
// expired). The list also refreshes itself when it is opened; this keeps the alerts coming when nobody opens it.
Artisan::command('scm:hr-alerts', function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('hr_alerts')) {
        $this->line('not installed');

        return 0;
    }
    $this->line(json_encode(app(\App\Hr\Services\AlertsService::class)->refresh(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Refresh the alerts of employees\' papers and contracts about to expire');
Schedule::command('scm:hr-alerts')->dailyAt('05:10');

// Tax & e-invoicing (app/Tax): the e-invoice of every invoice that does not have one yet, then what waits to be sent
// to the tax authority (nothing is sent while the e-invoicing unit is not onboarded).
Schedule::command('scm:tax-einvoices --seconds=40')->everyFiveMinutes()->withoutOverlapping();
// Demand & purchase planning (app/Planning): a large forecast run is computed in slices; this finishes the ones a
// person started, also when nobody keeps the page open.
Schedule::command('scm:planning-compute --seconds=50')->everyMinute()->withoutOverlapping();

// ── Odoo → OPS live mirror (docs/odoo/ARCHITECTURE.ar.md). Read-only: nothing in Odoo is ever changed from here. ──
Artisan::command('scm:odoo-status', function (OdooMirror $mirror) {
    $s = $mirror->status(true);
    if (! $s['installed']) {
        $this->warn('the odoo_* tables do not exist on this database yet: run php artisan migrate');

        return 1;
    }
    $c = $s['connection'];
    $this->line('connection: '.($c['ok'] ? "OK — Odoo {$c['version']} ({$c['transport']}) at {$c['host']}, user ".($c['user']['name'] ?? '?') : 'NOT OK — '.($c['error'] ?? 'not configured')));
    $this->line('models: '.json_encode($s['models']));
    $this->line('first copy: '.json_encode($s['firstCopy']));
    $this->line('changes: '.json_encode($s['changes']));
    $this->line('freshness: '.json_encode($s['freshness']));

    return $c['ok'] ? 0 : 1;
})->purpose('Odoo mirror: connection, how far the copy is, what changed');

// One run by default (what the scheduler calls). --loop keeps running until nothing is pending: the first full copy,
// done from a workstation or a server with time, never from a serverless request.
Artisan::command('scm:odoo-sync {--seconds=50} {--model=*} {--loop} {--max-minutes=240}', function (OdooSync $sync) {
    $seconds = max(3, (int) $this->option('seconds'));
    $only = $this->option('model') ?: null;
    $until = microtime(true) + 60 * max(1, (int) $this->option('max-minutes'));
    do {
        $r = $sync->run($seconds, 'command', $only);
        if (! $r['ran']) {
            // the scheduler calls this every minute on every installation: where the mirror is not switched on, or
            // another run still holds the lock, there is simply nothing to do — that is not a failure
            $this->line('not run: '.$r['reason']);

            return 0;
        }
        $p = $r['pending'];
        $this->line(sprintf('%s  %5d ms  calls %-4d pulled %-6d created %-6d updated %-5d deleted %-4d errors %-2d | to inspect %d · first copy %d · open windows %d%s',
            now()->format('H:i:s'), $r['ms'], $r['calls'], $r['pulled'], $r['created'], $r['updated'], $r['deleted'], $r['errors'], $p['toInspect'], $p['firstCopy'], $p['windows'],
            $r['error'] ? ' | '.$r['error'] : ''));
        $more = $this->option('loop') && ! $r['error'] && ($p['toInspect'] + $p['firstCopy'] + $p['windows']) > 0 && microtime(true) < $until;
    } while ($more);

    return $r['error'] ? 1 : 0;
})->purpose('Odoo mirror: read what changed in Odoo since the last run (--loop: until the first copy is complete)');
Schedule::command('scm:odoo-sync --seconds=50')->everyMinute()->withoutOverlapping();

Artisan::command('scm:odoo-discover', function (OdooSync $sync) {
    $this->line(json_encode($sync->discover()));

    return 0;
})->purpose('Odoo mirror: list the models of the Odoo instance and register the new ones');

// ── the copy as a backup ──
// Is the copy what Odoo holds? Counts per model and a sample of records read again (OdooVerify). --heal reads a model
// again in place when values differ without Odoo having stamped the record, or ids are missing.
Artisan::command('scm:odoo-verify {--model=*} {--sample=} {--heal}', function (OdooVerify $verify) {
    $v = $verify->run('command', $this->option('model') ?: null, $this->option('sample') !== null ? (int) $this->option('sample') : null, (bool) $this->option('heal'));
    $this->line(sprintf('%s — models %d · in Odoo %s · in the copy %s · missing %d · no longer in Odoo %d · sampled %d · differing %d',
        $v['complete'] ? 'COMPLETE' : 'NOT COMPLETE', $v['models'], number_format($v['recordsOdoo']), number_format($v['recordsCopy']), $v['missing'], $v['extra'], $v['sampled'], $v['differing']));
    foreach (($v['detail']['models'] ?? []) as $model => $row) {
        $this->line('  '.$model.': '.json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    foreach (['stillCopying' => 'models still being copied', 'onHold' => 'models on hold', 'unreadable' => 'models Odoo did not answer for'] as $k => $label) {
        if (! empty($v['detail'][$k])) {
            $this->line("  {$label}: {$v['detail'][$k]}");
        }
    }

    return $v['complete'] ? 0 : 1;
})->purpose('Odoo mirror: compare the copy with Odoo (counts per model and a sample of records)');
Schedule::command('scm:odoo-verify --heal')->dailyAt('03:30')->withoutOverlapping();
// The index of the Odoo archive (App\Integration\Odoo\Archive) follows the mirror by itself after every page it writes;
// this is the safety net for anything that slipped through, and what finishes a first build in slices. Two seconds
// when nothing changed.
Schedule::command('scm:odoo-archive-index --seconds=45')->hourly()->withoutOverlapping();

// The content of Odoo's attachments onto a disk of OPS (OdooFiles): resumable, checked against Odoo's checksums.
Artisan::command('scm:odoo-files {--seconds=50} {--loop} {--max-minutes=600} {--retry}', function (OdooFiles $files) {
    @ini_set('memory_limit', '1024M');
    if ($this->option('retry')) {
        $this->line('failed files to look at again: '.$files->retryFailed());
    }
    $until = microtime(true) + 60 * max(1, (int) $this->option('max-minutes'));
    do {
        $r = $files->run(max(3, (int) $this->option('seconds')));
        if (! $r['ran']) {
            $this->line('not run: '.$r['reason']);

            return 0;
        }
        $this->line(sprintf('%s  stored %-5d %8.1f MB  skipped %-4d failed %-3d left %d%s', now()->format('H:i:s'), $r['stored'], $r['bytes'] / 1048576, $r['skipped'], $r['failed'], $r['left'], isset($r['error']) ? ' | '.$r['error'] : ''));
    } while ($this->option('loop') && empty($r['error']) && $r['left'] > 0 && microtime(true) < $until);

    return 0;
})->purpose('Odoo mirror: keep the content of Odoo\'s attachments on a disk of OPS (--loop: until none is left)');
Schedule::command('scm:odoo-files --seconds=50')->everyFiveMinutes()->withoutOverlapping();

// The copy as files for a safe (OdooExport): one gzip JSON-lines file per model and a manifest with checksums.
Artisan::command('scm:odoo-export {dir? : default storage/app/private/odoo-export/<date>} {--model=*}', function (OdooExport $export) {
    $dir = $this->argument('dir') ?: storage_path('app/private/odoo-export/'.now()->format('Y-m-d_His'));
    $m = $export->export($dir, $this->option('model') ?: null, fn (string $model, int $n) => $this->line(sprintf('  %-40s %s', $model, number_format($n))));
    $this->line('exported '.number_format($m['records']).' records of '.count($m['models'])." models to {$dir}");

    return 0;
})->purpose('Odoo mirror: write the whole copy as portable files (gzip JSON lines per model + manifest)');

Artisan::command('scm:odoo-import-archive {dir} {--check : only check the files against the manifest} {--force : load over a mirror that is not empty}', function (OdooExport $export) {
    if ($this->option('check')) {
        $c = $export->check($this->argument('dir'));
        $this->line(($c['ok'] ? 'OK' : 'DAMAGED').' — '.number_format($c['records']).' records in '.$c['models'].' files'.($c['bad'] ? ' · do not match: '.implode(', ', $c['bad']) : ''));

        return $c['ok'] ? 0 : 1;
    }
    $r = $export->import($this->argument('dir'), (bool) $this->option('force'));
    $this->line('loaded '.number_format($r['records']).' records of '.$r['models'].' models (registered as paused: "restored from an archive")');

    return 0;
})->purpose('Odoo mirror: check an exported archive, or load it into the mirror tables of this installation');

// The OPS sections that follow Odoo (config odoo.projections: approvals, CRM, people…). Without an area it lists them;
// with one it prints the plan, and with --apply it loads the whole area from the copy (idempotent — the first load of
// an area, and the way to bring it back in step). After that the runs keep it current from the change feed.
Artisan::command('scm:odoo-project {area?} {--apply}', function () {
    $all = array_filter((array) config('odoo.projections'), fn ($class) => class_exists($class));
    $area = $this->argument('area');
    if (! $area) {
        $states = \App\Integration\Odoo\Projection\OdooProjection::states();
        foreach ($all as $key => $class) {
            $p = app($class);
            $loaded = $states[$key]['firstAppliedAt'] ?? null;
            $this->line(sprintf('%-12s %-11s %-10s %-28s waiting %d', $key, $p->enabled() ? 'follows' : 'handed-over', $p->ready() ? 'ready' : 'not-copied',
                $loaded ? 'loaded '.substr((string) $loaded, 0, 16) : 'NOT LOADED (--apply once)', $p->ready() ? $p->waiting() : 0));
        }

        return 0;
    }
    if (! isset($all[$area])) {
        $this->warn("no such area: {$area}");

        return 1;
    }
    try {
        $out = $this->option('apply') ? app($all[$area])->apply() : app($all[$area])->plan();
    } catch (AppError $e) {
        // the copy of the area is not complete yet, its tables are not migrated, it was handed over: said, not thrown
        $this->warn("{$e->errorCode}: ".($e->messageEn ?? $e->messageAr));

        return 1;
    }
    $this->line(($out['dryRun'] ? 'PLAN (nothing changed)' : 'APPLIED').': '.json_encode($out['counts'], JSON_UNESCAPED_UNICODE));
    foreach ($out['notes'] as $what => $examples) {
        $this->line("  {$what}: ".implode(' | ', $examples));
    }

    return 0;
})->purpose('Odoo mirror: the OPS sections that follow Odoo — no area: where each stands; an area: its plan, or --apply for the first load');

// Odoo gained fields, or the field rules changed: the fields of the copied models are read again, and the models whose
// set of copied fields is no longer what the copy was made with have their records read again IN PLACE (nothing is
// dropped; the copy stays readable). No model named = every copied model.
Artisan::command('scm:odoo-reinspect {model?*}', function (OdooSync $sync) {
    $names = (array) $this->argument('model');
    $again = 0;
    foreach (OdooModel::where('status', 'active')->where('full_state', 'done')->when($names, fn ($q) => $q->whereIn('model', $names))->orderBy('priority')->orderBy('model')->get() as $m) {
        try {
            if ($sync->reinspect($m)) {
                $again++;
                $this->line("{$m->model}: its records are read again on the next runs");
            }
        } catch (Throwable $e) {
            $this->warn("{$m->model}: ".mb_substr($e->getMessage(), 0, 160));
        }
    }
    $this->line("models to read again: {$again}");

    return 0;
})->purpose('Odoo mirror: re-read the fields of the copied models and refresh the records where the copied fields changed');

// After the field policy changed (config odoo.never_fields / personal_fields / private): the copy of a model is dropped
// and made again under the rules as they stand now. Only the OPS copy is touched; the next runs read the model again.
Artisan::command('scm:odoo-recopy {model*}', function (OdooSync $sync) {
    foreach ((array) $this->argument('model') as $name) {
        $m = OdooModel::where('model', $name)->first();
        $this->line($m ? "{$name}: ".$sync->recopy($m).' records dropped from the copy — it is read again on the next runs' : "{$name}: not a registered model");
    }

    return 0;
})->purpose('Odoo mirror: drop the copy of the given models and copy them again under the current rules');

// Odoo's products, customers and suppliers → OPS master data (OdooMasterData). Without --apply it only prints the plan.
Artisan::command('scm:odoo-master {--apply}', function (OdooMasterData $master) {
    $out = $this->option('apply') ? $master->apply() : $master->plan();
    $this->line(($out['dryRun'] ? 'PLAN (nothing changed)' : 'APPLIED').': '.json_encode($out['counts'], JSON_UNESCAPED_UNICODE));
    foreach ($out['notes'] as $what => $examples) {
        $this->line("  {$what}: ".implode(' | ', $examples));
    }

    return 0;
})->purpose('Odoo mirror: bring products, customers and suppliers into the OPS master data (--apply to do it)');
