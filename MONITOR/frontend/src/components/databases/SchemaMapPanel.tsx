import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  Clock,
  Link2,
  Loader2,
  Network,
  Table2,
  XCircle,
} from 'lucide-react';
import Card, { CardHeader, CardBody } from '../reporting/Card';
import { Button, Pill } from '../reporting/primitives';
import { useTheme } from '../../hooks/useTheme';
import { databaseService } from '../../services/databaseService';
import {
  DatabaseConnection,
  MappingMetric,
  MappingRelation,
  MappingTable,
  SchemaMapping,
} from '../../types/databases';
import { formatNumber } from '../../utils/format';

/**
 * Which metric cards this database feeds, and through which tables.
 *
 * ── The question this answers ─────────────────────────────────────────
 *
 * "Where does this number come from." Until now the only two answers available
 * on this page were the connection test — which proves the credential reaches
 * *a* database — and the table inspector, which lists tables and columns. Neither
 * connects either end: an operator looking at a Repairs figure that seems short
 * could confirm `service_orders` exists and had rows, and still not know that the
 * count reaches a subscriber through a free-text account number, or that the
 * count is dated on `timestamp` because this schema never got `updated_at`.
 *
 * So the map is drawn from the card end. Pick a metric and the tables behind it
 * light up, the linkages between them are drawn out with the actual join
 * condition, and anything missing is named. Hovering does the same thing
 * without committing, because the common use is comparing two cards quickly
 * rather than studying one.
 *
 * ── Why the relationships are declared, not introspected ──────────────
 *
 * Half of them are not foreign keys. `service_orders` reaches an account by
 * matching `account_no` as a string; an application matches a plan on a
 * lower-cased name because there was no plan row when it was filed. A FOREIGN KEY
 * listing would show neither, and those two are the ones that fail silently —
 * a spelling difference produces a plausible total rather than an obvious gap.
 * See SchemaMap::RELATIONS for the declarations themselves.
 */

/** Colour and wording per linkage kind — they fail in different ways. */
const RELATION_KIND: Record<MappingRelation['kind'], { label: string; tone: string }> = {
  fk: { label: 'foreign key', tone: 'text-blue-500' },
  lookup: { label: 'lookup', tone: 'text-violet-500' },
  match: { label: 'text match', tone: 'text-amber-500' },
};

const SchemaMapPanel: React.FC<{ connection: DatabaseConnection; onClose: () => void }> = ({
  connection,
  onClose,
}) => {
  const isDarkMode = useTheme();

  const [mapping, setMapping] = useState<SchemaMapping | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  /** The metric card that is pinned by a click. */
  const [selected, setSelected] = useState<string | null>(null);
  /** The one under the pointer. Hover previews without disturbing the pin. */
  const [hovered, setHovered] = useState<string | null>(null);

  // Hover wins while it lasts, so moving across the cards compares them without
  // a click each; letting go returns to whatever was pinned.
  const active = hovered ?? selected;

  useEffect(() => {
    let cancelled = false;

    setLoading(true);

    databaseService
      .mapping(connection.id)
      .then((result) => {
        if (cancelled) return;

        setMapping(result);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;

        setError(err?.response?.data?.message || 'Could not read this database.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [connection.id]);

  // Memoised rather than defaulted inline: `?? []` builds a fresh array on
  // every render, which would make the two derivations below re-run on each one
  // and defeat the point of memoising them at all.
  const metrics = useMemo(() => mapping?.metrics ?? [], [mapping]);
  const relations = useMemo(() => mapping?.relations ?? [], [mapping]);
  const tables = mapping?.tables ?? [];

  const activeMetric = useMemo(
    () => metrics.find((metric) => metric.key === active) ?? null,
    [metrics, active]
  );

  /**
   * Tables the active card reads. Empty with nothing selected, which the table
   * column reads as "highlight none" rather than "highlight all" — a map where
   * everything is lit says nothing.
   */
  const litTables = useMemo(
    () => new Set(activeMetric?.tables ?? []),
    [activeMetric]
  );

  // A linkage is shown for a card when the card reads both of its ends. One end
  // in common is not a relationship this figure travels along.
  const litRelations = useMemo(
    () =>
      activeMetric
        ? relations.filter((row) => litTables.has(row.from) && litTables.has(row.to))
        : [],
    [activeMetric, relations, litTables]
  );

  return (
    <Card flush>
      <CardHeader
        title={`Data map — ${connection.label}`}
        subtitle="Which figures this database feeds, and how its tables reach each other"
        icon={<Network size={16} />}
        actions={
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        }
      />
      <CardBody>
        {loading ? (
          <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            <Loader2 size={14} className="inline animate-spin mr-2" />
            Reading the schema…
          </p>
        ) : error ? (
          <p className="text-sm text-red-500">{error}</p>
        ) : !mapping?.declared ? (
          <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            No mapping is declared for the <strong>{mapping?.driver}</strong> structure, so there is
            nothing to check this database against.
          </p>
        ) : (
          <>
            {mapping.summary && <Summary summary={mapping.summary} />}

            <p className={`text-xs mb-3 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Hover a card to preview what it reads; click to pin it.
              {active && (
                <>
                  {' '}
                  <button
                    type="button"
                    onClick={() => {
                      setSelected(null);
                      setHovered(null);
                    }}
                    className="underline text-blue-600 dark:text-blue-400"
                  >
                    Clear
                  </button>
                </>
              )}
            </p>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {/* ── Metric cards ─────────────────────────────────────── */}
              <div>
                <ColumnHeading icon={<Table2 size={13} />} label="Metric cards" />

                <div className="space-y-1.5 max-h-[360px] overflow-y-auto custom-scroll pr-1">
                  {metrics.length === 0 ? (
                    <p className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      No metric cards are declared for this structure.
                    </p>
                  ) : (
                    metrics.map((metric) => (
                      <MetricRow
                        key={metric.key}
                        metric={metric}
                        active={active === metric.key}
                        pinned={selected === metric.key}
                        onHover={setHovered}
                        onSelect={(key) =>
                          setSelected((current) => (current === key ? null : key))
                        }
                      />
                    ))
                  )}
                </div>
              </div>

              {/* ── Tables ───────────────────────────────────────────── */}
              <div>
                <ColumnHeading
                  icon={<Table2 size={13} />}
                  label={
                    activeMetric ? `Tables behind ${activeMetric.label}` : 'Tables this portal reads'
                  }
                />

                <div className="space-y-1.5 max-h-[360px] overflow-y-auto custom-scroll pr-1">
                  {tables.map((table) => (
                    <TableRow
                      key={table.table}
                      table={table}
                      lit={litTables.has(table.table)}
                      dimmed={Boolean(activeMetric) && !litTables.has(table.table)}
                    />
                  ))}
                </div>
              </div>
            </div>

            {/* ── Linkages ───────────────────────────────────────────── */}
            <div className="mt-4">
              <ColumnHeading
                icon={<Link2 size={13} />}
                label={
                  activeMetric
                    ? `Linkages ${activeMetric.label} travels along`
                    : 'How these tables reach each other'
                }
              />

              {activeMetric && litRelations.length === 0 ? (
                <p className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                  This figure is read from a single table — nothing is joined to produce it.
                </p>
              ) : (
                <div className="space-y-1.5">
                  {(activeMetric ? litRelations : relations).map((relation) => (
                    <RelationRow key={`${relation.from}-${relation.to}-${relation.on}`} relation={relation} />
                  ))}
                </div>
              )}
            </div>

            <p className={`mt-4 text-xs leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
              The linkages are declared rather than read from foreign keys, because several of them
              are not foreign keys: repairs reach an account by matching its number as text, and an
              application matches a plan on its name because there was no plan record when it was
              filed. Those are the joins that fail quietly — a mistyped account number produces a
              row with no subscriber attached rather than an error — so they are the ones worth
              being able to see.
            </p>
          </>
        )}
      </CardBody>
    </Card>
  );
};

const ColumnHeading: React.FC<{ icon: React.ReactNode; label: string }> = ({ icon, label }) => {
  const isDarkMode = useTheme();

  return (
    <p
      className={`flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider mb-2 ${
        isDarkMode ? 'text-gray-400' : 'text-gray-500'
      }`}
    >
      {icon}
      {label}
    </p>
  );
};

/** Present / missing / degraded, counted once so the detail below has a frame. */
const Summary: React.FC<{ summary: NonNullable<SchemaMapping['summary']> }> = ({ summary }) => {
  const isDarkMode = useTheme();

  const healthy = summary.missing_tables === 0 && summary.missing_columns === 0;

  return (
    <div
      className={`flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border px-3 py-2 mb-3 text-sm ${
        healthy
          ? isDarkMode
            ? 'border-emerald-800/60 bg-emerald-500/10 text-emerald-200'
            : 'border-emerald-200 bg-emerald-50 text-emerald-800'
          : isDarkMode
          ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
          : 'border-amber-200 bg-amber-50 text-amber-800'
      }`}
    >
      {healthy ? (
        <CheckCircle2 size={15} className="flex-shrink-0" />
      ) : (
        <AlertTriangle size={15} className="flex-shrink-0" />
      )}
      <span>
        <strong>
          {formatNumber(summary.present)} of {formatNumber(summary.expected)}
        </strong>{' '}
        expected tables present
      </span>
      {summary.missing_columns > 0 && (
        <span>{formatNumber(summary.missing_columns)} required columns missing</span>
      )}
      {/* A degraded date still computes — on a weaker timestamp. Worth seeing
          before somebody queries the figure rather than after. */}
      {summary.degraded_dates > 0 && (
        <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
          {formatNumber(summary.degraded_dates)} dated on a fallback column
        </span>
      )}
    </div>
  );
};

const MetricRow: React.FC<{
  metric: MappingMetric;
  active: boolean;
  pinned: boolean;
  onHover: (key: string | null) => void;
  onSelect: (key: string) => void;
}> = ({ metric, active, pinned, onHover, onSelect }) => {
  const isDarkMode = useTheme();

  return (
    <button
      type="button"
      // Both, so the map works for a pointer and for a keyboard: focus previews
      // exactly as hover does, which is what stops this being a mouse-only view.
      onMouseEnter={() => onHover(metric.key)}
      onMouseLeave={() => onHover(null)}
      onFocus={() => onHover(metric.key)}
      onBlur={() => onHover(null)}
      onClick={() => onSelect(metric.key)}
      aria-pressed={pinned}
      className={`w-full text-left rounded-lg border px-3 py-2 transition-colors ${
        active
          ? isDarkMode
            ? 'border-blue-600 bg-blue-500/10'
            : 'border-blue-400 bg-blue-50'
          : isDarkMode
          ? 'border-gray-800 hover:border-gray-700'
          : 'border-gray-200 hover:border-gray-300'
      }`}
    >
      <span className="flex items-start justify-between gap-2">
        <span className={`font-semibold text-sm ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
          {metric.label}
        </span>
        {metric.available ? (
          <Pill tone="success">served</Pill>
        ) : (
          <Pill tone="danger">unavailable</Pill>
        )}
      </span>

      <span className={`block text-[11px] mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
        {metric.page}
      </span>

      {/* The rule, only on the card being looked at. Shown for all of them the
          column becomes a wall of prose nobody scans. */}
      {active && (
        <span
          className={`block text-[11px] mt-1.5 leading-relaxed ${
            isDarkMode ? 'text-gray-300' : 'text-gray-600'
          }`}
        >
          {metric.basis}
        </span>
      )}

      {metric.missing.length > 0 && (
        <span className="block text-[11px] mt-1 text-red-500">
          missing: {metric.missing.join(', ')}
        </span>
      )}
    </button>
  );
};

const TableRow: React.FC<{ table: MappingTable; lit: boolean; dimmed: boolean }> = ({
  table,
  lit,
  dimmed,
}) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-lg border px-3 py-2 transition-all ${dimmed ? 'opacity-40' : ''} ${
        lit
          ? isDarkMode
            ? 'border-blue-600 bg-blue-500/10'
            : 'border-blue-400 bg-blue-50'
          : isDarkMode
          ? 'border-gray-800'
          : 'border-gray-200'
      }`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-1.5 min-w-0">
          {table.exists ? (
            table.healthy ? (
              <CheckCircle2 size={13} className="text-emerald-500 flex-shrink-0" />
            ) : (
              <AlertTriangle size={13} className="text-amber-500 flex-shrink-0" />
            )
          ) : (
            <XCircle size={13} className="text-red-500 flex-shrink-0" />
          )}
          <span
            className={`font-mono text-xs truncate ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}
          >
            {table.table}
          </span>
        </span>

        <span className={`text-[11px] flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
          {table.exists ? `${formatNumber(table.column_count)} columns` : 'absent'}
        </span>
      </div>

      {table.missing.length > 0 && (
        <p className="text-[11px] mt-0.5 text-red-500">missing: {table.missing.join(', ')}</p>
      )}

      {/* Which real column each dated figure landed on. The fallback case is
          called out because the figure still computes — it is just measuring a
          slightly different moment than the label claims. */}
      {table.dates.length > 0 && (
        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
          {table.dates.map((date) => (
            <span
              key={date.role}
              className={`flex items-center gap-1 text-[11px] ${
                date.resolved === null
                  ? 'text-red-500'
                  : date.degraded
                  ? 'text-amber-500'
                  : isDarkMode
                  ? 'text-gray-500'
                  : 'text-gray-400'
              }`}
              title={
                date.resolved === null
                  ? `This schema has no column for "${date.role}" at all.`
                  : date.degraded
                  ? `Dated on ${date.resolved} because ${date.preferred} is absent.`
                  : undefined
              }
            >
              <Clock size={10} />
              {date.role}: {date.resolved ?? 'none'}
            </span>
          ))}
        </div>
      )}
    </div>
  );
};

const RelationRow: React.FC<{ relation: MappingRelation }> = ({ relation }) => {
  const isDarkMode = useTheme();
  const kind = RELATION_KIND[relation.kind];

  return (
    <div
      className={`rounded-lg border px-3 py-2 ${relation.available ? '' : 'opacity-60'} ${
        isDarkMode ? 'border-gray-800' : 'border-gray-200'
      }`}
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className={`font-mono text-xs ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
          {relation.from}
        </span>
        <ArrowRight size={13} className={kind.tone} />
        <span className={`font-mono text-xs ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
          {relation.to}
        </span>
        <span className={`text-[11px] font-semibold ${kind.tone}`}>{kind.label}</span>

        {!relation.available && (
          <span className="text-[11px] text-red-500">— one end is missing here</span>
        )}
      </div>

      <p className={`font-mono text-[11px] mt-1 break-all ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {relation.on}
      </p>
      <p className={`text-[11px] mt-0.5 leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        {relation.note}
      </p>
    </div>
  );
};

export default SchemaMapPanel;
