<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ColorPalette;
use App\Services\Reports\HostingFee;
use App\Services\Reports\SyncPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Portal branding: the system logo and the colour palette.
 *
 * These write to MONITOR's own tables and its own public disk — never to a
 * monitored database, which stays read-only at the connection level regardless
 * of which middleware a request arrived through.
 *
 * Reading is open to any authenticated session (and the logo is readable before
 * one exists, because the login screen renders it). Writing needs
 * `action.settings.manage`, because these values change how the portal looks for
 * everyone, not for the person changing them.
 */
class SettingsController extends Controller
{
    /** Where uploaded logos live on the public disk. */
    private const LOGO_DIR = 'branding';

    /**
     * Everything the Settings screen renders in one call.
     *
     * One request rather than three: the screen is useless with any of them
     * missing, so three round trips would only add three ways for it to load
     * half-drawn.
     */
    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'logo' => $this->logoUrl(),
                'palettes' => ColorPalette::orderBy('id')->get(),
                'sync_price' => [
                    'rate' => SyncPricing::rate(),
                    'excluded_statuses' => SyncPricing::excludedStatuses(),
                ],
                'hosting_fee' => [
                    'rate' => HostingFee::rate(),
                ],
            ],
        ]);
    }

    /**
     * The SYNC platform fee, per billable subscriber per month.
     *
     * A setting rather than config because it is renegotiated more often than the
     * application is deployed, and it lands under Net Income on the Executive
     * Dashboard — a figure that wrong should not need a release to correct.
     *
     * Which subscribers are billable is *not* editable here. VIP and Pullout are
     * excluded by the brief and that exclusion is applied in SQL where the count
     * is taken; making it a form field would let the headcount and the money be
     * computed over different populations.
     */
    public function updateSyncPrice(Request $request)
    {
        $data = $request->validate([
            // Capped rather than unbounded: this multiplies by the whole
            // subscriber base, so a mis-keyed extra digit is a six-figure error in
            // a figure nobody would recognise as wrong at a glance.
            'rate' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $rate = round((float) $data['rate'], 2);
        $previous = SyncPricing::rate();

        DB::transaction(function () use ($rate, $request) {
            AppSetting::put(
                SyncPricing::SETTING_KEY,
                (string) $rate,
                $request->user()?->username
            );
        });

        AuditLog::record(
            $request,
            'settings.sync_price',
            AppSetting::class,
            SyncPricing::SETTING_KEY,
            'SYNC price per customer changed',
            AuditLog::diff(['rate' => $previous], ['rate' => $rate])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'SYNC price per customer updated.',
            'data' => [
                'sync_price' => [
                    'rate' => $rate,
                    'excluded_statuses' => SyncPricing::excludedStatuses(),
                ],
            ],
        ]);
    }

    /**
     * The hosting fee, a flat monthly infrastructure charge.
     *
     * Same reasoning as updateSyncPrice: a setting rather than config because it
     * is renegotiated more often than the application is deployed.
     */
    public function updateHostingFee(Request $request)
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $rate = round((float) $data['rate'], 2);
        $previous = HostingFee::rate();

        DB::transaction(function () use ($rate, $request) {
            AppSetting::put(
                HostingFee::SETTING_KEY,
                (string) $rate,
                $request->user()?->username
            );
        });

        AuditLog::record(
            $request,
            'settings.hosting_fee',
            AppSetting::class,
            HostingFee::SETTING_KEY,
            'Hosting fee changed',
            AuditLog::diff(['rate' => $previous], ['rate' => $rate])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Hosting fee updated.',
            'data' => [
                'hosting_fee' => [
                    'rate' => $rate,
                ],
            ],
        ]);
    }

    /**
     * The logo alone, unauthenticated.
     *
     * The login screen needs it before a session exists. Returns null rather
     * than 404 when none is set, so the frontend falls back to the bundled mark
     * instead of logging an error on every visit.
     */
    public function logo()
    {
        return response()->json([
            'status' => 'success',
            'data' => ['logo' => $this->logoUrl()],
        ]);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            // SVG is deliberately excluded. It is a script-bearing format and
            // this file is served to every browser that opens the portal,
            // including on the unauthenticated login screen.
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $previous = AppSetting::get(AppSetting::LOGO);

        $path = $request->file('logo')->store(self::LOGO_DIR, 'public');

        AppSetting::put(AppSetting::LOGO, $path, $request->user()?->username);

        // Removed only after the new one is safely stored, so a failed upload
        // leaves the old logo in place rather than none at all.
        $this->deleteFile($previous);

        AuditLog::record(
            $request,
            'settings.logo',
            AppSetting::class,
            AppSetting::LOGO,
            'System logo replaced',
            AuditLog::diff(['logo' => $previous], ['logo' => $path])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Logo updated.',
            'data' => ['logo' => $this->logoUrl()],
        ]);
    }

    public function deleteLogo(Request $request)
    {
        $previous = AppSetting::get(AppSetting::LOGO);

        AppSetting::clear(AppSetting::LOGO);
        $this->deleteFile($previous);

        AuditLog::record(
            $request,
            'settings.logo',
            AppSetting::class,
            AppSetting::LOGO,
            'System logo removed'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Logo removed. The bundled mark will be used.',
            'data' => ['logo' => null],
        ]);
    }

    public function storePalette(Request $request)
    {
        $data = $this->validatedPalette($request);

        $palette = ColorPalette::create([
            'palette_name' => $data['palette_name'],
            'primary' => $data['primary'],
            'secondary' => $data['secondary'],
            'accent' => $data['accent'],
            // Created inactive. Adding a palette and having the whole portal
            // change colour underneath you is not what "add" means — activating
            // is a separate, deliberate click.
            'status' => 'inactive',
            'updated_by' => $request->user()?->username,
        ]);

        AuditLog::record(
            $request,
            'settings.palette',
            ColorPalette::class,
            $palette->id,
            "Created colour palette [{$palette->palette_name}]"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Palette created.',
            'data' => ['palette' => $palette],
        ], 201);
    }

    public function updatePalette(Request $request, ColorPalette $palette)
    {
        $data = $this->validatedPalette($request, $palette);

        $before = $palette->only(['palette_name', 'primary', 'secondary', 'accent']);

        $palette->fill(array_merge($data, ['updated_by' => $request->user()?->username]))->save();

        AuditLog::record(
            $request,
            'settings.palette',
            ColorPalette::class,
            $palette->id,
            "Updated colour palette [{$palette->palette_name}]",
            AuditLog::diff($before, $data)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Palette updated.',
            'data' => ['palette' => $palette->fresh()],
        ]);
    }

    /**
     * Makes one palette the active one.
     *
     * Exactly one is active at a time, so activating deactivates the rest in the
     * same breath. Two active rows would leave which one wins up to row order,
     * which is not a decision anyone made.
     */
    public function activatePalette(Request $request, ColorPalette $palette)
    {
        ColorPalette::where('status', 'active')->update(['status' => 'inactive']);

        $palette->status = 'active';
        $palette->updated_by = $request->user()?->username;
        $palette->save();

        AuditLog::record(
            $request,
            'settings.palette',
            ColorPalette::class,
            $palette->id,
            "Activated colour palette [{$palette->palette_name}]"
        );

        return response()->json([
            'status' => 'success',
            'message' => "\"{$palette->palette_name}\" is now the active palette.",
            'data' => ['palettes' => ColorPalette::orderBy('id')->get()],
        ]);
    }

    public function destroyPalette(Request $request, ColorPalette $palette)
    {
        // Deleting the active palette would leave the portal with no brand
        // colours and no obvious way to notice why. Activate another first.
        if ($palette->status === 'active') {
            throw ValidationException::withMessages([
                'palette' => 'This palette is active. Activate another one before deleting it.',
            ]);
        }

        $name = $palette->palette_name;
        $id = $palette->id;

        $palette->delete();

        AuditLog::record(
            $request,
            'settings.palette',
            ColorPalette::class,
            $id,
            "Deleted colour palette [{$name}]"
        );

        return response()->json(['status' => 'success', 'message' => 'Palette deleted.']);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function validatedPalette(Request $request, ?ColorPalette $palette = null): array
    {
        return $request->validate([
            'palette_name' => [
                'required', 'string', 'max:100',
                Rule::unique('settings_color_palette', 'palette_name')->ignore($palette?->id),
            ],
            // Six-digit hex only. The column is 20 characters and would happily
            // take 'rebeccapurple', which then breaks the alpha-suffix maths the
            // sidebar does when it builds its active-item tint.
            'primary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }

    /** Absolute URL of the stored logo, or null when none is set. */
    private function logoUrl(): ?string
    {
        $path = AppSetting::get(AppSetting::LOGO);

        if (!$path) {
            return null;
        }

        // A row pointing at a file that is gone — a restored database, a wiped
        // storage volume — reports "no logo" rather than a broken image.
        if (!Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
