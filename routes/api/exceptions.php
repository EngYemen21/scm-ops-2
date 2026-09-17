<?php

use App\Http\Controllers\Api\ExceptionsController;
use Illuminate\Support\Facades\Route;

// Operational exceptions. Reading is open to every signed-in user; changes need a permission.
Route::get('exceptions', [ExceptionsController::class, 'index']);
Route::get('exceptions/{id}', [ExceptionsController::class, 'show']);
Route::post('exceptions', [ExceptionsController::class, 'store'])->middleware('perm:exception.create');
Route::post('exceptions/{id}/ack', [ExceptionsController::class, 'ack'])->middleware('perm:exception.manage');
Route::post('exceptions/{id}/resolve', [ExceptionsController::class, 'resolve'])->middleware('perm:exception.manage');
