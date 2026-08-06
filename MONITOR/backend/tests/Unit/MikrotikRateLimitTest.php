<?php

namespace Tests\Unit;

use App\Services\Mikrotik\UserManagerClient;
use App\Services\MikrotikRadiusService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The rate-limit parser, and the GMT+8 clock beside it.
 *
 * These two are worth tests more than anything else in the module, for the same
 * reason: both take something a person typed and turn it into an instruction to
 * live infrastructure, and both fail *silently* when they get it wrong. A
 * mis-parsed rate limit does not raise anything — RouterOS accepts it and a
 * region runs at the wrong speed until somebody complains. A mis-parsed time
 * does not raise anything either; the disconnection simply happens at the wrong
 * hour.
 */
class MikrotikRateLimitTest extends TestCase
{
    private MikrotikRadiusService $radius;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radius = new MikrotikRadiusService(new UserManagerClient());
    }

    /**
     * @dataProvider rates
     */
    public function test_it_normalises_what_operators_type(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->radius->parseRateLimit($input)['value']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public function rates(): array
    {
        return [
            // One figure is symmetric, which is what a fibre operator means.
            'bare unit' => ['250mb', '250M/250M'],
            'asymmetric' => ['250mb/50mb', '250M/50M'],
            'already routeros' => ['250M/250M', '250M/250M'],

            // Case and spacing are how people actually write these, off a
            // datasheet or out of a ticket.
            'mixed case' => ['250MB/50Mb', '250M/50M'],
            'spaced' => ['250 mb / 50 mb', '250M/50M'],
            'mbps' => ['250Mbps/50Mbps', '250M/50M'],
            'mbit' => ['250mbit/50mbit', '250M/50M'],
            // The slash spells two different things and both appear together in
            // the wild. Splitting naively reads "250mb/s" as an upload rate of
            // "s" — see parseRateLimit.
            'per second' => ['250mb/s', '250M/250M'],
            'per second both ways' => ['250mbit/s / 50mbit/s', '250M/50M'],

            // Kilobits stay kilobits; a restricted tier is a real configuration.
            'kilobits' => ['512kb/256kb', '512k/256k'],
            'kb shorthand' => ['512k', '512k/512k'],

            // Gigabits, and the reason decimals cannot be truncated: 1.5G is not
            // 1G, and RouterOS accepts no fraction — so it has to become 1500M.
            'whole gigabit' => ['1gb', '1G/1G'],
            'fractional gigabit' => ['1.5gb/512kb', '1500M/512k'],
            'fractional megabit' => ['1.5mb', '1500k/1500k'],

            // Exact rendering, not the prettiest: 1000M is 1G and is written as
            // such, but 1024M divides into no whole gigabit and stays in M.
            'promotes to gigabit' => ['1000mb', '1G/1G'],
            'stays in megabits' => ['1024mb', '1024M/1024M'],
        ];
    }

    /**
     * A bare number is the one input that cannot be guessed at.
     *
     * RouterOS reads it as bits per second, so "250" is a modem from 1962 — and
     * the operator meant 250 Mbps. Refusing beats guessing: guessing wrong here
     * throttles a region, and the message names the fix.
     */
    public function test_it_refuses_a_number_with_no_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has no unit/');

        $this->radius->parseRateLimit('250');
    }

    public function test_it_refuses_input_it_cannot_read(): void
    {
        foreach (['', 'fast', '250tb', '250mb/50mb/10mb', '250mb/'] as $input) {
            try {
                $this->radius->parseRateLimit($input);
                $this->fail("Expected \"{$input}\" to be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * Sub-megabit rates warn rather than fail.
     *
     * A walled-garden or restricted tier genuinely is a few hundred kilobits, so
     * this cannot be an error — but it is also exactly what a missing unit looks
     * like, and the two are indistinguishable from the input alone.
     */
    public function test_it_warns_about_an_implausibly_low_rate(): void
    {
        $low = $this->radius->parseRateLimit('512kb');

        $this->assertSame('512k/512k', $low['value']);
        $this->assertNotNull($low['warning']);

        $normal = $this->radius->parseRateLimit('250mb');

        $this->assertNull($normal['warning']);
    }

    public function test_it_reports_the_rate_in_bits_per_second(): void
    {
        $parsed = $this->radius->parseRateLimit('250mb/50mb');

        // Decimal, not binary: RouterOS reads M as 1000000 on a rate limit, and
        // 1024-based arithmetic here would be quietly wrong everywhere.
        $this->assertSame(250_000_000, $parsed['rx_bps']);
        $this->assertSame(50_000_000, $parsed['tx_bps']);
    }

    public function test_it_rejects_an_absurd_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/extra digit/');

        $this->radius->parseRateLimit('9999gb');
    }

    // ── GMT+8 scheduling ──────────────────────────────────────────────

    /**
     * A named time is read in Manila, whatever the server runs in.
     *
     * The assertion travels through UTC deliberately: it is the only way to show
     * that the input was interpreted in Asia/Manila rather than merely stored as
     * the same digits. 14:00 in Manila is 06:00 UTC, and a parser that ignored
     * the zone would produce 14:00 UTC and pass a naive string comparison.
     */
    public function test_it_reads_a_scheduled_time_as_manila_time(): void
    {
        $target = Carbon::now(MikrotikRadiusService::TIMEZONE)->addDay()->format('Y-m-d') . ' 14:00';

        $when = $this->radius->parseManilaTime($target);

        $this->assertSame(
            '14:00',
            $when->copy()->setTimezone(MikrotikRadiusService::TIMEZONE)->format('H:i')
        );
        $this->assertSame('06:00', $when->copy()->utc()->format('H:i'));
    }

    /**
     * The past is refused rather than fired immediately.
     *
     * "Schedule for twenty minutes ago" has no sane reading: the runner would
     * pick it up on its next tick, which is indistinguishable from pressing
     * Disconnect Now — and that button is right there and says what it does.
     */
    public function test_it_refuses_a_time_in_the_past(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already passed/');

        $this->radius->parseManilaTime(
            Carbon::now(MikrotikRadiusService::TIMEZONE)->subHour()->format('Y-m-d H:i')
        );
    }

    public function test_it_refuses_a_time_more_than_a_year_out(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than a year/');

        $this->radius->parseManilaTime(
            Carbon::now(MikrotikRadiusService::TIMEZONE)->addYears(2)->format('Y-m-d H:i')
        );
    }

    public function test_it_refuses_a_time_it_cannot_read(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->radius->parseManilaTime('next tuesday-ish');
    }
}
