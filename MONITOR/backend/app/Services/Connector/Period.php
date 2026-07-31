<?php

namespace App\Services\Connector;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * The daily / weekly / monthly / yearly window every dashboard shares.
 *
 * Kept as one object so a KPI card, a trend chart and a PDF report can never
 * disagree about what "this month" meant.
 */
class Period
{
    public const GRANULARITIES = ['daily', 'weekly', 'monthly', 'yearly'];

    private function __construct(
        public string $granularity,
        public Carbon $from,
        public Carbon $to,
        public Carbon $anchor,
        public string $label
    ) {
    }

    public static function make(string $granularity, ?string $asOf = null): self
    {
        $granularity = in_array($granularity, self::GRANULARITIES, true) ? $granularity : 'monthly';
        $anchor = self::anchor($asOf);

        switch ($granularity) {
            case 'daily':
                return new self($granularity, $anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(),
                    $anchor, $anchor->format('M d, Y'));

            case 'weekly':
                // Calendar week (Mon-Sun) rather than "last 7 days": an
                // executive comparing weeks needs them to line up.
                $start = $anchor->copy()->startOfWeek(Carbon::MONDAY);
                $end = $anchor->copy()->endOfWeek(Carbon::SUNDAY);

                return new self($granularity, $start, $end, $anchor,
                    $start->format('M d') . ' – ' . $end->format('M d, Y'));

            case 'yearly':
                return new self($granularity, $anchor->copy()->startOfYear(), $anchor->copy()->endOfYear(),
                    $anchor, $anchor->format('Y'));

            default:
                return new self('monthly', $anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(),
                    $anchor, $anchor->format('F Y'));
        }
    }

    /** The equivalent window one step earlier, for period-on-period change. */
    public function previous(): self
    {
        switch ($this->granularity) {
            case 'daily':
                return self::make('daily', $this->anchor->copy()->subDay()->toDateString());
            case 'weekly':
                return self::make('weekly', $this->anchor->copy()->subWeek()->toDateString());
            case 'yearly':
                return self::make('yearly', $this->anchor->copy()->subYear()->toDateString());
            default:
                return self::make('monthly', $this->anchor->copy()->subMonthNoOverflow()->toDateString());
        }
    }

    /**
     * How far back a trend chart reaches, and how its buckets are labelled.
     *
     * @return array{from:Carbon,bucket:string,label:string}
     */
    public function trend(string $column): array
    {
        switch ($this->granularity) {
            case 'daily':
                return [
                    'from' => $this->anchor->copy()->subDays(29)->startOfDay(),
                    'bucket' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
                    'label' => "DATE_FORMAT({$column}, '%b %d')",
                ];
            case 'weekly':
                return [
                    'from' => $this->anchor->copy()->subWeeks(11)->startOfWeek(Carbon::MONDAY),
                    'bucket' => "CONCAT(YEARWEEK({$column}, 3))",
                    'label' => "CONCAT('Wk', LPAD(WEEK({$column}, 3), 2, '0'))",
                ];
            case 'yearly':
                return [
                    'from' => $this->anchor->copy()->subYears(9)->startOfYear(),
                    'bucket' => "DATE_FORMAT({$column}, '%Y')",
                    'label' => "DATE_FORMAT({$column}, '%Y')",
                ];
            default:
                return [
                    'from' => $this->anchor->copy()->startOfMonth()->subMonths(11),
                    'bucket' => "DATE_FORMAT({$column}, '%Y-%m')",
                    'label' => "DATE_FORMAT({$column}, '%b %Y')",
                ];
        }
    }

    public function toArray(): array
    {
        return [
            'granularity' => $this->granularity,
            'label' => $this->label,
            'from' => $this->from->toDateTimeString(),
            'to' => $this->to->toDateTimeString(),
            'as_of' => $this->anchor->toDateString(),
        ];
    }

    private static function anchor(?string $asOf): Carbon
    {
        if ($asOf === null || $asOf === '') {
            return Carbon::now();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        return Carbon::createFromFormat('Y-m-d', $asOf)->startOfDay();
    }
}
