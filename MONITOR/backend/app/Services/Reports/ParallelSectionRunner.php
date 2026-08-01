<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Runs one section against several databases at the same time.
 *
 * Why processes. "All databases" spends almost all of its time *waiting* on
 * remote MySQL servers, not computing — eight databases at twenty-odd queries
 * each is a lot of round trips in sequence. Overlapping those waits is the whole
 * win. PHP cannot do it in one process without restructuring every driver query
 * to be asynchronous, so each database gets a short-lived child running
 * `php artisan reporting:section`, and the parent collects the JSON as children
 * finish.
 *
 * What it costs. One framework boot per child, on the order of 50–100ms. That is
 * why the runner declines below `min_sources`: with a single cold database, the
 * boot is pure overhead against one extra round trip. Cached databases never
 * reach here at all — the caller filters them out first.
 *
 * When it declines. Returning null means "I did not run; do it yourself", and
 * the caller falls back to the serial path. That happens when parallelism is
 * disabled, when there are too few databases to be worth it, when proc_open is
 * unavailable (shared hosting often disables it), or when a child cannot be
 * started at all. A failure to parallelise must never be a failure to answer.
 */
class ParallelSectionRunner
{
    /**
     * @param string[] $sources database keys to compute
     * @return array<string,array{ok:bool,payload?:array,error?:string}>|null
     *         null when the runner declined and the caller should go serial
     */
    public function run(array $sources, string $section, array $params): ?array
    {
        if (!$this->shouldRun($sources)) {
            return null;
        }

        $binary = $this->phpBinary();
        $artisan = base_path('artisan');

        if ($binary === null || !is_file($artisan)) {
            Log::warning('Parallel fan-out unavailable; falling back to serial', [
                'php_binary' => $binary,
                'artisan' => $artisan,
            ]);

            return null;
        }

        $payload = json_encode(['params' => $params], JSON_THROW_ON_ERROR);
        $limit = max(1, (int) config('reporting.parallel.max_processes', 6));
        $timeout = max(5, (int) config('reporting.parallel.timeout', 60));

        $queue = array_values($sources);
        $running = [];
        $results = [];
        $started = false;

        while ($queue !== [] || $running !== []) {
            // Fill the slots.
            while ($queue !== [] && count($running) < $limit) {
                $key = array_shift($queue);

                try {
                    $process = new Process(
                        [$binary, $artisan, 'reporting:section', $key, $section],
                        base_path(),
                        // Children must not inherit a request-scoped environment
                        // that could point them at a different config cache.
                        null,
                        // Params go over stdin rather than argv: they can contain
                        // a search string, and argv has both escaping pitfalls and
                        // a length limit on Windows.
                        $payload,
                        $timeout
                    );

                    $process->start();
                    $running[$key] = $process;
                    $started = true;
                } catch (ProcessException $e) {
                    // proc_open blocked, or the binary is not executable. If not
                    // one child has started yet, decline entirely so the caller
                    // runs the whole set serially rather than one at a time here.
                    if (!$started) {
                        Log::warning('Parallel fan-out could not start; falling back to serial', [
                            'error' => $e->getMessage(),
                        ]);

                        foreach ($running as $process) {
                            $process->stop(0);
                        }

                        return null;
                    }

                    $results[$key] = ['ok' => false, 'error' => $e->getMessage()];
                }
            }

            // Collect whatever has finished. usleep keeps this from spinning a
            // core while waiting on the network.
            $progressed = false;

            foreach ($running as $key => $process) {
                if ($process->isRunning()) {
                    continue;
                }

                unset($running[$key]);
                $results[$key] = $this->readResult($key, $section, $process);
                $progressed = true;
            }

            if (!$progressed && $running !== []) {
                usleep(20_000);
            }
        }

        return $results;
    }

    /**
     * Whether the fan-out is worth doing at all.
     */
    private function shouldRun(array $sources): bool
    {
        if (!config('reporting.parallel.enabled', true)) {
            return false;
        }

        // Shared hosting frequently disables this. Checked rather than assumed,
        // because Symfony throws on start() and by then a slot is committed.
        if (!function_exists('proc_open')) {
            return false;
        }

        $minimum = max(2, (int) config('reporting.parallel.min_sources', 2));

        return count($sources) >= $minimum;
    }

    /**
     * The CLI binary to run children with.
     *
     * PHP_BINARY is the process running us, which under php-fpm is the FPM
     * binary — usable for CLI in practice, but a deployment where it is not can
     * set the path explicitly.
     */
    private function phpBinary(): ?string
    {
        $configured = config('reporting.parallel.php_binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return PHP_BINARY !== '' ? PHP_BINARY : null;
    }

    /**
     * Decodes one child's output.
     *
     * A child reports failure as JSON on stdout with a non-zero exit. Anything
     * else on stdout — a PHP warning, an HTML error page, a deprecation notice —
     * means the child broke in a way it could not report, and that is surfaced
     * as a failed database rather than silently becoming an empty payload.
     */
    private function readResult(string $key, string $section, Process $process): array
    {
        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        $decoded = json_decode($stdout, true);

        if (is_array($decoded) && array_key_exists('ok', $decoded)) {
            if ($decoded['ok'] && isset($decoded['payload']) && is_array($decoded['payload'])) {
                return ['ok' => true, 'payload' => $decoded['payload']];
            }

            return [
                'ok' => false,
                'error' => (string) ($decoded['error'] ?? 'The child process reported no payload.'),
            ];
        }

        Log::warning('Parallel section child returned unusable output', [
            'section' => $section,
            'source' => $key,
            'exit_code' => $process->getExitCode(),
            // Truncated: a stack trace or an HTML error page would otherwise
            // dominate the log line.
            'stdout' => mb_substr($stdout, 0, 500),
            'stderr' => mb_substr($stderr, 0, 500),
        ]);

        return [
            'ok' => false,
            'error' => config('app.debug')
                ? ($stderr !== '' ? $stderr : 'Child process produced no usable output.')
                : 'This database could not be reached.',
        ];
    }
}
