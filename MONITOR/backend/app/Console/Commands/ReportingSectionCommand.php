<?php

namespace App\Console\Commands;

use App\Services\ReportingService;
use Illuminate\Console\Command;

/**
 * Computes one section against one database and prints the payload as JSON.
 *
 * An internal worker, not a command anyone runs by hand: ParallelSectionRunner
 * starts one of these per database so their remote-server waits overlap. Filters
 * arrive as JSON on stdin rather than as arguments — a search term could contain
 * anything, and argv brings both escaping pitfalls and a length limit on Windows.
 *
 * Contract with the parent, which the runner relies on:
 *
 *   success   exit 0, stdout is {"ok":true,"payload":{...}}
 *   failure   exit 1, stdout is {"ok":false,"error":"..."}
 *
 * Nothing else may reach stdout, so output buffering is discarded first and a
 * stray warning is treated by the parent as a broken child rather than as data.
 * The payload is *not* cached here: the parent stores what comes back, because a
 * child cannot be relied on to share the parent's cache store or path.
 */
class ReportingSectionCommand extends Command
{
    protected $signature = 'reporting:section
        {source : the database key}
        {section : subscriber_analytics|financial|operations|tech|employee}';

    protected $description = 'Internal: compute one reporting section for one database (used by the parallel fan-out)';

    /** Hidden from `artisan list`: it is plumbing, not an operator command. */
    protected $hidden = true;

    public function handle(ReportingService $reporting): int
    {
        $source = (string) $this->argument('source');
        $section = (string) $this->argument('section');

        try {
            $params = $this->readParams();

            $payload = $reporting->computeSection($source, $section, $params);

            $this->emit(['ok' => true, 'payload' => $payload]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Reported as data, not as an exception trace: the parent turns this
            // into "this database could not be reached" on one card while the
            // other databases still render.
            $this->emit(['ok' => false, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }

    /**
     * Filters from stdin.
     *
     * Absent or unreadable stdin yields an empty filter set, which the drivers
     * treat as their defaults — the same as an unfiltered request.
     */
    private function readParams(): array
    {
        $raw = @stream_get_contents(STDIN);

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Could not read the filter set from stdin.');
        }

        $params = $decoded['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Writes the single JSON line the parent parses.
     *
     * Any buffered output is thrown away first: a deprecation notice printed
     * before this point would otherwise be prepended to the JSON and make the
     * whole response unparseable.
     */
    private function emit(array $result): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
