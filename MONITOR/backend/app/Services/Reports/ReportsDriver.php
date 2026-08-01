<?php

namespace App\Services\Reports;

use Illuminate\Database\ConnectionInterface;

/**
 * One implementation per monitored schema for the five reporting sections.
 *
 * Kept separate from App\Services\Metrics\MetricsDriver on purpose. That
 * interface is the executive rollup every source must answer; this one is the
 * far larger operational surface, and no schema serves all of it:
 *
 *              subscribers  financial  operations  tech  employee
 *   NETMANAGER      yes        yes         yes      no      yes
 *   GOWISER         yes        no          yes      yes     yes
 *
 * NETMANAGER has no technician records at all. GOWISER records no expenses, so
 * it cannot state net or margin — the same reason GowiserDriver omits
 * 'financials' rather than returning zeros and implying the business broke even.
 *
 * capabilities() is what the frontend uses to hide a section, so a driver
 * declaring a section it cannot really answer is worse than omitting it.
 */
interface ReportsDriver
{
    /**
     * Who the subscribers are: status pipeline, expiry runway, plan mix,
     * geography, and the overdue ledger.
     *
     * @param array{
     *     branch?:int|string|null, as_of?:string|null,
     *     geo_region?:string, geo_province?:string, geo_municipality?:string,
     *     overdue_search?:string, overdue_plan_id?:int,
     *     overdue_bucket?:string, overdue_page?:int
     * } $params
     */
    public function subscriberAnalytics(ConnectionInterface $db, array $params): array;

    /**
     * Money in, money out, and what is behind both.
     *
     * @param array{
     *     period?:string, date_from?:string|null, date_to?:string|null,
     *     branch?:int|string|null, branch_period?:string, branch_year?:int,
     *     as_of?:string|null
     * } $params
     */
    public function financial(ConnectionInterface $db, array $params): array;

    /**
     * Field delivery: the installation and service pipeline, its backlog, and
     * how long work takes to close.
     *
     * @param array{
     *     date_from?:string|null, date_to?:string|null,
     *     branch?:int|string|null, as_of?:string|null
     * } $params
     */
    public function operations(ConnectionInterface $db, array $params): array;

    /**
     * The technician roster and its workload — jobs closed, visits made,
     * turnaround, and last known field position.
     *
     * @param array{
     *     date_from?:string|null, date_to?:string|null, as_of?:string|null
     * } $params
     */
    public function tech(ConnectionInterface $db, array $params): array;

    /**
     * Staff and what they produced: collections per cashier, work per field
     * user, and the payee ledger.
     *
     * @param array{
     *     date_from?:string|null, date_to?:string|null,
     *     branch?:int|string|null, as_of?:string|null
     * } $params
     */
    public function employee(ConnectionInterface $db, array $params): array;

    /**
     * Line-level data behind the printable reports: every payment and every
     * expense in the range, plus totals and the company header.
     */
    public function printable(ConnectionInterface $db, string $from, string $to, $branch = null): array;

    /** Selectable branches, [] when the schema has no branch concept. */
    public function branches(ConnectionInterface $db): array;

    /**
     * Which sections this schema can actually answer.
     *
     * @return string[] subset of: subscriber_analytics, financial, operations, tech, employee
     */
    public function capabilities(): array;
}
