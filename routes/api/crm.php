<?php

use App\Crm\Http\ActivitiesController;
use App\Crm\Http\ConfigController;
use App\Crm\Http\LeadsController;
use Illuminate\Support\Facades\Route;

/*
 * «الفرص والعملاء المحتملون» — /api/crm (docs/odoo/WAVE2.md §6). Reading the section needs page.crm; every change
 * needs crm.manage. Two steps reach into another module and need its permission as well, checked in the service:
 * a NEW customer from a won opportunity (customer.manage) and a NEW quotation (sales.manage).
 * A record that came from Odoo is read-only while the section follows Odoo (422 CRM_OWNED_BY_SOURCE) — notes, to-dos,
 * the customer and the quotations of a lead are OPS's own and stay open.
 */
Route::prefix('crm')->group(function () {
    Route::middleware('perm:page.crm')->group(function () {
        Route::get('lookups', [LeadsController::class, 'lookups']);
        Route::get('board', [LeadsController::class, 'board']);
        Route::get('leads', [LeadsController::class, 'index']);
        Route::get('leads/{id}', [LeadsController::class, 'show']);
        Route::get('leads/{id}/customer', [LeadsController::class, 'customer']);
        Route::get('activities', [ActivitiesController::class, 'index']);
        Route::get('report/funnel', [LeadsController::class, 'funnel']);
    });

    Route::middleware('perm:crm.manage')->group(function () {
        // a lead and its way through the pipeline
        Route::post('leads', [LeadsController::class, 'store']);
        Route::patch('leads/{id}', [LeadsController::class, 'update']);
        Route::post('leads/{id}/move', [LeadsController::class, 'move']);
        Route::post('leads/{id}/win', [LeadsController::class, 'win']);
        Route::post('leads/{id}/lose', [LeadsController::class, 'lose']);
        Route::post('leads/{id}/reopen', [LeadsController::class, 'reopen']);
        Route::post('leads/{id}/notes', [LeadsController::class, 'note']);
        // what a won opportunity becomes
        Route::post('leads/{id}/convert', [LeadsController::class, 'convert']);
        Route::post('leads/{id}/quotations', [LeadsController::class, 'quote']);
        Route::post('leads/{id}/quotations/{quotation}/unlink', [LeadsController::class, 'unquote']);
        // to-dos
        Route::post('leads/{lead}/activities', [ActivitiesController::class, 'store']);
        Route::patch('activities/{id}', [ActivitiesController::class, 'update']);
        Route::post('activities/{id}/done', [ActivitiesController::class, 'done']);
        Route::post('activities/{id}/cancel', [ActivitiesController::class, 'cancel']);
        // what the pipeline is made of
        Route::post('stages', [ConfigController::class, 'storeStage']);
        Route::patch('stages/{id}', [ConfigController::class, 'updateStage']);
        Route::post('teams', [ConfigController::class, 'storeTeam']);
        Route::patch('teams/{id}', [ConfigController::class, 'updateTeam']);
        Route::post('lost-reasons', [ConfigController::class, 'storeReason']);
        Route::patch('lost-reasons/{id}', [ConfigController::class, 'updateReason']);
        Route::post('tags', [ConfigController::class, 'storeTag']);
        Route::patch('tags/{id}', [ConfigController::class, 'updateTag']);
    });
});
