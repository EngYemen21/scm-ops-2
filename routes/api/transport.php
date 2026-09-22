<?php

use App\Http\Controllers\Api\Transport\FleetController;
use App\Http\Controllers\Api\Transport\GpsController;
use App\Http\Controllers\Api\Transport\MapController;
use App\Http\Controllers\Api\Transport\TripsController;
use Illuminate\Support\Facades\Route;

// /api/transport — TMS (trips, control tower) + fleet (vehicles, drivers, maintenance, fuel, alerts, ops requests, routes).
// Reading is open to every signed-in user; every change declares its permission.
Route::prefix('transport')->group(function () {
    // control tower / KPIs
    Route::get('tower', [TripsController::class, 'tower']);
    Route::get('fleet/kpis', [FleetController::class, 'kpis']);

    // map: provider config for the browser, the tower / fleet overview, one trip's route, provider-optimised stop order
    Route::get('map/config', [MapController::class, 'config']);
    Route::get('map', [MapController::class, 'overview']);
    Route::get('trips/{number}/map', [MapController::class, 'trip']);
    Route::post('trips/{number}/optimize', [MapController::class, 'optimize'])->middleware('perm:trip.manage');

    // live tracking (telematics provider): status, forced sync, the provider's units for pairing, one vehicle's trail
    Route::get('gps/status', [GpsController::class, 'status']);
    Route::post('gps/sync', [GpsController::class, 'sync'])->middleware('perm:vehicle.manage');
    Route::get('gps/units', [GpsController::class, 'units'])->middleware('perm:vehicle.manage');
    Route::get('vehicles/{code}/trail', [GpsController::class, 'trail']);

    // vehicles
    Route::get('vehicles', [FleetController::class, 'listVehicles']);
    Route::get('vehicles/{code}', [FleetController::class, 'getVehicle']);
    Route::post('vehicles', [FleetController::class, 'createVehicle'])->middleware('perm:vehicle.manage');
    Route::patch('vehicles/{code}', [FleetController::class, 'updateVehicle'])->middleware('perm:vehicle.manage');
    Route::post('vehicles/{code}/state', [FleetController::class, 'setVehicleState'])->middleware('perm:vehicle.state');
    Route::post('vehicles/{code}/breakdown', [FleetController::class, 'breakdown'])->middleware('perm:vehicle.state');

    // drivers
    Route::get('drivers', [FleetController::class, 'listDrivers']);
    Route::get('drivers/{code}', [FleetController::class, 'getDriver']);
    Route::post('drivers', [FleetController::class, 'createDriver'])->middleware('perm:driver.manage');
    Route::patch('drivers/{code}', [FleetController::class, 'updateDriver'])->middleware('perm:driver.manage');
    Route::post('drivers/{code}/state', [FleetController::class, 'setDriverState'])->middleware('perm:driver.manage');
    Route::post('drivers/{code}/incidents', [FleetController::class, 'addIncident'])->middleware('perm:driver.manage');

    // maintenance / fuel
    Route::get('maintenance', [FleetController::class, 'listMaintenance']);
    Route::post('maintenance', [FleetController::class, 'createMaintenance'])->middleware('perm:maintenance.manage');
    Route::post('maintenance/{number}/close', [FleetController::class, 'closeMaintenance'])->middleware('perm:maintenance.manage');
    Route::get('fuel', [FleetController::class, 'listFuel']);
    Route::post('fuel', [FleetController::class, 'createFuel'])->middleware('perm:fuel.manage');

    // alerts
    Route::get('alerts', [FleetController::class, 'listAlerts']);
    Route::post('alerts/generate', [FleetController::class, 'generateAlerts'])->middleware('perm:alert.manage');
    Route::post('alerts/{code}/resolve', [FleetController::class, 'resolveAlert'])->middleware('perm:alert.manage');
    Route::post('alerts/{code}/snooze', [FleetController::class, 'snoozeAlert'])->middleware('perm:alert.manage');
    Route::post('alerts/{code}/assign', [FleetController::class, 'assignAlert'])->middleware('perm:alert.manage');

    // driver ops requests
    Route::get('ops-requests', [FleetController::class, 'listOpsRequests']);
    Route::get('ops-requests/{number}', [FleetController::class, 'getOpsRequest']);
    Route::post('ops-requests', [FleetController::class, 'createOpsRequest'])->middleware('perm:opreq.create');
    Route::post('ops-requests/{number}/status', [FleetController::class, 'setOpsRequestStatus'])->middleware('perm:opreq.manage');

    // routes
    Route::get('routes', [FleetController::class, 'listRoutes']);
    Route::post('routes', [FleetController::class, 'createRoute'])->middleware('perm:trip.manage');
    Route::patch('routes/{code}', [FleetController::class, 'updateRoute'])->middleware('perm:trip.manage');

    // trips
    Route::get('trips', [TripsController::class, 'index']);
    Route::get('trips/{number}', [TripsController::class, 'show']);
    Route::post('trips', [TripsController::class, 'store'])->middleware('perm:trip.manage');
    Route::get('trips/{number}/recommend', [TripsController::class, 'recommend']);
    Route::post('trips/{number}/assign', [TripsController::class, 'assign'])->middleware('perm:trip.assign');
    Route::post('trips/{number}/unassign', [TripsController::class, 'unassign'])->middleware('perm:trip.assign');
    Route::post('trips/{number}/stops/reorder', [TripsController::class, 'reorder'])->middleware('perm:trip.manage');
    Route::post('trips/{number}/cancel', [TripsController::class, 'cancel'])->middleware('perm:trip.manage');
    Route::post('trips/{number}/close', [TripsController::class, 'close'])->middleware('perm:trip.close');
    Route::get('trips/{number}/events', [TripsController::class, 'events']);
    Route::post('trips/{number}/events', [TripsController::class, 'addEvent'])->middleware('perm:trip.manage');
});
