<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DatabaseConnectionController;
use App\Http\Controllers\Api\ExecutiveOverviewController;
use App\Http\Controllers\Api\FinancialsController;
use App\Http\Controllers\Api\MikrotikController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\RadiusConfigController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\SettingsController;
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

// Palette and logo are needed by the login screen, before a session exists.
Route::get('/settings-color-palette', [SettingsColorPaletteController::class, 'index']);
Route::get('/settings-color-palette/active', [SettingsColorPaletteController::class, 'active']);
Route::get('/settings/logo', [SettingsController::class, 'logo']);

// The logo bytes, streamed by PHP rather than served off the public disk. The
// login screen renders this before a session exists, so it is unauthenticated
// like the JSON endpoint above it — see SettingsController::logoFile for why it
// does not go through Storage::url().
Route::get('/settings/logo/file', [SettingsController::class, 'logoFile']);

Route::middleware('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // How often this user's dashboards re-read themselves. Deliberately on the
    // plain auth group: it is the one thing on the Settings page that belongs to
    // the person rather than the portal, and gating it behind an administrative
    // grant would mean nobody but an administrator could set their own.
    Route::put('/me/preferences', [AuthController::class, 'updatePreferences']);
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
 * The source list.
 *
 * All that survives of the legacy executive rollup endpoints — their pages were
 * removed in favour of the reporting modules and the group overview, which
 * compose the same figures from one code path instead of a second, divergent one.
 *
 * This one stays because it is not a rollup: it is how the app learns which
 * databases exist and which the signed-in role may look at, which every
 * reporting section needs before it can ask anything.
 */
Route::middleware(['auth', 'executive'])->prefix('monitor')->group(function () {
    Route::get('/sources', [MonitorController::class, 'sources']);
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
 * Module 5 — the Executive Dashboard.
 *
 * Composed from the sections above rather than from its own SQL, so it can never
 * disagree with the modules it summarises. The controller enforces a role check
 * on top of the module permission: this view puts every company's figures on one
 * page, which is not access a custom role should acquire sideways.
 *
 * `work-streams` is the same view's Applications / Job Orders / Service Orders
 * widgets, which each carry an independent date range. It is a separate endpoint
 * so moving one of those ranges does not re-run the financial fan-out behind the
 * rest of the page, which is by far the most expensive part of it and does not
 * change when someone puts Service Orders on the month.
 */
Route::middleware(['auth', 'executive'])->group(function () {
    Route::get('/executive/overview', [ExecutiveOverviewController::class, 'show']);
    Route::get('/executive/work-streams', [ExecutiveOverviewController::class, 'workStreams']);

    // The drill-down behind the Subscriber Analytics counters — a named,
    // paginated list of everyone in one billing status. Same two gates as the
    // dashboard: a list of every customer in a status is strictly more sensitive
    // than the count that opened it.
    Route::get('/executive/subscribers', [ExecutiveOverviewController::class, 'subscribers']);

    // The records behind one metric tile — application, installed, repair,
    // reschedule, pending — over the window the card was counted in. Built on
    // the same query the counter used, so the modal can never show a different
    // population from the tile that opened it.
    Route::get('/executive/records', [ExecutiveOverviewController::class, 'metricRecords']);
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

    /*
     * Branding. Reading is open to any session — every page renders the logo and
     * the palette — but changing them alters the portal for everyone, so the
     * writes need their own grant.
     */
    Route::get('/settings', [SettingsController::class, 'index']);

    Route::middleware('permission:' . Permissions::ACTION_SETTINGS_MANAGE)->group(function () {
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo']);

        // The SYNC platform fee, which lands under Expenses on the Executive
        // Dashboard and therefore inside Net Income. Behind the same grant as
        // the branding writes because it changes a figure for everyone.
        Route::put('/settings/sync-price', [SettingsController::class, 'updateSyncPrice']);

        // The hosting fee, a flat monthly infrastructure charge. Same grant.
        Route::put('/settings/hosting-fee', [SettingsController::class, 'updateHostingFee']);

        Route::post('/settings/palettes', [SettingsController::class, 'storePalette']);
        Route::put('/settings/palettes/{palette}', [SettingsController::class, 'updatePalette']);
        Route::post('/settings/palettes/{palette}/activate', [SettingsController::class, 'activatePalette']);
        Route::delete('/settings/palettes/{palette}', [SettingsController::class, 'destroyPalette']);
    });

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
 * MikroTik RADIUS — User Manager control.
 *
 * The only routes in MONITOR that change something outside MONITOR. Everything
 * else here reads a monitored database or writes to a local settings table; these
 * reach a live router and can disconnect subscribers.
 *
 * Four gates, and the split is the safety property:
 *
 *   executive-role           the signed-in user's ROLE is an executive one
 *   MODULE_MIKROTIK          read the User Manager
 *   ACTION_MIKROTIK_WRITE    change a group's rate limit, pool, or a user's group
 *   ACTION_MIKROTIK_KICK     terminate live sessions, now or on a schedule
 *
 * The role gate is first and is the one that cannot be granted from inside the
 * app. Module and action permissions are editable on the Roles screen, so a
 * permission alone can be handed to anyone by anyone who can edit roles — and
 * the ability to re-shape every subscriber's bandwidth is not access that should
 * be acquirable that way. Permissions::EXECUTIVE_ROLES only changes in a deploy.
 *
 * Granting somebody the tab still does not grant them the ability to cut a
 * region off, which a single permission covering the whole feature would.
 *
 * Deliberately NOT behind the 'executive' middleware: that one enforces the
 * read-only guarantee the reporting pages rely on and would reject every write
 * here with a 405. 'executive-role' is its role check without that rule — see
 * EnsureExecutiveRole for why the two are separate rather than parameterised.
 */
Route::middleware(['auth', 'executive-role', 'permission:' . Permissions::MODULE_MIKROTIK])
    ->prefix('mikrotik')
    ->group(function () {
        Route::get('/', [MikrotikController::class, 'index']);
        Route::get('/users', [MikrotikController::class, 'users']);

        // Reads a rate limit without setting it, so the form can show "250mb"
        // resolving to "250M/250M" as it is typed. Read-only, hence no write
        // grant — see MikrotikController::previewRateLimit.
        Route::post('/rate-limit/preview', [MikrotikController::class, 'previewRateLimit']);

        Route::middleware('permission:' . Permissions::ACTION_MIKROTIK_WRITE)->group(function () {
            Route::put('/groups/{group}', [MikrotikController::class, 'updateGroup']);

            // Moving a subscriber between groups changes what they are paying
            // for, so it sits behind the same grant as re-shaping a group.
            Route::put('/users/{username}/group', [MikrotikController::class, 'moveUser']);
        });

        Route::middleware('permission:' . Permissions::ACTION_MIKROTIK_KICK)->group(function () {
            Route::post('/kick/now', [MikrotikController::class, 'kickNow']);
            // A named wall-clock time in Asia/Manila (GMT+8).
            Route::post('/kick/schedule', [MikrotikController::class, 'scheduleKick']);
            // The next maintenance window, whenever that is.
            Route::post('/kick/later', [MikrotikController::class, 'kickLater']);
            Route::delete('/kick/{kick}', [MikrotikController::class, 'cancelKick']);
        });
    });

/*
 * RADIUS API Settings — where MikroTik RADIUS points.
 *
 * Ported from GOWISER's radius_config so the endpoints are managed in one place
 * rather than in two environments. These rows hold credentials for hardware that
 * can take the network offline, so they carry their own grant rather than
 * sharing ACTION_SETTINGS_MANAGE with the logo and the colour palette — and the
 * executive role gate applies here too, since a RADIUS endpoint somebody else
 * controls is a more complete compromise of this feature than any permission on
 * the module itself.
 *
 * Outside the 'executive' group for the same reason as the block above: these
 * are writes, and that middleware rejects anything that is not a GET.
 */
Route::middleware(['auth', 'executive-role', 'permission:' . Permissions::ACTION_RADIUS_MANAGE])
    ->prefix('radius-config')
    ->group(function () {
        Route::get('/', [RadiusConfigController::class, 'index']);
        Route::post('/', [RadiusConfigController::class, 'store']);
        Route::put('/{radius}', [RadiusConfigController::class, 'update']);
        Route::delete('/{radius}', [RadiusConfigController::class, 'destroy']);

        // Authenticates against the stored credentials rather than anything in
        // the request, so this cannot become a way to probe arbitrary hosts.
        Route::post('/{radius}/test', [RadiusConfigController::class, 'test']);
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

    // What the reporting drivers expect from this database against what is
    // actually there — table by table, column by column, including which real
    // timestamp column each dated figure resolved to. The monitored schemas
    // drift and MONITOR cannot migrate them, so the drift needs somewhere to be
    // visible before a figure reads zero for a reason nobody can see.
    Route::get('/{connection}/mapping', [DatabaseConnectionController::class, 'mapping']);
});
