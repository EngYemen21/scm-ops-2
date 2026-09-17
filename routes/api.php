<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
| API routes (prefix /api). The contract — paths, payloads, error shape — is shared with the reference system.
|
| Conventions:
|  - everything except the three session endpoints sits behind `auth.api` + `idempotent`;
|  - each mutation declares its permission with `perm:<key>` (see config/scm.php PERMISSIONS);
|  - one route file per domain under routes/api/, required at the bottom of the protected group.
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth.api', 'idempotent'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    foreach (glob(__DIR__.'/api/*.php') as $domainRoutes) {
        require $domainRoutes;
    }
});
