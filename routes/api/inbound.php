<?php

use App\Http\Controllers\Api\Inbound\InboundController;
use Illuminate\Support\Facades\Route;

// Inbound: expected shipments → arrival → inspection → GRN → putaway. Reads are open to every signed-in user.
Route::prefix('inbound')->group(function () {
    Route::get('shipments', [InboundController::class, 'shipments']);
    Route::get('shipments/scan/{code}', [InboundController::class, 'scan']);
    Route::get('shipments/{id}', [InboundController::class, 'shipment']);
    Route::post('shipments/{id}/arrive', [InboundController::class, 'arrive'])->middleware('perm:shipment.receive');
    Route::post('shipments/{id}/inspect', [InboundController::class, 'inspect'])->middleware('perm:shipment.receive');
    Route::post('shipments/{id}/cancel', [InboundController::class, 'cancel'])->middleware('perm:po.cancel');
    Route::post('shipments/{id}/grn', [InboundController::class, 'postGrn'])->middleware('perm:grn.post');

    Route::get('grns', [InboundController::class, 'grns']);
    Route::get('grns/{id}', [InboundController::class, 'grn']);

    Route::get('putaway', [InboundController::class, 'putaway']);
    Route::post('putaway/{id}/confirm', [InboundController::class, 'confirmPutaway'])->middleware('perm:putaway.confirm');
});
