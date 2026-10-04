<?php

use App\Integration\Http\V1Controller;
use Illuminate\Support\Facades\Route;

/*
| System-to-system gateway /api/v1 (docs/integration/ARCHITECTURE.md §5). Only other SYSTEMS call these, signed with
| HMAC (`int.system:<scope>`); a person's session token is never accepted here, and these routes are not reachable
| with one. Loaded from routes/api.php outside the user-authenticated group.
*/

Route::get('health', [V1Controller::class, 'health'])->middleware('int.system');
Route::post('events', [V1Controller::class, 'events'])->middleware('int.system:events:write');
Route::post('ops/heartbeat', [V1Controller::class, 'heartbeat'])->middleware('int.system:ops:run');
Route::get('inventory/availability', [V1Controller::class, 'availability'])->middleware('int.system:inventory:read');
Route::get('orders/{externalRef}', [V1Controller::class, 'order'])->middleware('int.system:orders:read');
