<?php

namespace App\Services\Core;

use App\Services\Sales\CreditService;
use App\Services\Sales\SalesService;
use App\Services\Transport\FleetService;
use Illuminate\Support\Facades\Cache;

/**
 * Upkeep that needs no person, riding on the integration heartbeat (IntegrationRunner::run) — on the serverless
 * deployment there is no scheduler, the heartbeat is the only clock. Under a real scheduler the same work also has
 * its own commands (routes/console.php); both paths are idempotent, so running both is harmless.
 *
 *  - every 10 minutes: orders of ours that wait for stock get it, oldest first;
 *  - once a day:       quotations past their validity date become expired, driver / vehicle document alerts are
 *                      refreshed (an expired licence or iqama blocks the driver), every customer balance is rewritten
 *                      from its sources (safety net of CreditService::sync).
 *
 * A step that fails is reported and never stops the others or the integration run.
 */
class OpsHousekeeping
{
    private const TICK_KEY = 'ops:housekeeping:tick';

    private const DAY_KEY = 'ops:housekeeping:day:';

    /** @return array{ran:bool, backorders?:array, quotations?:array, fleetDocs?:array, credit?:array} */
    public function run(): array
    {
        $out = ['ran' => false];
        if (Cache::add(self::TICK_KEY, now()->toJSON(), now()->addMinutes(10))) {
            $out['ran'] = true;
            $out['backorders'] = $this->step(function () {
                $r = app(SalesService::class)->retryBackorders();

                return ['tried' => $r['tried'], 'completed' => $r['completed'], 'partial' => $r['partial']];
            });
        }
        if (Cache::add(self::DAY_KEY.now()->toDateString(), now()->toJSON(), now()->addDay())) {
            $out['ran'] = true;
            $out['quotations'] = $this->step(fn () => ['expired' => app(SalesService::class)->expireQuotations()['expired']]);
            $out['fleetDocs'] = $this->step(fn () => app(FleetService::class)->refreshDocumentAlerts());
            $out['credit'] = $this->step(fn () => app(CreditService::class)->syncAll());
        }

        return $out;
    }

    private function step(callable $work): array
    {
        try {
            return (array) $work();
        } catch (\Throwable $e) {
            report($e);

            return ['error' => class_basename($e)];
        }
    }
}
