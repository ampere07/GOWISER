<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportPeriod;
use App\Services\ReportingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pre-computes the reporting sections so the first viewer does not pay for the
 * whole fan-out.
 *
 * Warming only helps if it runs more often than the cache expires, so schedule it
 * below monitor.cache_ttl — App\Console\Kernel does. Run under it and every warm
 * pass fills an entry that has already gone.
 *
 * Only the *default* filter set is warmed: month-to-date, no branch, no search.
 * Warming every range someone might pick is not possible, and guessing at a
 * handful would spend queries on ranges nobody opens. A page opened with the
 * defaults — which is how a page is opened — is warm; change a filter and you pay
 * for that fan-out once.
 *
 * Because each database is cached separately, one aggregate pass also warms every
 * single-database view of the same section. That is why there is no separate
 * per-database loop here.
 */
class WarmReportingCache extends Command
{
    protected $signature = 'reporting:warm
        {--section=* : limit to these sections, default all configured}
        {--quiet-log : do not write a summary to the log}';

    protected $description = 'Pre-compute the reporting sections so the first viewer hits a warm cache';

    public function handle(ReportingService $reporting): int
    {
        if (!config('reporting.warm.enabled', true)) {
            $this->components->warn('Cache warming is disabled (reporting.warm.enabled).');

            return self::SUCCESS;
        }

        $sections = $this->sections();

        if ($sections === []) {
            $this->components->error('No sections to warm.');

            return self::FAILURE;
        }

        // The same defaults the frontend opens with, so the entries warmed here
        // are the ones a page load actually asks for.
        $params = $this->defaultParams();

        $started = microtime(true);
        $warmed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sections as $section) {
            $sources = $reporting->sourcesFor($section);

            if ($sources === []) {
                $this->components->twoColumnDetail($section, '<fg=gray>no database serves it</>');
                $skipped++;

                continue;
            }

            $sectionStarted = microtime(true);

            try {
                // aggregate() skips databases that are already warm and fans out
                // over the rest, so a second run in the same window is cheap.
                $result = $reporting->aggregate($section, $params);

                $elapsed = round((microtime(true) - $sectionStarted) * 1000);
                $info = $result['aggregate'] ?? null;

                if ($info === null) {
                    // One database serves this section, so there was nothing to
                    // merge and no aggregate block.
                    $detail = sprintf('<fg=green>warmed</> <fg=gray>1 database, %dms</>', $elapsed);
                } else {
                    $detail = sprintf(
                        '<fg=green>warmed</> <fg=gray>%d/%d databases, %dms</>',
                        count($info['answered']),
                        $info['total_databases'],
                        $elapsed
                    );

                    if ($info['failed'] !== []) {
                        $detail .= sprintf(
                            ' <fg=yellow>(%s unreachable)</>',
                            implode(', ', array_column($info['failed'], 'label'))
                        );
                    }
                }

                $this->components->twoColumnDetail($section, $detail);
                $warmed++;
            } catch (\Throwable $e) {
                // One section failing must not abort the rest: a scheduled job
                // that gives up halfway leaves the remaining pages cold.
                $this->components->twoColumnDetail(
                    $section,
                    '<fg=red>failed</> <fg=gray>' . $e->getMessage() . '</>'
                );

                $failed++;
            }
        }

        $elapsed = round(microtime(true) - $started, 1);

        $summary = sprintf(
            '%d warmed, %d skipped, %d failed in %ss',
            $warmed,
            $skipped,
            $failed,
            $elapsed
        );

        $this->newLine();
        $this->components->info($summary);

        if (!$this->option('quiet-log')) {
            Log::info('Reporting cache warmed: ' . $summary);
        }

        // Non-zero only when nothing succeeded — otherwise a single unreachable
        // branch would mark every scheduled run as failed.
        return $warmed > 0 || $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return string[] */
    private function sections(): array
    {
        $configured = config('reporting.warm.sections', ReportingService::SECTIONS);
        $requested = (array) $this->option('section');

        if ($requested !== []) {
            return array_values(array_intersect($configured, $requested));
        }

        return array_values(array_intersect(ReportingService::SECTIONS, $configured));
    }

    /**
     * The filter set a page opens with: month-to-date, everything unfiltered.
     *
     * Must match the frontend's defaultFilters(), or this warms entries nobody
     * requests and every real page load still pays full price.
     */
    private function defaultParams(): array
    {
        $today = ReportPeriod::anchor();

        return [
            'date_from' => $today->copy()->startOfMonth()->toDateString(),
            'date_to' => $today->toDateString(),
            'branch' => null,
            'as_of' => null,
            'period' => 'monthly',
            'branch_period' => 'monthly',
            'branch_year' => (int) $today->format('Y'),
            'geo_region' => '',
            'geo_province' => '',
            'geo_municipality' => '',
            'overdue_search' => '',
            'overdue_plan_id' => 0,
            'overdue_bucket' => '',
            'overdue_page' => 1,
        ];
    }
}
