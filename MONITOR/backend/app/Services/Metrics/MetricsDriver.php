<?php

namespace App\Services\Metrics;

use Illuminate\Database\ConnectionInterface;

/**
 * One implementation per monitored *schema*, not per database.
 *
 * Two ISP systems can both be watched, but GOWISER stores money in
 * `transactions`/`billing_accounts` while NETMANAGER stores it in
 * `payments`/`expenses`. Rather than a controller full of if-statements, each
 * schema answers the same questions its own way, and says which questions it
 * can answer at all.
 */
interface MetricsDriver
{
    /** Headline KPIs: accounts, sessions, receivables, collections. */
    public function overview(ConnectionInterface $db): array;

    /** Field and support activity. */
    public function operations(ConnectionInterface $db): array;

    /** Collections trend over $months. */
    public function revenue(ConnectionInterface $db, int $months): array;

    /**
     * Full income/expense/net analytics for one period and optional branch.
     *
     * @param string $period daily|weekly|monthly|yearly
     * @param string|int|null $branch driver-specific branch id, null for all
     * @param string|null $asOf Y-m-d the period is measured from; null = today
     */
    public function financials(ConnectionInterface $db, string $period, $branch = null, ?string $asOf = null): array;

    /** Selectable branches, [] when the schema has no branch concept. */
    public function branches(ConnectionInterface $db): array;

    /**
     * Which of the above return real data for this schema.
     *
     * The frontend hides navigation for anything absent, so a source without
     * expense tracking never shows an empty Financials page.
     *
     * @return string[] subset of: overview, operations, revenue, financials
     */
    public function capabilities(): array;
}
