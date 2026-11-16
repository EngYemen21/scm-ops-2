<?php

use App\Http\Controllers\Api\Fulfillment\FulfillmentController;
use Illuminate\Support\Facades\Route;

// Fulfillment execution: pick → pack → loading plan → load → dispatch, and the way back from each step
// (put-back, unpack, unload). Reads are open; every scan / confirmation / correction needs its permission.
Route::prefix('fulfillment')->group(function () {
    Route::get('orders', [FulfillmentController::class, 'index']);
    Route::get('orders/{id}', [FulfillmentController::class, 'show']);
    Route::get('staging', [FulfillmentController::class, 'staging']);

    // picking: who holds a list, scan-confirm, short pick, batch substitution, put-back
    Route::get('pick-lists', [FulfillmentController::class, 'pickLists']);
    Route::post('pick-lists/{id}/claim', [FulfillmentController::class, 'claim'])->middleware('perm:pick.confirm');
    Route::post('pick-lists/{id}/release', [FulfillmentController::class, 'release'])->middleware('perm:pick.confirm');
    Route::post('pick-lists/{id}/assign', [FulfillmentController::class, 'assign'])->middleware('perm:pick.override');
    Route::post('pick-tasks/{id}/confirm', [FulfillmentController::class, 'pick'])->middleware('perm:pick.confirm');
    Route::post('pick-tasks/{id}/short', [FulfillmentController::class, 'short'])->middleware('perm:pick.confirm');
    Route::post('pick-tasks/{id}/substitute', [FulfillmentController::class, 'substitute'])->middleware('perm:pick.confirm');
    Route::post('orders/{id}/putback', [FulfillmentController::class, 'putback'])->middleware('perm:pick.override');

    // packing: cartons, correction, unpack
    Route::post('orders/{id}/pack', [FulfillmentController::class, 'pack'])->middleware('perm:pack.confirm');
    Route::post('orders/{id}/unpack', [FulfillmentController::class, 'unpack'])->middleware('perm:pack.confirm');
    Route::patch('orders/{id}/package', [FulfillmentController::class, 'editPackage'])->middleware('perm:pack.confirm');

    // loading and dispatch
    Route::get('trips/{trip}/loading', [FulfillmentController::class, 'loadingPlan']);
    Route::post('trips/{trip}/dock', [FulfillmentController::class, 'dock'])->middleware('perm:load.confirm');
    Route::post('trips/{trip}/load', [FulfillmentController::class, 'load'])->middleware('perm:load.confirm');
    Route::post('trips/{trip}/unload', [FulfillmentController::class, 'unload'])->middleware('perm:load.confirm');
    Route::post('trips/{trip}/dispatch', [FulfillmentController::class, 'dispatch'])->middleware('perm:trip.dispatch');
});
