<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
