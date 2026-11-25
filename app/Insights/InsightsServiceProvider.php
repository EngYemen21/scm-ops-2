<?php

namespace App\Insights;

use App\Insights\Services\BrandAlertsService;
use App\Insights\Services\ExpiryService;
use App\Insights\Services\OdooHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The section's commands and their schedule (registered here, not in routes/console.php):
 *   scm:insights-alerts    the eight brand alerts for every active brand owner          daily 06:00
 *   scm:insights-expiry    the expiry levels against the batches with stock               daily 06:10
 *   scm:insights-history   the copy of Odoo's customer invoices (first pass, then feed)   every 15 minutes, 50 s
 * Each is idempotent: running it twice, or on two machines at once, raises nothing twice.
 */
class InsightsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        Artisan::command('scm:insights-alerts {--owner= : one brand owner id}', function (BrandAlertsService $alerts) {
            if (! Schema::hasTable('ins_brand_alerts')) {
                $this->warn('insights tables not installed');

                return 0;
            }
            $out = $alerts->run(null, $this->option('owner') ?: null);
            $this->info("owners {$out['owners']} · raised {$out['raised']} ".json_encode($out['byType']));

            return 0;
        })->purpose('Brand dashboards: compute the eight alert rules (each occurrence raised once)');

        Artisan::command('scm:insights-expiry', function (ExpiryService $expiry) {
            if (! Schema::hasTable('ins_expiry_log')) {
                $this->warn('insights tables not installed');

                return 0;
            }
            $out = $expiry->run();
            $this->info("levels {$out['levels']} · announced {$out['announced']} ".json_encode($out['byLevel'], JSON_UNESCAPED_UNICODE));

            return 0;
        })->purpose('Expiry alert levels: announce each lot once per level');

        Artisan::command('scm:insights-history {--seconds=50} {--fresh : start the first pass again}', function (OdooHistory $history) {
            if (! $history->installed()) {
                $this->warn('insights tables not installed');

                return 0;
            }
            $out = $history->advance((float) $this->option('seconds'), (bool) $this->option('fresh'));
            $this->info(json_encode($out + ['status' => $history->status()]));

            return 0;
        })->purpose('Copy Odoo\'s customer invoices from the local mirror into the insights history (resumable)');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('scm:insights-alerts')->dailyAt('06:00')->withoutOverlapping();
            $schedule->command('scm:insights-expiry')->dailyAt('06:10')->withoutOverlapping();
            $schedule->command('scm:insights-history --seconds=50')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
