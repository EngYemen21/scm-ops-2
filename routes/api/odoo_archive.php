<?php

use App\Integration\Odoo\Archive\ArchiveServiceProvider;
use App\Integration\Odoo\Archive\Http\ArchiveController;
use App\Integration\Odoo\Archive\Http\EnsureArchiveInstalled;
use Illuminate\Support\Facades\Route;

// «أرشيف أودو» (docs/odoo/WAVE2.md §3): everything the copy of Odoo holds, as documents — read-only.
// page.odoo opens it; a kind built on people's files also needs odoo.private (403 ODOO_PRIVATE, answered by the
// archive itself, which knows the kinds); the CSV of a list also needs report.export.
Route::prefix('odoo-archive')->middleware('perm:page.odoo')->group(function () {
    // answers on any installation, also one where the index does not exist yet (`ready: false`)
    Route::get('catalog', [ArchiveController::class, 'catalog']);
    Route::middleware(EnsureArchiveInstalled::class)->group(function () {
        Route::get('search', [ArchiveController::class, 'search']);
        Route::get('docs/{kind}', [ArchiveController::class, 'docs'])->where('kind', '[a-z0-9_]+');
        Route::get('docs/{kind}/export', [ArchiveController::class, 'export'])->where('kind', '[a-z0-9_]+')->middleware('perm:report.export');
        Route::get('docs/{kind}/{odooId}', [ArchiveController::class, 'doc'])->where('kind', '[a-z0-9_]+')->whereNumber('odooId');
        Route::get('partners/{odooId}', [ArchiveController::class, 'partner'])->whereNumber('odooId');
        Route::get('products/{odooId}', [ArchiveController::class, 'product'])->whereNumber('odooId');
    });
});

// The command that builds the index (`php artisan scm:odoo-archive-index`) is registered from here, with the archive's
// own provider, so that no shared file has to name it.
if (! app()->providerIsLoaded(ArchiveServiceProvider::class)) {
    app()->register(ArchiveServiceProvider::class);
}
