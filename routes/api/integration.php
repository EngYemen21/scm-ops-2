<?php

use App\Integration\Http\TowerController;
use Illuminate\Support\Facades\Route;

// Integration Control Tower (people, signed-in). The system-to-system gateway is /api/v1 (routes/integration_v1.php).
Route::prefix('integration')->middleware('perm:integration.view')->group(function () {
    Route::get('mappings/{entity}', [TowerController::class, 'mappings'])->whereIn('entity', ['product', 'customer']);
    Route::post('mappings/{entity}', [TowerController::class, 'map'])->whereIn('entity', ['product', 'customer'])->middleware('perm:integration.manage');
    Route::delete('mappings/{entity}/{externalId}', [TowerController::class, 'unmap'])->whereIn('entity', ['product', 'customer'])->middleware('perm:integration.manage');
});
