<?php

use App\Integration\Http\TowerController;
use Illuminate\Support\Facades\Route;

// Integration Control Tower (people, signed-in). The system-to-system gateway is /api/v1 (routes/integration_v1.php).
// Viewing needs integration.view; anything that changes state (retry, replay, resolve, map, run) integration.manage.
Route::prefix('integration')->middleware('perm:integration.view')->group(function () {
    Route::get('overview', [TowerController::class, 'overview']);
    Route::get('inbox', [TowerController::class, 'inbox']);
    Route::get('deliveries', [TowerController::class, 'deliveries']);
    Route::get('exceptions', [TowerController::class, 'exceptions']);
    Route::get('events/{id}', [TowerController::class, 'payload']);
    Route::get('trace/{key}', [TowerController::class, 'trace']);
    Route::post('inbox/{id}/replay', [TowerController::class, 'replay'])->middleware('perm:integration.manage');
    Route::post('deliveries/{id}/retry', [TowerController::class, 'retry'])->middleware('perm:integration.manage');
    Route::post('exceptions/{id}/resolve', [TowerController::class, 'resolve'])->middleware('perm:integration.manage');
    Route::post('run', [TowerController::class, 'run'])->middleware('perm:integration.manage');
    Route::post('reconcile', [TowerController::class, 'reconcile'])->middleware('perm:integration.manage');

    Route::get('mappings/{entity}', [TowerController::class, 'mappings'])->whereIn('entity', ['product', 'customer']);
    Route::post('mappings/{entity}', [TowerController::class, 'map'])->whereIn('entity', ['product', 'customer'])->middleware('perm:integration.manage');
    // create the other system's product in OPS and link it — also needs the right to manage products
    Route::post('mappings/product/adopt', [TowerController::class, 'adopt'])->middleware('perm:integration.manage,product.manage');
    Route::delete('mappings/{entity}/{externalId}', [TowerController::class, 'unmap'])->whereIn('entity', ['product', 'customer'])->middleware('perm:integration.manage');
});
