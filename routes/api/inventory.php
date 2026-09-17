<?php

use App\Http\Controllers\Api\Inventory\CountsController;
use App\Http\Controllers\Api\Inventory\InventoryController;
use App\Http\Controllers\Api\Inventory\TransfersController;
use Illuminate\Support\Facades\Route;

// /api/inventory — balances, ledger, batches, adjustments, moves, transfers and counts.
// Reads are open to every signed-in user (reconciliation checks audit.view | inventory.adjust itself); changes need a permission.
Route::prefix('inventory')->group(function () {
    // read views
    Route::get('balances', [InventoryController::class, 'balances']);
    Route::get('balances/summary', [InventoryController::class, 'summary']);
    Route::get('products/{sku}/stock', [InventoryController::class, 'productStock']);
    Route::get('ledger', [InventoryController::class, 'ledger']);
    Route::get('ledger/{number}', [InventoryController::class, 'ledgerEntry']);
    Route::get('batches', [InventoryController::class, 'batches']);
    Route::get('reconciliation', [InventoryController::class, 'reconciliation']);
    Route::get('staging', [InventoryController::class, 'staging']);
    Route::get('trace/{referenceNumber}', [InventoryController::class, 'trace']);

    // manual operations
    Route::post('adjust', [InventoryController::class, 'adjust'])->middleware('perm:inventory.adjust');
    Route::post('quarantine', [InventoryController::class, 'quarantine'])->middleware('perm:inventory.adjust');
    Route::post('move', [InventoryController::class, 'move'])->middleware('perm:inventory.move');

    // transfers
    Route::get('transfers', [TransfersController::class, 'index']);
    Route::get('transfers/{id}', [TransfersController::class, 'show']);
    Route::post('transfers', [TransfersController::class, 'store'])->middleware('perm:inventory.transfer');
    Route::post('transfers/{id}/approve', [TransfersController::class, 'approve'])->middleware('perm:inventory.transfer.approve');
    Route::post('transfers/{id}/reject', [TransfersController::class, 'reject'])->middleware('perm:inventory.transfer.approve');
    Route::post('transfers/{id}/receive', [TransfersController::class, 'receive'])->middleware('perm:inventory.transfer');
    // submit | cancel | start-picking | picking-done | ship | close
    Route::post('transfers/{id}/{action}', [TransfersController::class, 'action'])->middleware('perm:inventory.transfer');

    // counts
    Route::get('counts', [CountsController::class, 'index']);
    Route::get('counts/{id}', [CountsController::class, 'show']);
    Route::post('counts', [CountsController::class, 'store'])->middleware('perm:inventory.count');
    Route::post('counts/{id}/start', [CountsController::class, 'start'])->middleware('perm:inventory.count');
    Route::post('counts/{id}/enter', [CountsController::class, 'enter'])->middleware('perm:inventory.count');
    Route::post('counts/{id}/complete', [CountsController::class, 'complete'])->middleware('perm:inventory.count');
    Route::post('counts/{id}/approve', [CountsController::class, 'approve'])->middleware('perm:inventory.adjust');
    Route::post('counts/{id}/close', [CountsController::class, 'close'])->middleware('perm:inventory.count');
});
