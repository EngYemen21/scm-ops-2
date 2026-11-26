<?php

namespace App\Planning;

use App\Planning\Console\PlanningComputeCommand;
use Illuminate\Support\ServiceProvider;

/**
 * What the planning section adds besides its routes: the command that pushes large runs on
 * (`php artisan scm:planning-compute`). Registered by routes/api/planning.php, so no shared file names it; an
 * installation that caches its routes adds this class to bootstrap/providers.php (see the section's report).
 */
class PlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([PlanningComputeCommand::class]);
    }
}
