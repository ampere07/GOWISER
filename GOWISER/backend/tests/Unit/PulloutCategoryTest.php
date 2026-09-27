<?php

namespace Tests\Unit;

use App\Support\PulloutCategory;
use PHPUnit\Framework\TestCase;

/**
 * When a service order counts as a completed pullout.
 *
 * The rule lives in App\Support\PulloutCategory and is called here directly.
 * Both service order controllers are also read to confirm they still ask
 * PulloutCategory, against the saved row, rather than growing their own copy.
 *
 * No database: nothing is written, read or migrated.
 */
class PulloutCategoryTest extends TestCase
{
    private const CONTROLLERS = [
        'api' => [__DIR__ . '/../../app/Http/Controllers/Api/ServiceOrderApiController.php'],
        'web' => [__DIR__ . '/../../app/Http/Controllers/ServiceOrderController.php'],
    ];

    /** @dataProvider pulloutSpellings */
    public function test_any_spelling_of_pullout_category_fires_on_done(string $spelling): void
    {
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin($spelling, 'Done'));
    }

    /** @dataProvider pulloutSpellings */
    public function test_a_pullout_concern_fires_on_done_without_a_category(string $spelling): void
    {
        // Auto-generated tickets: concern 'for pullout', no repair category.
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin(null, 'Done', $spelling));
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin('', ' done ', $spelling));
    }

    public static function pulloutSpellings(): array
    {
        return [
            ['Pullout'], ['pullout'], ['PULLOUT'], ['Pull Out'],
            ['for pullout'], ['For Pullout'], ['FOR PULL OUT'], ['  Pullout  '],
        ];
    }

    /** @dataProvider notDone */
    public function test_a_pullout_that_is_not_done_does_not_fire(?string $visitStatus): void
    {
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('Pullout', $visitStatus));
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin(null, $visitStatus, 'for pullout'));
    }

    public static function notDone(): array
    {
        return [[null], [''], ['In Progress'], ['Failed'], ['Reschedule'], ['For Visit']];
    }

    public function test_other_categories_and_concerns_do_not_fire(): void
    {
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('Reboot/Reconfig Router', 'Done'));
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('', 'Done'));
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin(null, 'Done', 'Reconnect'));
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('Relocate', 'Done', 'No Internet'));
    }

    /** @dataProvider controllers */
    public function test_controllers_decide_on_the_saved_row_via_pullout_category(string $path): void
    {
        $src = file_get_contents($path);

        $this->assertStringContainsString('$isAlreadyPulloutDone = \App\Support\PulloutCategory::deactivatesPortalLogin(', $src);
        $this->assertStringContainsString('$isPulloutVisitDone = \App\Support\PulloutCategory::deactivatesPortalLogin(', $src);
        $this->assertStringContainsString("\$pulloutRow = DB::table('service_orders')->where('id', \$id)->first();", $src);
        $this->assertStringContainsString('if ($isPulloutVisitDone && !$isAlreadyPulloutDone)', $src);
        $this->assertStringNotContainsString('$pulloutCategories', $src, 'a private copy of the pullout rule is back');
    }

    public static function controllers(): array
    {
        return self::CONTROLLERS;
    }
}
