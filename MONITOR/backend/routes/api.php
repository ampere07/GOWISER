<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DatabaseConnectionController;
use App\Http\Controllers\Api\ExecutiveOverviewController;
use App\Http\Controllers\Api\FinancialsController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SettingsColorPaletteController;
use App\Support\Permissions;
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
    //
    // Gated separately from reading the Financial module. Printing puts the
    // ledger on paper and out of the building, which is a stronger act than
    // looking at it, and several roles are meant to do the second but not the
    // first.
    Route::get('/printable', [ReportingController::class, 'printable'])
        ->middleware('permission:' . Permissions::ACTION_FINANCIAL_EXPORT);
});

/*
 * Module 5 — the consolidated C-suite summary.
 *
 * Composed from the sections above rather than from its own SQL, so it can never
 * disagree with the modules it summarises. The controller enforces a role check
 * on top of the module permission: this view puts every company's figures on one
 * page, which is not access a custom role should acquire sideways.
 */
Route::middleware(['auth', 'executive'])->group(function () {
    Route::get('/executive/overview', [ExecutiveOverviewController::class, 'show']);
});

/*
 * The write endpoints outside the Databases page.
 *
 * All of them write to MONITOR's own tables. The monitored databases stay
 * read-only at the connection level in SourceRegistry::connection(), which no
 * request can bypass — so none of these routes sit behind the 'executive'
 * middleware, which would reject them for not being GETs.
 *
 * Each carries the specific permission it needs rather than a blanket admin
 * role: approving a payable, editing a user and reading the audit trail are
 * three different jobs and are granted independently.
 */
Route::middleware('auth')->group(function () {
    Route::post('/payables/toggle', [PayableController::class, 'toggle'])
        ->middleware('permission:' . Permissions::ACTION_PAYABLE_TOGGLE);

    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:' . Permissions::ACTION_AUDIT_VIEW);

    Route::prefix('users')
        ->middleware('permission:' . Permissions::ACTION_USERS_MANAGE)
        ->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            Route::post('/', [UserManagementController::class, 'store']);
            Route::put('/{user}', [UserManagementController::class, 'update']);
            Route::delete('/{user}', [UserManagementController::class, 'destroy']);

            // Reshaping a role changes what everyone holding it can see, so it
            // needs its own grant beyond being able to edit a single user.
            Route::put('/roles/{role}', [UserManagementController::class, 'updateRole'])
                ->middleware('permission:' . Permissions::ACTION_ROLES_MANAGE);
        });
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
