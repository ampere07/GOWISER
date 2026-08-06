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
use Illuminate\Support\Facades\Log;
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
     * Setting key holding the uploaded logo's content type.
     *
     * Recorded at upload rather than guessed at serve time. `mimeType()` on a
     * file whose extension the web server does not recognise returns
     * application/octet-stream, which browsers download instead of rendering —
     * and the first anyone knows about it is a portal with no logo.
     */
    private const LOGO_MIME = 'system_logo_mime';

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

    /**
     * The stored logo, streamed.
     *
     * ── Why the file is served by PHP rather than by the web server ───
     *
     * `Storage::disk('public')->url()` — what this used to hand out — is only a
     * working URL when two things happen to be true: `php artisan storage:link`
     * has been run on the box, and `APP_URL` matches the host the browser is
     * actually talking to. Neither is true often enough. An upload would succeed,
     * the row would be written, `Storage::exists()` would say yes, and the
     * portal would still show a broken image — with nothing anywhere to say why.
     * On this deployment the SPA and the API are not even on the same origin, so
     * APP_URL is a third thing to get wrong.
     *
     * Streaming it removes all three failure modes. There is no symlink to
     * create, no APP_URL to match, and the Content-Type is the one recorded at
     * upload rather than whatever the web server guesses from the extension.
     *
     * ── Caching ───────────────────────────────────────────────────────
     *
     * The URL carries a fingerprint of the stored path (see logoUrl), so it
     * changes whenever the logo does and never otherwise. That makes the response
     * safely immutable for a year — the browser stops asking, and a new upload is
     * picked up instantly because it is a different URL. An ETag is sent as well
     * so a client that ignores the fingerprint still gets a 304 rather than the
     * bytes.
     *
     * Unauthenticated, like logo() beside it: the login screen renders this
     * before a session exists.
     */
    public function logoFile()
    {
        $path = AppSetting::get(AppSetting::LOGO);
        $disk = Storage::disk('public');

        if (!$path || !$disk->exists($path)) {
            // 404 rather than a placeholder: the JSON endpoint already told the
            // frontend there is no logo and it is rendering the bundled mark. A
            // request reaching here for a missing file is a stale cached URL.
            abort(404);
        }

        $mime = AppSetting::get(self::LOGO_MIME) ?: $disk->mimeType($path) ?: 'application/octet-stream';

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"' . md5($path) . '"',
            // The file is user-supplied and served from our own origin; this
            // stops a browser from ever treating it as anything but an image.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Replaces the system logo.
     *
     * ── Validation ────────────────────────────────────────────────────
     *
     * Three checks, not one, because they catch different things:
     *
     *   image        the file is decodable as an image at all
     *   mimes        the *extension* is one we serve
     *   mimetypes    the sniffed content type agrees with it
     *
     * `mimes` alone passes a PHP script renamed to .png. `mimetypes` alone passes
     * a real PNG saved as `logo.php`. Requiring both means the extension and the
     * bytes have to tell the same story — which matters here more than usual,
     * because this file is served to every visitor including on the
     * unauthenticated login screen.
     *
     * SVG is deliberately excluded from all three. It is a script-bearing format.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:2048',
            ],
        ], [
            'logo.mimetypes' => 'That file is not a PNG, JPEG or WebP image, whatever its name says.',
            'logo.max' => 'That image is over 2 MB. Please use a smaller file.',
        ]);

        $file = $request->file('logo');
        $previous = AppSetting::get(AppSetting::LOGO);

        // The directory is created and checked before the write rather than
        // after the failure. A storage volume mounted read-only, or a directory
        // owned by root because someone ran artisan as root once, both surface
        // here as a clear message instead of an unhandled exception behind
        // "Upload failed".
        try {
            $disk = Storage::disk('public');

            if (!$disk->exists(self::LOGO_DIR)) {
                $disk->makeDirectory(self::LOGO_DIR);
            }

            $path = $file->store(self::LOGO_DIR, 'public');

            if ($path === false || $path === '') {
                throw new \RuntimeException('The file could not be written.');
            }
        } catch (\Throwable $e) {
            Log::error('Logo upload failed: ' . $e->getMessage(), [
                'directory' => self::LOGO_DIR,
                'disk_root' => config('filesystems.disks.public.root'),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'The logo could not be saved. The storage directory is not writable — check permissions on storage/app/public.',
            ], 500);
        }

        // Both keys move together or neither does: a path with a stale mime type
        // would be served as the wrong content type, which some browsers refuse
        // to render at all.
        DB::transaction(function () use ($path, $file, $request) {
            AppSetting::put(AppSetting::LOGO, $path, $request->user()?->username);
            AppSetting::put(
                self::LOGO_MIME,
                (string) ($file->getClientMimeType() ?: 'image/png'),
                $request->user()?->username
            );
        });

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

        DB::transaction(function () {
            AppSetting::clear(AppSetting::LOGO);
            AppSetting::clear(self::LOGO_MIME);
        });

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

    /**
     * URL of the stored logo, or null when none is set.
     *
     * Points at this controller's own streaming route rather than at the public
     * disk — see logoFile() for the three deployment failures that removes.
     *
     * The `v` parameter is a fingerprint of the stored path, which changes on
     * every upload because the stored filename is a fresh hash. That is what
     * makes the year-long cache on the file itself safe: a new logo is a new URL,
     * so it appears immediately, and an unchanged one is never re-fetched.
     * Cache-busting on a timestamp would defeat the cache entirely; on nothing at
     * all, an operator would upload a new mark and keep seeing the old one until
     * they cleared their browser.
     */
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

        return url('/api/settings/logo/file') . '?v=' . substr(md5($path), 0, 12);
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
