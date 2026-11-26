<?php

use App\Insights\Http\AlertsController;
use App\Insights\Http\BrandsController;
use App\Insights\Http\ExpiryController;
use App\Insights\Http\OperationsController;
use Illuminate\Support\Facades\Route;

/*
 * «العلامات والصلاحية والتحليلات» — /api/insights (docs/odoo/WAVE4.md §Q6). Everything needs page.insights.
 * Brand owners: a holder of brandowner.manage sees and changes all; a user linked to a brand owner sees his brand only
 * (and only what its access flags allow), nothing else of the section (403 INSIGHTS_OUTSIDER) — App\Insights\Support\Access,
 * enforced in the services. Expiry levels are changed with expiryalert.manage. Finance figures need fin.report, the
 * stock card export report.export (checked in the controller).
 */
Route::prefix('insights')->middleware('perm:page.insights')->group(function () {
    Route::get('me', [BrandsController::class, 'me']);

    // brand owners, franchisees, dashboards
    Route::get('brands', [BrandsController::class, 'index']);
    Route::get('brands/{id}', [BrandsController::class, 'show']);
    Route::get('brands/{id}/dashboard', [BrandsController::class, 'dashboard']);
    Route::get('brands/{id}/lines', [BrandsController::class, 'lines']);
    Route::get('alerts', [AlertsController::class, 'index']);
    Route::post('alerts/{id}/read', [AlertsController::class, 'read']);
    Route::post('alerts/{id}/unread', [AlertsController::class, 'unread']);
    Route::post('brands/{owner}/alerts/read-all', [AlertsController::class, 'readAll']);
    Route::get('history', [BrandsController::class, 'history']);

    Route::middleware('perm:brandowner.manage')->group(function () {
        Route::get('lookups', [BrandsController::class, 'lookups']);
        Route::post('brands', [BrandsController::class, 'store']);
        Route::patch('brands/{id}', [BrandsController::class, 'update']);
        Route::post('brands/{id}/state', [BrandsController::class, 'transition']);
        Route::post('brands/{id}/franchisees', [BrandsController::class, 'addFranchisee']);
        Route::patch('franchisees/{id}', [BrandsController::class, 'updateFranchisee']);
        Route::post('franchisees/{id}/status', [BrandsController::class, 'franchiseeStatus']);
        Route::get('alert-rules', [AlertsController::class, 'rules']);
        Route::put('alert-rules/{type}', [AlertsController::class, 'updateRule']);
        Route::post('alerts/run', [AlertsController::class, 'run']);
        Route::put('history/cutover', [BrandsController::class, 'setCutover']);
        Route::post('history/sync', [BrandsController::class, 'syncHistory']);
    });

    // expiry alert levels
    Route::get('expiry/levels', [ExpiryController::class, 'levels']);
    Route::get('expiry/log', [ExpiryController::class, 'log']);
    Route::get('expiry/upcoming', [ExpiryController::class, 'upcoming']);
    Route::middleware('perm:expiryalert.manage')->group(function () {
        Route::get('expiry/recipients', [ExpiryController::class, 'recipients']);
        Route::post('expiry/levels', [ExpiryController::class, 'store']);
        Route::patch('expiry/levels/{id}', [ExpiryController::class, 'update']);
        Route::post('expiry/run', [ExpiryController::class, 'run']);
    });

    // stock card and operations insights
    Route::get('stock-card', [OperationsController::class, 'stockCard']);
    Route::get('stock-card/export', [OperationsController::class, 'stockCardExport']);
    Route::get('operations/summary', [OperationsController::class, 'summary']);
    Route::get('operations/slow-moving', [OperationsController::class, 'slowMoving']);
    Route::get('operations/expiry-buckets', [OperationsController::class, 'expiryBuckets']);
    Route::get('operations/customer-margin', [OperationsController::class, 'customerMargin']);
    Route::get('operations/pnl', [OperationsController::class, 'pnl']);
    Route::get('operations/ageing', [OperationsController::class, 'ageing']);
    Route::get('operations/invoice-counts', [OperationsController::class, 'invoiceCounts']);
});
