<?php

namespace App\Services\Connector;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Every number on every dashboard comes out of here.
 *
 * The engine speaks only canonical names — "sum the payments dataset over this
 * period" — and the DatasetMap turns that into the site's real tables. Adding
 * a site therefore never means touching dashboard code.
 *
 * Three shapes cover the whole product:
 *   total()      one figure for a window
 *   series()     that figure bucketed over time, for trend charts
 *   breakdown()  that figure split by a dimension, for tables and doughnuts
 */
class MetricEngine
{
    public function __construct(private ConnectionManager $connections)
    {
    }

    public function supports(string $site, string $dataset): bool
    {
        return $this->connections->supports($site, $dataset);
    }

    /**
     * One aggregate for a window.
     *
     * @param array $opts measure, date_field, filters, distinct
     * @return array{value:float,count:int}
     */
    public function total(string $site, string $dataset, ?Period $period = null, array $opts = []): array
    {
        $map = $this->connections->map($site, $dataset);
        $query = $this->base($site, $map, $period, $opts);

        $measure = $this->measure($dataset, $map, $opts);

        $row = $query
            ->selectRaw($measure ? "COALESCE(SUM({$measure}), 0) AS agg" : '0 AS agg')
            ->selectRaw('COUNT(*) AS rows_total')
            ->first();

        return [
            'value' => round((float) ($row->agg ?? 0), 2),
            'count' => (int) ($row->rows_total ?? 0),
        ];
    }

    /**
     * The aggregate bucketed over time. Buckets with no rows are filled in as
     * zero so a quiet month cannot silently vanish from a trend line.
     *
     * @return array<int,array{period:string,label:string,value:float,count:int}>
     */
    public function series(string $site, string $dataset, Period $period, array $opts = []): array
    {
        $map = $this->connections->map($site, $dataset);
        $dateField = $this->dateField($dataset, $map, $opts);

        if (!$dateField) {
            throw new RuntimeException("Dataset [{$dataset}] has no date mapped, so it cannot be charted over time.");
        }

        $column = $map->column($dateField);
        $trend = $period->trend($column);
        $measure = $this->measure($dataset, $map, $opts);

        $rows = $this->base($site, $map, null, $opts)
            ->where(DB::raw($column), '>=', $trend['from'])
            ->where(DB::raw($column), '<=', $period->to)
            ->selectRaw("{$trend['bucket']} AS bucket")
            ->selectRaw("MIN({$trend['label']}) AS label")
            ->selectRaw($measure ? "COALESCE(SUM({$measure}), 0) AS agg" : '0 AS agg')
            ->selectRaw('COUNT(*) AS rows_total')
            ->groupBy(DB::raw($trend['bucket']))
            ->orderBy(DB::raw($trend['bucket']))
            ->get();

        return $rows->map(fn ($row) => [
            'period' => (string) $row->bucket,
            'label' => (string) $row->label,
            'value' => round((float) $row->agg, 2),
            'count' => (int) $row->rows_total,
        ])->all();
    }

    /**
     * The aggregate split by a mapped dimension, largest first.
     *
     * @return array<int,array{label:string,value:float,count:int}>
     */
    public function breakdown(
        string $site,
        string $dataset,
        string $field,
        ?Period $period = null,
        array $opts = []
    ): array {
        $map = $this->connections->map($site, $dataset);

        if (!$map->has($field)) {
            return [];
        }

        $column = $map->column($field);
        $measure = $this->measure($dataset, $map, $opts);
        $limit = (int) ($opts['limit'] ?? 12);
        $fallback = $opts['empty_label'] ?? 'Unspecified';

        $query = $this->base($site, $map, $period, $opts)
            ->selectRaw("{$column} AS label")
            ->selectRaw($measure ? "COALESCE(SUM({$measure}), 0) AS agg" : '0 AS agg')
            ->selectRaw('COUNT(*) AS rows_total')
            ->groupBy(DB::raw($column));

        // Order by whichever number the caller is actually looking at.
        $query->orderByRaw($measure ? "SUM({$measure}) DESC" : 'COUNT(*) DESC');

        // NULL and empty labels become the fallback here rather than in SQL,
        // which keeps the literal out of the query entirely.
        return $query->limit($limit)->get()->map(function ($row) use ($fallback) {
            $label = $row->label === null ? '' : trim((string) $row->label);

            return [
                'label' => $label === '' ? $fallback : $label,
                'value' => round((float) $row->agg, 2),
                'count' => (int) $row->rows_total,
            ];
        })->all();
    }

    /**
     * Counts per value of a mapped field, ignoring any measure. Used for
     * status mixes (online/offline, pending/done) where money is irrelevant.
     *
     * @return array<string,int>
     */
    public function statusCounts(string $site, string $dataset, string $field = 'status', ?Period $period = null, array $opts = []): array
    {
        $rows = $this->breakdown($site, $dataset, $field, $period, array_merge($opts, [
            'measure' => false,
            'limit' => $opts['limit'] ?? 30,
        ]));

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['label']] = $row['count'];
        }

        return $counts;
    }

    /**
     * Period-on-period movement for a headline figure.
     *
     * @return array{current:float,previous:float,change_pct:float|null}
     */
    public function compare(string $site, string $dataset, Period $period, array $opts = []): array
    {
        $current = $this->total($site, $dataset, $period, $opts)['value'];
        $previous = $this->total($site, $dataset, $period->previous(), $opts)['value'];

        return [
            'current' => $current,
            'previous' => $previous,
            // A jump from zero is not "infinite growth"; report it as unknown
            // rather than rendering a meaningless percentage.
            'change_pct' => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────

    private function base(string $site, DatasetMap $map, ?Period $period, array $opts): Builder
    {
        $query = $map->query($this->connections->connection($site));

        // Window-dependent filters need the granularity, so they are applied
        // here rather than inside the map. `granularity` in $opts lets series()
        // pass its own window when no period is bound to the query.
        $granularity = $opts['granularity'] ?? $period->granularity ?? null;

        if ($granularity) {
            $map->applyGranularityFilters($query, $granularity);
        }

        if ($period !== null) {
            $dateField = $this->dateField($map->dataset, $map, $opts);

            if ($dateField && $map->has($dateField)) {
                $column = $map->column($dateField);
                $query->where(DB::raw($column), '>=', $period->from)
                    ->where(DB::raw($column), '<=', $period->to);
            }
        }

        // Runtime filters, same declarative shape as the profile's own.
        foreach ($opts['filters'] ?? [] as $filter) {
            $this->applyRuntimeFilter($query, $map, $filter);
        }

        return $query;
    }

    private function applyRuntimeFilter(Builder $query, DatasetMap $map, array $filter): void
    {
        // Runtime filters address canonical fields, not real columns.
        $field = $filter['field'] ?? null;

        if (!$field || !$map->has($field)) {
            return;
        }

        $column = DB::raw($map->column($field));
        $op = strtolower($filter['op'] ?? 'eq');
        $value = $filter['value'] ?? null;

        switch ($op) {
            case 'in':
                $query->whereIn($column, (array) $value);
                break;
            case 'not_in':
                $query->whereNotIn($column, (array) $value);
                break;
            case 'not_null':
                $query->whereNotNull($column);
                break;
            case 'null':
                $query->whereNull($column);
                break;
            case 'like':
                $query->where($column, 'like', '%' . $value . '%');
                break;
            case 'gt':
                $query->where($column, '>', $value);
                break;
            case 'lt':
                $query->where($column, '<', $value);
                break;
            default:
                $query->where($column, '=', $value);
        }
    }

    /**
     * Which column gets summed. `measure => false` forces a pure row count,
     * which is what status breakdowns want.
     */
    private function measure(string $dataset, DatasetMap $map, array $opts): ?string
    {
        if (array_key_exists('measure', $opts) && $opts['measure'] === false) {
            return null;
        }

        $field = $opts['measure'] ?? config("datasets.{$dataset}.measure");

        if (!$field || !$map->has($field)) {
            return null;
        }

        return $map->column($field);
    }

    private function dateField(string $dataset, DatasetMap $map, array $opts): ?string
    {
        $field = $opts['date_field'] ?? config("datasets.{$dataset}.date");

        return ($field && $map->has($field)) ? $field : null;
    }
}
