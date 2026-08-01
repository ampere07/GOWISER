<?php

use App\Http\Controllers\Api\DatabaseConnectionController;
use App\Http\Controllers\Api\FinancialsController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SettingsColorPaletteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Three tiers:
|   - public: login and the health check
|   - executive: the dashboards, behind an authenticated session and the
|     read-only guard in EnsureExecutiveAccess
|   - db-admin: the Databases configuration page, the only tier that writes
|
| MONITOR reports on other systems and never changes them. That guarantee is not
| a property of the routing — it is enforced at the connection level in
| SourceRegistry::connection(), which rejects any statement that is not a read no
| matter which tier the request came through.
|
| What the db-admin tier writes is MONITOR's own `site_connections` table: the
| list of databases to report on, and the credentials to read them with.
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

/*
 * Connector-backed dashboards. Sites and their capabilities come from the
 * site_connections table, so adding a site needs no deploy.
 */
Route::middleware(['auth', 'executive'])->group(function () {
    Route::get('/sites', [SiteController::class, 'index']);
    Route::get('/financials', [FinancialsController::class, 'show']);
    Route::get('/financials/group', [FinancialsController::class, 'group']);
});

/*
 * Legacy config-driven endpoints, still serving the current frontend pages
 * until each is migrated onto the connector above.
 */
Route::middleware(['auth', 'executive'])->prefix('monitor')->group(function () {
    Route::get('/sources', [MonitorController::class, 'sources']);
    Route::get('/overview', [MonitorController::class, 'overview']);
    Route::get('/operations', [MonitorController::class, 'operations']);
    Route::get('/revenue', [MonitorController::class, 'revenue']);
    Route::get('/financials', [MonitorController::class, 'financials']);
    Route::get('/branches', [MonitorController::class, 'branches']);
    Route::get('/consolidated', [MonitorController::class, 'consolidated']);
});

/*
 * The five operational sections, ported from the operating systems' own modules
 * so the figures agree with what branch staff already see. Served by
 * App\Services\Reports drivers rather than the executive metrics drivers — see
 * config/monitor.php for why the two are separate, and for which source can
 * answer which section.
 *
 * These endpoints resolve their own source: a section the requested system cannot
 * serve falls through to one that can, and the response names whichever actually
 * answered. That is what lets Tech read GOWISER's technicians while the rest of
 * the app is pointed at NETMANAGER.
 */
Route::middleware(['auth', 'executive'])->prefix('reporting')->group(function () {
    Route::get('/capabilities', [ReportingController::class, 'capabilities']);
    Route::get('/branches', [ReportingController::class, 'branches']);

    Route::get('/subscriber-analytics', [ReportingController::class, 'subscriberAnalytics']);
    Route::get('/financial', [ReportingController::class, 'financial']);
    Route::get('/operations', [ReportingController::class, 'operations']);
    Route::get('/tech', [ReportingController::class, 'tech']);
    Route::get('/employee', [ReportingController::class, 'employee']);

    // Line-level data for the three print layouts. Never cached: a printed
    // report is a record someone signs.
    Route::get('/printable', [ReportingController::class, 'printable']);
});

/*
 * Databases configuration.
 *
 * The only writing endpoints in MONITOR, and the only ones not behind the
 * read-only 'executive' guard. They write to MONITOR's own `site_connections`
 * table; the monitored databases stay read-only at the connection level, enforced
 * in SourceRegistry::connection() no matter how a request arrived.
 *
 * Adding a row makes the database available to every section on the next request,
 * with no deploy. Requires the 'databases' permission specifically, because these
 * rows hold credentials for every monitored database.
 */
Route::middleware(['auth', 'db-admin'])->prefix('databases')->group(function () {
    Route::get('/', [DatabaseConnectionController::class, 'index']);
    Route::post('/', [DatabaseConnectionController::class, 'store']);
    Route::post('/reorder', [DatabaseConnectionController::class, 'reorder']);

    Route::put('/{connection}', [DatabaseConnectionController::class, 'update']);
    Route::delete('/{connection}', [DatabaseConnectionController::class, 'destroy']);

    // Connectivity check and schema inspection, so an operator can confirm a
    // connection reaches the database they meant.
    Route::post('/{connection}/test', [DatabaseConnectionController::class, 'test']);
    Route::get('/{connection}/introspect', [DatabaseConnectionController::class, 'introspect']);
});
