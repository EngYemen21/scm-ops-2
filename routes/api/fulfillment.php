<?php

use App\Http\Controllers\Api\Fulfillment\FulfillmentController;
use Illuminate\Support\Facades\Route;

// Fulfillment execution: pick → pack → loading plan → load → dispatch. Reads are open; every scan/confirmation needs its permission.
Route::prefix('fulfillment')->group(function () {
    Route::get('orders', [FulfillmentController::class, 'index']);
    Route::get('orders/{id}', [FulfillmentController::class, 'show']);
    Route::get('pick-lists', [FulfillmentController::class, 'pickLists']);
    Route::post('pick-tasks/{id}/confirm', [FulfillmentController::class, 'pick'])->middleware('perm:pick.confirm');
    Route::post('pick-tasks/{id}/short', [FulfillmentController::class, 'short'])->middleware('perm:pick.confirm');
    Route::post('orders/{id}/pack', [FulfillmentController::class, 'pack'])->middleware('perm:pack.confirm');
    Route::get('trips/{trip}/loading', [FulfillmentController::class, 'loading']);
    Route::post('trips/{trip}/load', [FulfillmentController::class, 'load'])->middleware('perm:load.confirm');
    Route::post('trips/{trip}/dispatch', [FulfillmentController::class, 'dispatch'])->middleware('perm:trip.dispatch');
});
