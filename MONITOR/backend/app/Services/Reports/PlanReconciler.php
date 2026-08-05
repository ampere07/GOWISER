<?php

namespace App\Services\Reports;

/**
 * Matches legacy subscriber plan strings onto the canonical `plan_list` records.
 *
 * The migration into SYNC left two populations behind. Most accounts carry a
 * real `billing_accounts.plan_id` and join cleanly. A tail does not: the id is
 * null, or points at a plan row that has since been renamed or deleted, and the
 * only surviving description of what they are on is the free-text string the
 * customer record kept — "PLAN 1500", "1500/50mbps", "Fiber 50 Mbps".
 *
 * Dropping that tail is what made plan distribution untrustworthy: the pie added
 * to 100% of a base that was quietly smaller than the subscriber count beside
 * it. So every account is placed, and the ones that genuinely cannot be
 * identified are grouped rather than discarded.
 *
 * ── Why matching is layered, and why it refuses ties ──────────────────
 *
 * Four passes, each stricter than a human eyeballing the list would be:
 *
 *   1. exact       the normalised names are identical
 *   2. compact     identical once every non-alphanumeric character is dropped,
 *                  so "Plan-1500" reaches "Plan 1500"
 *   3. speed       the same Mbps figure, when exactly one canonical plan has it
 *   4. price       the same bare number, when exactly one canonical plan has it
 *
 * Passes 3 and 4 only fire when the signature belongs to exactly *one* canonical
 * plan. Two plans at 50 Mbps make "50mbps" ambiguous, and quietly assigning a
 * subscriber to whichever sorted first would move revenue between plans on a
 * chart management reads as fact. An ambiguous string is left unmapped, which is
 * visible and correctable; a confident wrong answer is neither.
 *
 * Nothing here writes to the source database — this is a read-side
 * reconciliation for reporting only, and the migration itself is not this
 * portal's to redo.
 */
class PlanReconciler
{
    /** The bucket unmatched strings fall into, rather than vanishing. */
    public const UNMAPPED_LABEL = 'Unmapped / Legacy Plan';

    /** @var array<int,array{id:int,label:string,price:float}> keyed by plan id */
    private array $plans = [];

    /** @var array<string,int> normalised name => plan id */
    private array $byExact = [];

    /** @var array<string,int> alphanumeric-only name => plan id */
    private array $byCompact = [];

    /** @var array<string,int[]> "50mbps" => plan ids carrying that speed */
    private array $bySpeed = [];

    /** @var array<string,int[]> "1500" => plan ids carrying that number */
    private array $byNumber = [];

    /**
     * Builds the index from the canonical rows.
     *
     * @param iterable $canonical rows with id, plan_name and price
     */
    public static function fromCanonical(iterable $canonical): self
    {
        $reconciler = new self();

        foreach ($canonical as $plan) {
            $id = (int) ($plan->id ?? 0);
            $name = (string) ($plan->plan_name ?? '');

            if ($id <= 0 || trim($name) === '') {
                continue;
            }

            $reconciler->plans[$id] = [
                'id' => $id,
                'label' => $name,
                'price' => round((float) ($plan->price ?? 0), 2),
            ];

            $exact = self::normalise($name);
            $compact = self::compact($name);

            // First writer wins on the unique indexes. `plan_list.plan_name` is
            // unique in the schema, so a collision here means two names that
            // differ only in punctuation or case — in which case either is as
            // good an answer as the other, and stability beats arbitrating.
            $reconciler->byExact[$exact] ??= $id;
            $reconciler->byCompact[$compact] ??= $id;

            foreach (self::speeds($name) as $speed) {
                $reconciler->bySpeed[$speed][] = $id;
            }

            foreach (self::numbers($name) as $number) {
                $reconciler->byNumber[$number][] = $id;
            }

            // The plan's own price, not only digits that happen to appear in its
            // name. Without this a plan called "PLAN A" priced at 1500 cannot be
            // reached from the string "PLAN A - 1500", which is how the old
            // application form rendered its labels and therefore how a large
            // part of the legacy tail is spelled.
            //
            // Floored at 100: below that a price collides with speeds, tier
            // numbers and contract lengths, and matching a subscriber on a
            // two-digit number is how a ₱50 promo swallows a 50 Mbps plan.
            //
            // Kept identical to GOWISER's App\Services\PlanIdentity. The two
            // systems report the same plans to the same people, and a matcher
            // that is cleverer on one side than the other puts two different
            // subscriber counts on two screens with no way to tell which is
            // right.
            $price = $reconciler->plans[$id]['price'];

            if ($price >= 100) {
                $reconciler->byNumber[(string) (int) round($price)][] = $id;

                if (abs($price - round($price)) > 0.001) {
                    $reconciler->byNumber[number_format($price, 2, '.', '')][] = $id;
                }
            }
        }

        return $reconciler;
    }

    /** @return array<int,array{id:int,label:string,price:float}> */
    public function canonical(): array
    {
        return $this->plans;
    }

    public function plan(int $id): ?array
    {
        return $this->plans[$id] ?? null;
    }

    /**
     * The canonical plan id a legacy string refers to, or null when nothing
     * claims it unambiguously.
     */
    public function match(?string $raw): ?int
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $exact = self::normalise($value);

        if (isset($this->byExact[$exact])) {
            return $this->byExact[$exact];
        }

        $compact = self::compact($value);

        if ($compact !== '' && isset($this->byCompact[$compact])) {
            return $this->byCompact[$compact];
        }

        // Signature passes, unambiguous only. See the class comment.
        foreach (self::speeds($value) as $speed) {
            $owners = array_unique($this->bySpeed[$speed] ?? []);

            if (count($owners) === 1) {
                return (int) reset($owners);
            }
        }

        foreach (self::numbers($value) as $number) {
            $owners = array_unique($this->byNumber[$number] ?? []);

            if (count($owners) === 1) {
                return (int) reset($owners);
            }
        }

        return null;
    }

    /**
     * Lower-cased, punctuation-flattened, whitespace-collapsed.
     *
     * Separators become spaces rather than being deleted, so "1500/50mbps"
     * yields the tokens "1500" and "50mbps" instead of one run-on word.
     */
    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['₱', 'php', 'pesos', 'peso'], ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** Alphanumeric only, so spacing and punctuation cannot separate a match. */
    public static function compact(string $value): string
    {
        return str_replace(' ', '', self::normalise($value));
    }

    /**
     * Every bandwidth figure in a name, as "<n>mbps".
     *
     * Handles the spellings the migration left behind — "50Mbps", "50 MBPS",
     * "50mb", "50 M" — and normalises Gbps to its Mbps equivalent so a plan
     * recorded both ways still matches itself.
     *
     * @return string[]
     */
    public static function speeds(string $value): array
    {
        $normalised = self::normalise($value);
        $found = [];

        if (preg_match_all('/(\d+(?:\s*\.\s*\d+)?)\s*(gbps|gb|g|mbps|mb|m)\b/', $normalised, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $amount = (float) str_replace(' ', '', $match[1]);
                $unit = $match[2];

                if ($amount <= 0) {
                    continue;
                }

                if (in_array($unit, ['gbps', 'gb', 'g'], true)) {
                    $amount *= 1000;
                }

                // Formatted without decimals: 50.0 and 50 are the same speed and
                // must produce the same key.
                $found[] = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . 'mbps';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Bare numbers in a name, used as a last-resort price signature.
     *
     * Numbers that carry a bandwidth unit are excluded — those were already
     * tried as speeds, and re-testing "50" from "50Mbps" as a price is how a
     * 50 Mbps plan gets matched to a ₱50 one.
     *
     * @return string[]
     */
    public static function numbers(string $value): array
    {
        $normalised = self::normalise($value);
        $speedTokens = [];

        if (preg_match_all('/(\d+(?:\s*\.\s*\d+)?)\s*(?:gbps|gb|g|mbps|mb|m)\b/', $normalised, $matches)) {
            $speedTokens = array_map(fn ($n) => str_replace(' ', '', $n), $matches[1]);
        }

        $found = [];

        if (preg_match_all('/\b(\d+)\b/', $normalised, $matches)) {
            foreach ($matches[1] as $number) {
                // Two- and one-digit numbers are almost always a speed, a tier
                // index or a contract length rather than a price, and are far too
                // collision-prone to reassign a subscriber on.
                if (in_array($number, $speedTokens, true) || strlen($number) < 3) {
                    continue;
                }

                $found[] = $number;
            }
        }

        return array_values(array_unique($found));
    }
}
