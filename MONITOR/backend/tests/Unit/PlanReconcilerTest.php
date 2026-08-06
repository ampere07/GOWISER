<?php

namespace Tests\Unit;

use App\Services\Reports\PlanReconciler;
use Tests\TestCase;

/**
 * Matching legacy subscriber plan strings onto the canonical plan list.
 *
 * Worth tests because every failure here is silent and directional: an
 * unmatched account does not raise anything, it quietly leaves the Subscriber
 * Plan cards adding to less than the Active counter three rows above them. The
 * containment pass — the fix — is equally silent in the other direction if it
 * over-matches, so both halves are pinned here.
 */
class PlanReconcilerTest extends TestCase
{
    private function reconciler(array $plans): PlanReconciler
    {
        return PlanReconciler::fromCanonical(array_map(
            fn (array $plan) => (object) $plan,
            $plans
        ));
    }

    private function canonical(): PlanReconciler
    {
        return $this->reconciler([
            ['id' => 1, 'plan_name' => 'Plan A', 'price' => 1500],
            ['id' => 2, 'plan_name' => 'Plan B', 'price' => 1800],
            ['id' => 3, 'plan_name' => 'Premium Plan', 'price' => 3500],
            ['id' => 4, 'plan_name' => 'VIP FREE', 'price' => 0],
        ]);
    }

    public function test_it_matches_an_exact_name(): void
    {
        $this->assertSame(1, $this->canonical()->match('Plan A'));
    }

    public function test_it_matches_through_punctuation_and_case(): void
    {
        $this->assertSame(1, $this->canonical()->match('PLAN-A'));
        $this->assertSame(3, $this->canonical()->match('premium  plan'));
    }

    /**
     * The fix. Most legacy strings are the canonical name with something stuck
     * to it, and none of them reached a match before this pass existed.
     *
     * @dataProvider contained
     */
    public function test_it_matches_a_name_contained_in_the_subscriber_string(
        string $raw,
        int $expected
    ): void {
        $this->assertSame($expected, $this->canonical()->match($raw));
    }

    /** @return array<string,array{0:string,1:int}> */
    public function contained(): array
    {
        return [
            'trailing word' => ['Plan A Fiber', 1],
            'leading brand' => ['SwitchNet Plan B', 2],
            'both sides' => ['SwitchNet Premium Plan 2024', 3],
            'parenthesised note' => ['Plan A (promo)', 1],
            'em dash and price' => ['Plan B — 1800', 2],
        ];
    }

    /**
     * Containment is on whole tokens, not substrings.
     *
     * "Plan A" is inside "Plan Alpha" as raw text, and a LIKE '%plan a%' — the
     * obvious implementation — would move every Alpha subscriber onto Plan A.
     * That is the confident wrong answer this class exists to refuse.
     */
    public function test_it_does_not_match_across_a_word_boundary(): void
    {
        $reconciler = $this->reconciler([
            ['id' => 1, 'plan_name' => 'Plan A', 'price' => 1500],
        ]);

        $this->assertNull($reconciler->match('Plan Alpha'));
        $this->assertNull($reconciler->match('Planet Broadband'));
    }

    /**
     * The most specific containment wins.
     *
     * With both "Plan A" and "Plan A Plus" on the list, a subscriber on "Plan A
     * Plus Fiber" belongs to the second — the longer name is always the better
     * answer, and taking the shorter one would understate the premium tier.
     */
    public function test_the_longest_contained_name_wins(): void
    {
        $reconciler = $this->reconciler([
            ['id' => 1, 'plan_name' => 'Plan A', 'price' => 1500],
            ['id' => 2, 'plan_name' => 'Plan A Plus', 'price' => 2500],
        ]);

        $this->assertSame(2, $reconciler->match('Plan A Plus Fiber'));
        $this->assertSame(1, $reconciler->match('Plan A Fiber'));
    }

    /**
     * A genuine tie refuses rather than picking one.
     *
     * Two different plans equally contained in the string is real ambiguity, and
     * an account left visibly unmapped is correctable where a silently misfiled
     * one is not.
     */
    public function test_an_ambiguous_containment_returns_null(): void
    {
        $reconciler = $this->reconciler([
            ['id' => 1, 'plan_name' => 'Fiber 50', 'price' => 1500],
            ['id' => 2, 'plan_name' => 'Fiber 99', 'price' => 1500],
        ]);

        $this->assertNull($reconciler->match('Fiber 50 Fiber 99 bundle'));
    }

    /**
     * The reverse direction too: the subscriber string inside the canonical name.
     *
     * Accounts recorded as bare "VIP FREE" against a canonical "VIP FREE" match
     * exactly; this covers the case where the canonical name is the longer one.
     */
    public function test_it_matches_when_the_canonical_name_is_the_longer_side(): void
    {
        $reconciler = $this->reconciler([
            ['id' => 1, 'plan_name' => 'Plan A 1500', 'price' => 1500],
        ]);

        $this->assertSame(1, $reconciler->match('Plan A'));
    }

    /** A single-character plan name matches almost anything and is skipped. */
    public function test_it_ignores_a_one_character_plan_name(): void
    {
        $reconciler = $this->reconciler([
            ['id' => 1, 'plan_name' => 'A', 'price' => 1500],
        ]);

        $this->assertNull($reconciler->match('Fiber A Plus Broadband'));
    }

    public function test_it_returns_null_for_nothing_recognisable(): void
    {
        $this->assertNull($this->canonical()->match(''));
        $this->assertNull($this->canonical()->match('   '));
        $this->assertNull($this->canonical()->match('zzz unknown tier'));
    }
}
