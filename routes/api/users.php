<?php

use App\Http\Controllers\Api\Users\UsersController;
use Illuminate\Support\Facades\Route;

// User administration — everything here needs user.manage, reads included (as in the reference).
Route::middleware('perm:user.manage')->group(function () {
    Route::get('users', [UsersController::class, 'index']);
    Route::get('users/roles', [UsersController::class, 'roles']);
    Route::get('users/permissions', [UsersController::class, 'permissions']);
    Route::post('users', [UsersController::class, 'store']);
    Route::patch('users/{id}', [UsersController::class, 'update']);
    Route::put('users/roles/{key}/permissions', [UsersController::class, 'setRolePermissions']);
});
