<?php

namespace App\Services\Connector;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One canonical dataset resolved against one site: which real table it lives
 * in, which real column each canonical field is, and which rows to ignore.
 *
 * Everything the dashboards ask for is expressed through this object, so no
 * dashboard code ever names a real table or column.
 */
class DatasetMap
{
    public function __construct(
        public readonly string $dataset,
        public readonly string $table,
        public readonly string $alias,
        /** @var array<string,string> canonical field => real column */
        public readonly array $fields,
        /** @var array<int,array> row filters baked into every query */
        public readonly array $filters = [],
        /** @var array<int,array> joins needed to reach mapped columns */
        public readonly array $joins = [],
        /** @var array{column:string,value:mixed}|null per-site row scope */
        public readonly ?array $scope = null
    ) {
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]) && $this->fields[$field] !== '';
    }

    /**
     * Qualified column for a canonical field, e.g. "t.received_payment".
     *
     * A mapping may point at an expression rather than a plain column (for
     * example COALESCE of two date columns); those are passed through as-is.
     */
    public function column(string $field): string
    {
        if (!$this->has($field)) {
            throw new RuntimeException(
                "Field [{$field}] is not mapped for dataset [{$this->dataset}] on this site."
            );
        }

        $column = $this->fields[$field];

        // Already qualified, or an expression — leave it alone.
        if (str_contains($column, '.') || str_contains($column, '(')) {
            return $column;
        }

        return "{$this->alias}.{$column}";
    }

    /** Qualified column, or null when the field is not mapped. */
    public function columnOrNull(string $field): ?string
    {
        return $this->has($field) ? $this->column($field) : null;
    }

    /**
     * Base query with joins, baked-in filters and the site scope applied.
     * Callers add their own aggregation on top.
     */
    public function query(ConnectionInterface $db): Builder
    {
        $query = $db->table("{$this->table} as {$this->alias}");

        foreach ($this->joins as $join) {
            $type = strtolower($join['type'] ?? 'left');
            $target = isset($join['alias']) ? "{$join['table']} as {$join['alias']}" : $join['table'];

            if ($type === 'inner') {
                $query->join($target, DB::raw($join['first']), '=', DB::raw($join['second']));
            } else {
                $query->leftJoin($target, DB::raw($join['first']), '=', DB::raw($join['second']));
            }
        }

        foreach ($this->filters as $filter) {
            $this->applyFilter($query, $filter);
        }

        // A shared database where sites are separated by a column rather than
        // by their own schema.
        if ($this->scope && !empty($this->scope['column'])) {
            $column = $this->scope['column'];
            $column = str_contains($column, '.') ? $column : "{$this->alias}.{$column}";

            $query->where(DB::raw($column), '=', $this->scope['value']);
        }

        return $query;
    }

    /**
     * Filters are declared as data so they can be edited in the admin screen
     * without shipping code. `nullable` lets NULL pass, which matters for
     * "status is empty or not cancelled" style rules.
     */
    private function applyFilter(Builder $query, array $filter): void
    {
        $rawColumn = $filter['column'] ?? null;

        if (!$rawColumn) {
            return;
        }

        $column = str_contains($rawColumn, '.') || str_contains($rawColumn, '(')
            ? $rawColumn
            : "{$this->alias}.{$rawColumn}";

        $op = strtolower($filter['op'] ?? 'eq');
        $value = $filter['value'] ?? null;
        $nullable = (bool) ($filter['nullable'] ?? false);

        $apply = function (Builder $q) use ($column, $op, $value) {
            $expr = DB::raw($column);

            switch ($op) {
                case 'null':
                    $q->whereNull($expr);
                    break;
                case 'not_null':
                    $q->whereNotNull($expr);
                    break;
                case 'in':
                    $q->whereIn($expr, (array) $value);
                    break;
                case 'not_in':
                    $q->whereNotIn($expr, (array) $value);
                    break;
                case 'in_ci':
                    $q->whereIn(DB::raw("LOWER({$column})"), array_map('strtolower', (array) $value));
                    break;
                case 'not_in_ci':
                    $q->whereNotIn(DB::raw("LOWER({$column})"), array_map('strtolower', (array) $value));
                    break;
                case 'like':
                    $q->where($expr, 'like', $value);
                    break;
                case 'not_like':
                    $q->where($expr, 'not like', $value);
                    break;
                case 'gt':
                case 'gte':
                case 'lt':
                case 'lte':
                case 'not_eq':
                    $map = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'not_eq' => '<>'];
                    $q->where($expr, $map[$op], $value);
                    break;
                default:
                    $q->where($expr, '=', $value);
            }
        };

        if ($nullable) {
            $query->where(function (Builder $q) use ($apply, $column) {
                $q->whereNull(DB::raw($column));
                $q->orWhere(fn (Builder $inner) => $apply($inner));
            });

            return;
        }

        $apply($query);
    }
}
