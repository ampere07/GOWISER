<?php

use App\Http\Controllers\Api\DatabaseConnectionController;
use App\Http\Controllers\Api\FinancialsController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\UserController;
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
    // Not permission-gated: the site list is what every other page's source picker is built from,
    // and SiteController already narrows it to the role's site_scope. A user who can see no
    // sections gets an empty list from that scoping rather than a 403 they cannot act on.
    Route::get('/sites', [SiteController::class, 'index']);

    Route::get('/financials', [FinancialsController::class, 'show'])
        ->middleware('permission:financials.view');
    Route::get('/financials/group', [FinancialsController::class, 'group'])
        ->middleware('permission:financials.view');
});

/*
 * Legacy config-driven endpoints, still serving the current frontend pages
 * until each is migrated onto the connector above.
 */
Route::middleware(['auth', 'executive'])->prefix('monitor')->group(function () {
    // As with /sites: the source list underpins every page's picker and carries no metrics.
    Route::get('/sources', [MonitorController::class, 'sources']);

    Route::get('/overview', [MonitorController::class, 'overview'])
        ->middleware('permission:overview.view');
    Route::get('/operations', [MonitorController::class, 'operations'])
        ->middleware('permission:operations.view');
    Route::get('/revenue', [MonitorController::class, 'revenue'])
        ->middleware('permission:revenue.view');
    Route::get('/financials', [MonitorController::class, 'financials'])
        ->middleware('permission:financials.view');
    Route::get('/consolidated', [MonitorController::class, 'consolidated'])
        ->middleware('permission:consolidated.view');

    // Branch list for the Overview filter, so it follows that page's grant.
    Route::get('/branches', [MonitorController::class, 'branches'])
        ->middleware('permission:overview.view');
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
    // Capability and branch lookups describe what CAN be asked, not what the numbers are, and
    // the frontend needs both before it can render any permitted section. Left ungated for the
    // same reason as /sites.
    Route::get('/capabilities', [ReportingController::class, 'capabilities']);
    Route::get('/branches', [ReportingController::class, 'branches']);

    Route::get('/subscriber-analytics', [ReportingController::class, 'subscriberAnalytics'])
        ->middleware('permission:subscriber-analytics.view');
    Route::get('/financial', [ReportingController::class, 'financial'])
        ->middleware('permission:financial.view');
    Route::get('/operations', [ReportingController::class, 'operations'])
        ->middleware('permission:field-operations.view');
    Route::get('/tech', [ReportingController::class, 'tech'])
        ->middleware('permission:tech.view');
    Route::get('/employee', [ReportingController::class, 'employee'])
        ->middleware('permission:employee.view');

    // Line-level data for the three print layouts. Never cached: a printed
    // report is a record someone signs.
    //
    // Gated on `.export`, not `.view`. Taking a report out of the portal — printing it, handing
    // it to someone outside — is the act worth controlling separately from reading it on screen,
    // and this endpoint returns row-level detail the dashboards only ever show aggregated.
    Route::get('/printable', [ReportingController::class, 'printable'])
        ->middleware('permission:financial.export');
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
/*
 * User administration.
 *
 * Writes, like Databases, and for the same reason it is allowed to: the rows belong to MONITOR's
 * own database, not to a monitored system. It sits outside the 'executive' guard because that
 * guard rejects every non-GET method by design.
 *
 * Each route names its own verb, so a role can be given sight of the user list without the
 * ability to create accounts.
 */
Route::middleware(['auth'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index'])
        ->middleware('permission:users.view');

    // Roles are read as part of building the create form, so they follow users.view.
    Route::get('/roles', [UserController::class, 'roles'])
        ->middleware('permission:users.view');

    // Superadmin-only, above and beyond the permission. Creating an account is the one action
    // that manufactures access rather than exercising it, so it is gated on who the caller IS.
    // Both guards are listed: the permission stays meaningful, and removing either still leaves
    // the endpoint protected.
    Route::post('/', [UserController::class, 'store'])
        ->middleware(['permission:users.create', 'superadmin']);

    // Suspending an account is an edit of its `active` flag, so it needs no route of its own —
    // one place decides whether a change to a user is allowed.
    Route::put('/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.edit');
    Route::delete('/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete');
});

Route::middleware(['auth', 'db-admin'])->prefix('databases')->group(function () {
    // EnsureDatabaseAdmin already requires the `databases` section; these add the verb, so a role
    // can be given read-only sight of the connection list without the ability to change it.
    Route::get('/', [DatabaseConnectionController::class, 'index'])
        ->middleware('permission:databases.view');
    Route::post('/', [DatabaseConnectionController::class, 'store'])
        ->middleware('permission:databases.create');
    Route::post('/reorder', [DatabaseConnectionController::class, 'reorder'])
        ->middleware('permission:databases.edit');

    Route::put('/{connection}', [DatabaseConnectionController::class, 'update'])
        ->middleware('permission:databases.edit');
    Route::delete('/{connection}', [DatabaseConnectionController::class, 'destroy'])
        ->middleware('permission:databases.delete');

    // Connectivity check and schema inspection, so an operator can confirm a
    // connection reaches the database they meant. Reads about a connection rather than changes
    // to one, so they follow `view` — POST here only because a test carries a body.
    Route::post('/{connection}/test', [DatabaseConnectionController::class, 'test'])
        ->middleware('permission:databases.view');
    Route::get('/{connection}/introspect', [DatabaseConnectionController::class, 'introspect'])
        ->middleware('permission:databases.view');
});
