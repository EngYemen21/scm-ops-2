<?php

use App\Http\Controllers\Api\Delivery\DeliveryController;
use Illuminate\Support\Facades\Route;

// Driver app + proof of delivery. A driver only reaches trips assigned to their own driver record (enforced in the service).
Route::prefix('delivery')->group(function () {
    Route::get('my-trips', [DeliveryController::class, 'myTrips']);
    Route::get('trips/{id}', [DeliveryController::class, 'trip']);
    Route::post('trips/{id}/start', [DeliveryController::class, 'start'])->middleware('perm:delivery.execute');
    Route::post('stops/{id}/arrive', [DeliveryController::class, 'arrive'])->middleware('perm:delivery.execute');
    Route::post('stops/{id}/deliver', [DeliveryController::class, 'deliver'])->middleware('perm:delivery.execute');
    Route::post('stops/{id}/partial', [DeliveryController::class, 'partial'])->middleware('perm:delivery.execute');
    Route::post('stops/{id}/fail', [DeliveryController::class, 'fail'])->middleware('perm:delivery.execute');
    Route::get('pods', [DeliveryController::class, 'pods']);
    Route::get('pods/{id}', [DeliveryController::class, 'pod']);
});
