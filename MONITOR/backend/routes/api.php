<?php

use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SettingsColorPaletteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Two tiers:
|   - public: login and the health check
|   - executive: everything else, behind an authenticated session and the
|     read-only guard in EnsureExecutiveAccess
|
| There are deliberately no create/update/delete routes. MONITOR reports on
| other systems; it does not change them.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Monitor API is running',
        'data' => [
            'server' => config('app.name'),
            'timestamp' => now()->toIso8601String(),
        ],
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

// Palette is needed by the login screen, before a session exists.
Route::get('/settings-color-palette', [SettingsColorPaletteController::class, 'index']);
Route::get('/settings-color-palette/active', [SettingsColorPaletteController::class, 'active']);

Route::middleware('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth', 'executive'])->prefix('monitor')->group(function () {
    Route::get('/sources', [MonitorController::class, 'sources']);
    Route::get('/overview', [MonitorController::class, 'overview']);
    Route::get('/operations', [MonitorController::class, 'operations']);
    Route::get('/revenue', [MonitorController::class, 'revenue']);
    Route::get('/financials', [MonitorController::class, 'financials']);
    Route::get('/branches', [MonitorController::class, 'branches']);
    Route::get('/consolidated', [MonitorController::class, 'consolidated']);
});
