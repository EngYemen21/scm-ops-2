<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Archive\Console\ArchiveIndexCommand;
use Illuminate\Support\ServiceProvider;

/**
 * What the Odoo archive adds to the application besides its routes: the command that builds its index
 * (`php artisan scm:odoo-archive-index`). The services of the archive are singletons by themselves (#[Singleton]).
 *
 * Registered by routes/api/odoo_archive.php, so no shared file names it. An installation that caches its routes
 * (`php artisan route:cache`) does not run route files: there, add this class to bootstrap/providers.php.
 */
class ArchiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([ArchiveIndexCommand::class]);
    }
}
