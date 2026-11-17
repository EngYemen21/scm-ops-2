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
    // the follow-up shipment of a partially received (or cancelled) delivery: { po, eta? }
    Route::post('shipments/backorder', [InboundController::class, 'backorder'])->middleware('perm:shipment.receive', 'idempotent');
    Route::post('shipments/{id}/cancel', [InboundController::class, 'cancel'])->middleware('perm:po.cancel');
    // exits once the truck is at the gate: the arrival was a wrong click · the whole delivery is turned away { reason }
    Route::post('shipments/{id}/unarrive', [InboundController::class, 'unarrive'])->middleware('perm:shipment.receive');
    Route::post('shipments/{id}/refuse', [InboundController::class, 'refuse'])->middleware('perm:shipment.receive');
    Route::post('shipments/{id}/grn', [InboundController::class, 'postGrn'])->middleware('perm:grn.post');

    Route::get('grns', [InboundController::class, 'grns']);
    Route::get('grns/{id}', [InboundController::class, 'grn']);
    // a receipt posted wrongly, while nothing of it was put away or invoiced: { reason }
    Route::post('grns/{id}/void', [InboundController::class, 'voidGrn'])->middleware('perm:grn.void');

    Route::get('putaway', [InboundController::class, 'putaway']);
    Route::post('putaway/{id}/confirm', [InboundController::class, 'confirmPutaway'])->middleware('perm:putaway.confirm');
});
