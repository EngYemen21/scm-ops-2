<?php

use App\Credit\CreditServiceProvider;
use App\Insights\InsightsServiceProvider;
use App\Integration\Odoo\Archive\ArchiveServiceProvider;
use App\Planning\PlanningServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // the sections built for what Odoo has: their commands and schedules (also where routes are cached)
    ArchiveServiceProvider::class,   // scm:odoo-archive-index
    CreditServiceProvider::class,    // scm:credit-deadlines, every 15 minutes
    PlanningServiceProvider::class,  // scm:planning-compute
    InsightsServiceProvider::class,  // scm:insights-alerts, scm:insights-expiry, scm:insights-history
];
