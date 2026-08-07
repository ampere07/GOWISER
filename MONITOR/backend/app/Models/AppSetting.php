<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Portal-wide branding, as a key/value pair.
 *
 * Reads are cached because the logo is fetched on every page load including the
 * login screen, and that is one query per visitor for a value that changes
 * perhaps twice a year. Writes bust the entry rather than waiting it out — an
 * administrator who has just uploaded a logo must see it, not a copy from the
 * last hour.
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    /** Relative path, inside the public disk, of the uploaded system logo. */
    public const LOGO = 'system_logo';

    /**
     * Auto-refresh intervals, in seconds, applied to every account.
     *
     * Portal-wide rather than per-user. These numbers decide how often the
     * portal fans out across every monitored database and — on the MikroTik
     * screen — how often it reaches routers that are simultaneously serving live
     * authentication, so the load they imply belongs to the deployment rather
     * than to whoever happens to have a tab open. Held per user, the total cost
     * was the sum of a dozen private choices nobody could see in one place.
     */
    public const OVERVIEW_REFRESH = 'overview_refresh';
    public const MIKROTIK_REFRESH = 'mikrotik_refresh';

    private const CACHE_TTL = 3600;

    public static function get(string $key, $default = null)
    {
        $value = cache()->remember(
            'app_setting:' . $key,
            self::CACHE_TTL,
            fn () => static::where('key', $key)->value('value')
        );

        return $value !== null && $value !== '' ? $value : $default;
    }

    public static function put(string $key, ?string $value, ?string $actor = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor]);

        cache()->forget('app_setting:' . $key);
    }

    public static function clear(string $key): void
    {
        static::where('key', $key)->delete();

        cache()->forget('app_setting:' . $key);
    }

    /**
     * The portal-wide auto-refresh intervals, with the defaults filled in.
     *
     * Always complete, so no caller has to know what the fallback is — one place
     * that knows is what stops the dashboard and the settings form disagreeing
     * about what "default" means.
     *
     * An unrecognised interval falls back rather than being honoured. A stale
     * value from an older build, or a hand-edited row, must not put a
     * two-second poll on every monitored database.
     *
     * @return array<string,int>
     */
    public static function refreshIntervals(): array
    {
        $out = [];

        foreach (User::REFRESH_DEFAULTS as $key => $default) {
            $stored = static::get($key);

            $out[$key] = $stored !== null && in_array((int) $stored, User::REFRESH_CHOICES, true)
                ? (int) $stored
                : $default;
        }

        return $out;
    }

    /**
     * Saves the portal-wide intervals.
     *
     * Merged over what is stored rather than replacing the lot, so a key this
     * form does not carry is left alone. Wrapped in a transaction because the
     * two keys are two rows and a half-applied pair would leave the portal
     * polling one screen on the new interval and the other on the old.
     *
     * @param  array<string,int> $intervals
     * @return array<string,int> the intervals as they now stand
     */
    public static function putRefreshIntervals(array $intervals, ?string $actor = null): array
    {
        DB::transaction(function () use ($intervals, $actor) {
            foreach ($intervals as $key => $seconds) {
                if (!array_key_exists($key, User::REFRESH_DEFAULTS)) {
                    continue;
                }

                static::put($key, (string) (int) $seconds, $actor);
            }
        });

        return static::refreshIntervals();
    }
}
