<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled report definition.
 *
 * The schedule vocabulary lives here so the API validator, the queueing command
 * and the front-end form all agree on which fields a given schedule requires.
 * Previously each of the three had its own idea, which is why the form demanded
 * a day-of-month even for "Every Day".
 */
class Report extends Model
{
    use HasFactory;

    public const SCHEDULE_DAILY     = 'Every Day';
    public const SCHEDULE_WEEKLY    = 'Every Week';
    public const SCHEDULE_MONTHLY   = 'Every Month';
    public const SCHEDULE_QUARTERLY = 'Every 3 Months';
    public const SCHEDULE_YEARLY    = 'Every Year';

    /**
     * Which extra fields each schedule needs.
     *
     *   day      day of month (1–31)
     *   weekday  Monday…Sunday
     *   month    1–12 (anchor month; quarterly also fires +3, +6, +9 from it)
     */
    public const SCHEDULE_REQUIREMENTS = [
        self::SCHEDULE_DAILY     => [],
        self::SCHEDULE_WEEKLY    => ['weekday'],
        self::SCHEDULE_MONTHLY   => ['day'],
        self::SCHEDULE_QUARTERLY => ['day', 'month'],
        self::SCHEDULE_YEARLY    => ['month', 'day'],
    ];

    public const WEEKDAYS = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
    ];

    /**
     * Legacy schedule spellings seen in existing rows, mapped onto the canonical
     * values so old records keep firing after this change.
     */
    private const SCHEDULE_ALIASES = [
        'daily'          => self::SCHEDULE_DAILY,
        'every day'      => self::SCHEDULE_DAILY,
        'everyday'       => self::SCHEDULE_DAILY,
        'weekly'         => self::SCHEDULE_WEEKLY,
        'every week'     => self::SCHEDULE_WEEKLY,
        'monthly'        => self::SCHEDULE_MONTHLY,
        'every month'    => self::SCHEDULE_MONTHLY,
        'quarterly'      => self::SCHEDULE_QUARTERLY,
        'every 3 months' => self::SCHEDULE_QUARTERLY,
        'every quarter'  => self::SCHEDULE_QUARTERLY,
        'yearly'         => self::SCHEDULE_YEARLY,
        'annually'       => self::SCHEDULE_YEARLY,
        'every year'     => self::SCHEDULE_YEARLY,
    ];

    protected $fillable = [
        'report_name',
        'report_type',
        'report_schedule',
        'report_time',
        'day',
        'report_weekday',
        'report_month',
        'send_to',
        'date_range',
        'created_by',
        'file_url',
        'csv_file_url',
        'is_active',
        'organization_id',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'report_month'       => 'integer',
        'last_dispatched_at' => 'datetime',
        'created_at'         => 'datetime',
    ];

    public $timestamps = false;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    public function dispatches(): HasMany
    {
        return $this->hasMany(ReportDispatch::class);
    }

    // ── Schedule vocabulary ───────────────────────────────────────────────────

    public static function schedules(): array
    {
        return array_keys(self::SCHEDULE_REQUIREMENTS);
    }

    /** Normalise a stored/user-supplied schedule to its canonical form. */
    public static function normalizeSchedule(?string $schedule): ?string
    {
        $key = strtolower(trim((string) $schedule));

        if ($key === '') {
            return null;
        }

        return self::SCHEDULE_ALIASES[$key]
            ?? (isset(self::SCHEDULE_REQUIREMENTS[$schedule]) ? $schedule : null);
    }

    public function canonicalSchedule(): ?string
    {
        return self::normalizeSchedule($this->report_schedule);
    }

    /** Which extra fields this report's schedule requires. */
    public function requiredScheduleFields(): array
    {
        $schedule = $this->canonicalSchedule();

        return $schedule ? self::SCHEDULE_REQUIREMENTS[$schedule] : [];
    }

    public static function requiresField(?string $schedule, string $field): bool
    {
        $canonical = self::normalizeSchedule($schedule);

        return $canonical !== null
            && in_array($field, self::SCHEDULE_REQUIREMENTS[$canonical], true);
    }

    /** Normalise a weekday name; returns null when unrecognised. */
    public static function normalizeWeekday(?string $weekday): ?string
    {
        $key = strtolower(trim((string) $weekday));

        foreach (self::WEEKDAYS as $name) {
            if (strtolower($name) === $key || strtolower(substr($name, 0, 3)) === $key) {
                return $name;
            }
        }

        return null;
    }

    // ── Schedule evaluation ───────────────────────────────────────────────────

    /**
     * Does this report's schedule fire on the given date?
     *
     * Time-of-day is deliberately not considered here — the caller decides which
     * minute to fire in — so this method is directly unit-testable.
     */
    public function firesOn(Carbon $date): bool
    {
        $schedule = $this->canonicalSchedule();
        if ($schedule === null) {
            return false;
        }

        switch ($schedule) {
            case self::SCHEDULE_DAILY:
                return true;

            case self::SCHEDULE_WEEKLY:
                $weekday = self::normalizeWeekday($this->report_weekday);

                // No weekday recorded (legacy row): fall back to the weekday the
                // report was created on rather than never firing.
                if ($weekday === null) {
                    $weekday = $this->created_at
                        ? Carbon::parse($this->created_at)->format('l')
                        : 'Monday';
                }

                return $date->format('l') === $weekday;

            case self::SCHEDULE_MONTHLY:
                return $this->matchesDayOfMonth($date);

            case self::SCHEDULE_QUARTERLY:
                if (!$this->matchesDayOfMonth($date)) {
                    return false;
                }

                $anchor = $this->anchorMonth();

                // Fires in the anchor month and every third month after it, in
                // both directions, so the quarter cycle is stable regardless of
                // which month the check runs in. The old modulo on
                // (currentMonth - createdMonth) went negative for months before
                // the creation month and skipped them.
                return ((($date->month - $anchor) % 3) + 3) % 3 === 0;

            case self::SCHEDULE_YEARLY:
                return $date->month === $this->anchorMonth()
                    && $this->matchesDayOfMonth($date);
        }

        return false;
    }

    /**
     * Day-of-month match, clamped to the length of the month.
     *
     * A report set to day 31 must still fire in February — otherwise it silently
     * skips four to five months a year.
     */
    private function matchesDayOfMonth(Carbon $date): bool
    {
        $day = (int) $this->day;
        if ($day < 1) {
            return false;
        }

        $effective = min($day, $date->daysInMonth);

        return $date->day === $effective;
    }

    private function anchorMonth(): int
    {
        $month = (int) $this->report_month;
        if ($month >= 1 && $month <= 12) {
            return $month;
        }

        return $this->created_at ? Carbon::parse($this->created_at)->month : 1;
    }

    /** Scheduled send time as H:i, or null when not set. */
    public function scheduledTime(): ?string
    {
        if (empty($this->report_time)) {
            return null;
        }

        try {
            return Carbon::parse($this->report_time)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Stable identifier for one scheduled occurrence, e.g. "2026-07-30_17:40".
     * Used as the dedupe key in report_dispatches.
     */
    public function occurrenceKey(Carbon $date): string
    {
        return $date->format('Y-m-d') . '_' . ($this->scheduledTime() ?? '00:00');
    }

    /** Human-readable schedule detail, e.g. "on day 15" or "every Monday". */
    public function scheduleDetail(): string
    {
        $schedule = $this->canonicalSchedule();

        switch ($schedule) {
            case self::SCHEDULE_WEEKLY:
                $weekday = self::normalizeWeekday($this->report_weekday);

                return $weekday ? "every {$weekday}" : '';

            case self::SCHEDULE_MONTHLY:
                return $this->day ? "on day {$this->day}" : '';

            case self::SCHEDULE_QUARTERLY:
                $parts = [];
                if ($this->report_month) {
                    $parts[] = 'starting ' . Carbon::create(null, (int) $this->report_month, 1)->format('F');
                }
                if ($this->day) {
                    $parts[] = "on day {$this->day}";
                }

                return implode(' ', $parts);

            case self::SCHEDULE_YEARLY:
                if ($this->report_month && $this->day) {
                    return 'on ' . Carbon::create(null, (int) $this->report_month, 1)->format('F')
                        . ' ' . (int) $this->day;
                }

                return '';
        }

        return '';
    }

    /** Recipient emails, trimmed, de-duplicated and validated. */
    public function recipients(): array
    {
        $raw = preg_split('/[,;\s]+/', (string) $this->send_to, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $emails = [];
        foreach ($raw as $candidate) {
            $email = trim($candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Case-insensitive de-dupe: "Admin@x.com" and "admin@x.com" are
                // the same mailbox and must not each receive a copy. The first
                // spelling wins so the order and casing the user typed survive.
                $emails[strtolower($email)] ??= $email;
            }
        }

        return array_values($emails);
    }

    /** Recipient entries that are not valid email addresses. */
    public function invalidRecipients(): array
    {
        $raw = preg_split('/[,;\s]+/', (string) $this->send_to, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map('trim', $raw),
            fn ($email) => $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
    }
}
