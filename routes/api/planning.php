<?php

use App\Planning\Http\PlansController;
use App\Planning\Http\RunsController;
use App\Planning\Http\SettingsController;
use App\Planning\PlanningServiceProvider;
use Illuminate\Support\Facades\Route;

/*
 * «تخطيط الطلب والشراء» — /api/planning (docs/odoo/WAVE4.md §Q2). Reading needs page.planning.
 *   forecast.manage       runs (create, edit, compute, freeze, archive, copy), the push of reorder settings, actuals,
 *                         budget scenarios and their allocation, the Odoo-warehouse mapping, criticality;
 *   purchaseplan.manage   purchase plans: make, edit, submit, close, cancel, turn into purchase requests (that step also
 *                         needs pr.create, checked by the service);
 *   purchaseplan.approve  approve or return a plan (never one he submitted);
 *   report.export         the Excel / CSV export.
 * Records that came from Odoo are read-only while the section follows Odoo (422 PLANNING_OWNED_BY_SOURCE).
 */
Route::prefix('planning')->middleware('perm:page.planning')->group(function () {
    Route::get('lookups', [SettingsController::class, 'lookups']);
    Route::get('runs', [RunsController::class, 'index']);
    Route::get('runs/{id}', [RunsController::class, 'show']);
    Route::get('runs/{id}/items', [RunsController::class, 'items']);
    Route::get('runs/{id}/items/{item}', [RunsController::class, 'item']);
    Route::get('runs/{id}/requirements', [RunsController::class, 'requirements']);
    Route::get('runs/{id}/patterns', [RunsController::class, 'patterns']);
    Route::get('runs/{id}/events', [RunsController::class, 'events']);
    Route::get('runs/{id}/export', [RunsController::class, 'export'])->middleware('perm:report.export');
    Route::get('runs/{id}/budgets', [PlansController::class, 'budgets']);
    Route::get('budgets/{id}', [PlansController::class, 'budget']);
    Route::get('budgets/{id}/allocations', [PlansController::class, 'allocations']);
    Route::get('plans', [PlansController::class, 'index']);
    Route::get('plans/{id}', [PlansController::class, 'show']);
    Route::get('warehouse-maps', [SettingsController::class, 'warehouseMaps']);
    Route::get('attributes/{entity}', [SettingsController::class, 'attributes'])->whereIn('entity', ['product', 'customer']);
    // pushing on a computation someone started changes nothing a person decided: anyone who reads the section may
    Route::post('runs/{id}/advance', [RunsController::class, 'advance']);

    Route::middleware('perm:forecast.manage')->group(function () {
        Route::post('runs', [RunsController::class, 'store']);
        Route::patch('runs/{id}', [RunsController::class, 'update']);
        Route::post('runs/{id}/compute', [RunsController::class, 'compute']);
        Route::post('runs/{id}/stop', [RunsController::class, 'stop']);
        Route::post('runs/{id}/freeze', [RunsController::class, 'freeze']);
        Route::post('runs/{id}/archive', [RunsController::class, 'archive']);
        Route::post('runs/{id}/restore', [RunsController::class, 'restore']);
        Route::post('runs/{id}/copy', [RunsController::class, 'copy']);
        Route::post('runs/{id}/push', [RunsController::class, 'push']);
        Route::post('runs/{id}/actuals', [RunsController::class, 'actuals']);
        Route::post('runs/{id}/budgets', [PlansController::class, 'storeBudget']);
        Route::patch('budgets/{id}', [PlansController::class, 'updateBudget']);
        Route::post('budgets/{id}/allocate', [PlansController::class, 'allocate']);
        Route::put('warehouse-maps/{odooId}', [SettingsController::class, 'mapWarehouse'])->whereNumber('odooId');
        Route::put('attributes/{entity}/{id}', [SettingsController::class, 'setAttribute'])->whereIn('entity', ['product', 'customer']);
    });

    Route::middleware('perm:purchaseplan.manage')->group(function () {
        Route::post('budgets/{id}/plans', [PlansController::class, 'store']);
        Route::patch('plans/{id}', [PlansController::class, 'update']);
        Route::patch('plans/{id}/lines/{line}', [PlansController::class, 'updateLine']);
        Route::post('plans/{id}/lines/{line}/cancel', [PlansController::class, 'cancelLine']);
        Route::post('plans/{id}/submit', [PlansController::class, 'submit']);
        Route::post('plans/{id}/close', [PlansController::class, 'close']);
        Route::post('plans/{id}/cancel', [PlansController::class, 'cancel']);
        Route::post('plans/{id}/request', [PlansController::class, 'request']);
    });

    Route::middleware('perm:purchaseplan.approve')->group(function () {
        Route::post('plans/{id}/approve', [PlansController::class, 'approve']);
        Route::post('plans/{id}/return', [PlansController::class, 'returnToDraft']);
    });
});

// The command that pushes large runs on (`php artisan scm:planning-compute`) is registered from here, with the
// section's own provider, so that no shared file has to name it.
if (! app()->providerIsLoaded(PlanningServiceProvider::class)) {
    app()->register(PlanningServiceProvider::class);
}
