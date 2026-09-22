<?php

use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Services\Transport\GpsTrackingService;
use App\Support\SnapshotImporter;
use Illuminate\Support\Facades\Artisan;
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
