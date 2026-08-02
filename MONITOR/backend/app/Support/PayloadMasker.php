<?php

namespace App\Support;

/**
 * Strips or masks the parts of a section payload a role may not see.
 *
 * Widget permissions are enforced here, on the way out of the controller, and
 * not only in the frontend. Hiding a panel in React hides the panel; it does not
 * stop the numbers being in the JSON the browser already received, and "the
 * revenue was masked" is not a claim anyone can make about a payload that
 * contained it.
 *
 * Two different treatments, because two different things are being protected:
 *
 *   REMOVE  a whole block the role has no business receiving — the payables
 *           ledger, the executive metrics. The key is absent, and the frontend
 *           renders nothing rather than an empty panel.
 *
 *   MASK    a figure that must stay structurally present because the layout and
 *           the counts around it are still legitimate. Replaced with null, which
 *           the UI already renders as an em dash, plus a `masked` marker so it
 *           can say "restricted" instead of "no data" — those mean different
 *           things and conflating them is how a masked page reads as a broken one.
 */
class PayloadMasker
{
    /**
     * Money fields inside `kpi` that a role without the revenue widget must not
     * receive. Counts are left alone: how many payments were taken is an
     * operational figure, how much they were worth is not.
     */
    private const REVENUE_FIELDS = [
        'income', 'average_payment', 'largest_payment',
        'office_income', 'portal_income',
        'expenses', 'net', 'margin_pct', 'expected_mrc', 'collection_rate',
    ];

    /**
     * Applies every widget rule for one section.
     *
     * @param string[] $abilities the caller's effective permission list
     */
    public static function apply(string $section, array $payload, array $abilities): array
    {
        switch ($section) {
            case 'financial':
                return self::financial($payload, $abilities);
            case 'subscriber_analytics':
                return self::subscribers($payload, $abilities);
            case 'operations':
                return self::operations($payload, $abilities);
            default:
                return $payload;
        }
    }

    private static function financial(array $payload, array $abilities): array
    {
        $masked = [];

        if (!self::allows($abilities, Permissions::WIDGET_FINANCIAL_REVENUE)) {
            $payload['kpi'] = self::blank($payload['kpi'] ?? [], self::REVENUE_FIELDS);

            // Every series and breakdown is a restatement of the same figures, so
            // masking the headline while leaving these would be theatre.
            foreach (['series', 'by_plan', 'by_method', 'by_expense_type', 'payment_notes'] as $block) {
                $payload[$block] = [];
            }

            $payload['trend'] = ['period' => $payload['trend']['period'] ?? 'monthly', 'points' => []];
            $payload['periods'] = [];
            $payload['by_branch'] = array_merge(
                $payload['by_branch'] ?? [],
                ['rows' => []]
            );

            $masked[] = Permissions::WIDGET_FINANCIAL_REVENUE;
        }

        foreach ([
            'income_channels' => Permissions::WIDGET_FINANCIAL_CHANNELS,
            'executive_metrics' => Permissions::WIDGET_FINANCIAL_METRICS,
            'opex_capex' => Permissions::WIDGET_FINANCIAL_OPEX,
            'payables' => Permissions::WIDGET_FINANCIAL_PAYABLES,
        ] as $block => $permission) {
            if (!self::allows($abilities, $permission)) {
                unset($payload[$block]);
                $masked[] = $permission;
            }
        }

        return self::mark($payload, $masked);
    }

    private static function subscribers(array $payload, array $abilities): array
    {
        $masked = [];

        if (!self::allows($abilities, Permissions::WIDGET_SUBSCRIBER_BILLING)) {
            unset($payload['billing_summary']);
            $masked[] = Permissions::WIDGET_SUBSCRIBER_BILLING;
        }

        if (!self::allows($abilities, Permissions::WIDGET_SUBSCRIBER_BARANGAY)) {
            $payload['barangays'] = [];
            $masked[] = Permissions::WIDGET_SUBSCRIBER_BARANGAY;
        }

        // Expected MRC is a revenue figure that happens to live on a counting
        // page, so it follows the revenue permission rather than this module's.
        if (!self::allows($abilities, Permissions::WIDGET_FINANCIAL_REVENUE)) {
            if (isset($payload['growth'])) {
                $payload['growth']['expected_mrc'] = null;
            }

            $masked[] = Permissions::WIDGET_FINANCIAL_REVENUE;
        }

        return self::mark($payload, $masked);
    }

    private static function operations(array $payload, array $abilities): array
    {
        if (self::allows($abilities, Permissions::WIDGET_OPERATIONS_SLA)) {
            return $payload;
        }

        unset($payload['turnaround'], $payload['turnaround_by_type']);

        return self::mark($payload, [Permissions::WIDGET_OPERATIONS_SLA]);
    }

    private static function allows(array $abilities, string $permission): bool
    {
        return in_array($permission, $abilities, true);
    }

    /** Nulls the listed fields, leaving the rest of the block intact. */
    private static function blank(array $block, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $block)) {
                $block[$field] = null;
            }
        }

        return $block;
    }

    /**
     * Records which widgets were withheld.
     *
     * The list is on the payload rather than inferred from missing keys so the
     * UI can distinguish "you are not permitted to see this" from "this system
     * does not record it" — the existing `supports_*` flags mean the second, and
     * showing the wrong message sends someone to fix a database.
     */
    private static function mark(array $payload, array $masked): array
    {
        if ($masked !== []) {
            $payload['masked'] = array_values(array_unique($masked));
        }

        return $payload;
    }
}
