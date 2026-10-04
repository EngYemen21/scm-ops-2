<?php

use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntMirror;
use App\Integration\Services\IntegrationRunner;
use App\Integration\Services\MappingService;
use App\Integration\Support\PackEstimator;
use App\Models\Bin;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOpsService;
use App\Services\Inventory\InventoryService;
use App\Services\Transport\GpsTrackingService;
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
