<?php

use App\Http\Controllers\Api\Platform\PlatformController;
use Illuminate\Support\Facades\Route;

// Dashboard, Control Tower, activity feed, notifications, reports catalogue and global search are open to every
// signed-in user (figures are cost-gated inside). The audit trail and the settings need a permission; a CSV export
// (`reports/{name}?format=csv`) additionally needs `report.export`, enforced by the controller.
Route::get('dashboard', [PlatformController::class, 'dashboard']);
Route::get('tower', [PlatformController::class, 'tower']);

Route::get('activity', [PlatformController::class, 'activity']);
Route::get('audit', [PlatformController::class, 'audit'])->middleware('perm:audit.view');

Route::get('notifications', [PlatformController::class, 'notifications']);
Route::post('notifications/read-all', [PlatformController::class, 'readAll']);
Route::post('notifications/{id}/read', [PlatformController::class, 'read']);

Route::get('reports', [PlatformController::class, 'reports']);
Route::get('reports/{name}', [PlatformController::class, 'report']);

Route::get('settings', [PlatformController::class, 'settings'])->middleware('perm:settings.manage');
Route::put('settings/{key}', [PlatformController::class, 'setSetting'])->middleware('perm:settings.manage');

Route::get('search', [PlatformController::class, 'search']);
