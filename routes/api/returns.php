<?php

use App\Http\Controllers\Api\Returns\ReturnsController;
use Illuminate\Support\Facades\Route;

// Returns: pending → approved → received (RET-01) → inspect → decision (closed). Reads are open to every signed-in user.
Route::get('returns', [ReturnsController::class, 'index']);
Route::get('returns/{id}', [ReturnsController::class, 'show']);
Route::post('returns', [ReturnsController::class, 'store'])->middleware('perm:return.create');
Route::post('returns/{id}/approve', [ReturnsController::class, 'approve'])->middleware('perm:return.flow');
Route::post('returns/{id}/reject', [ReturnsController::class, 'reject'])->middleware('perm:return.flow');
Route::post('returns/{id}/receive', [ReturnsController::class, 'receive'])->middleware('perm:return.flow');
Route::post('returns/{id}/inspect', [ReturnsController::class, 'inspect'])->middleware('perm:return.flow');
Route::post('returns/{id}/decide', [ReturnsController::class, 'decide'])->middleware('perm:return.decide');
