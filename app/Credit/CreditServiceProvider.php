<?php

namespace App\Credit;

use App\Credit\Console\DeadlinesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * What the credit section adds besides its routes: the command of its clock (`php artisan scm:credit-deadlines`) and
 * its place in the scheduler (every fifteen minutes, never two at once).
 *
 * Registered by routes/api/credit.php, so no shared file names it. An installation that caches its routes
 * (`php artisan route:cache`) does not run route files: there, add this class to bootstrap/providers.php.
 */
class CreditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([DeadlinesCommand::class]);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('scm:credit-deadlines')->everyFifteenMinutes()->withoutOverlapping(30);
        });
    }
}
