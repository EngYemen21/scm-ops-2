<?php

use App\Commissions\Http\CommissionsController;
use App\Commissions\Http\PlansController;
use App\Commissions\Http\StatementsController;
use Illuminate\Support\Facades\Route;

/*
 * «العمولات» — /api/commissions (docs/odoo/WAVE3.md, team P2).
 *   page.commissions     opens the section: a user reads HIS OWN statements, lines and summary
 *   commission.manage    plans, assignments, calculation, adjustments, submitting — and every statement
 *   commission.approve   approves a statement, sends it back, releases (or voids) its payout — and every statement
 * Reading a plan needs commission.manage or commission.approve (checked in the controller: a plan holds other people's
 * rates); sending a statement back is open to both (checked in the service). A record that came from Odoo is read-only
 * while the section follows Odoo (422 COMMISSION_OWNED_BY_SOURCE).
 */
Route::prefix('commissions')->group(function () {
    Route::middleware('perm:page.commissions')->group(function () {
        Route::get('lookups', [CommissionsController::class, 'lookups']);
        Route::get('summary', [CommissionsController::class, 'summary']);
        Route::get('statements', [StatementsController::class, 'index']);
        Route::get('statements/{id}', [StatementsController::class, 'show']);
        Route::get('statements/{id}/lines', [StatementsController::class, 'lines']);
        Route::get('lines', [StatementsController::class, 'allLines']);
        Route::get('plans', [PlansController::class, 'index']);
        Route::get('plans/{id}', [PlansController::class, 'show']);
        Route::post('statements/{id}/return', [StatementsController::class, 'sendBack']);
    });

    Route::middleware('perm:page.commissions,commission.manage')->group(function () {
        // plans
        Route::post('plans', [PlansController::class, 'store']);
        Route::patch('plans/{id}', [PlansController::class, 'update']);
        Route::post('plans/{id}/activate', [PlansController::class, 'activate']);
        Route::post('plans/{id}/archive', [PlansController::class, 'archive']);
        Route::post('plans/{id}/duplicate', [PlansController::class, 'duplicate']);
        // whose sale is it
        Route::get('assignments', [CommissionsController::class, 'assignments']);
        Route::put('assignments', [CommissionsController::class, 'setAssignment']);
        Route::post('assignments/{id}/remove', [CommissionsController::class, 'removeAssignment']);
        Route::get('unassigned', [CommissionsController::class, 'unassigned']);
        // calculation and a person's corrections
        Route::post('calculate', [CommissionsController::class, 'calculate']);
        Route::post('statements/{id}/recalculate', [StatementsController::class, 'recalculate']);
        Route::post('statements/{id}/adjustments', [StatementsController::class, 'adjust']);
        Route::post('lines/{id}/void', [StatementsController::class, 'voidLine']);
        Route::post('lines/{id}/exclude', [StatementsController::class, 'excludeLine']);
        Route::post('lines/{id}/include', [StatementsController::class, 'includeLine']);
        Route::post('statements/{id}/submit', [StatementsController::class, 'submit']);
    });

    Route::middleware('perm:page.commissions,commission.approve')->group(function () {
        Route::get('payout-options', [CommissionsController::class, 'payoutOptions']);
        Route::post('statements/{id}/approve', [StatementsController::class, 'approve']);
        Route::post('statements/{id}/payout', [StatementsController::class, 'payout']);
        Route::post('statements/{id}/void-payout', [StatementsController::class, 'voidPayout']);
    });
});
