<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MikrotikKick;
use App\Services\Mikrotik\UserManagerClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The MikroTik RADIUS engine: rate limits, group membership and session control.
 *
 * UserManagerClient is the transport — it knows how to reach a RouterOS box and
 * nothing about why. This is the layer above it that knows why: what a rate
 * limit means, which sessions a change strands on the old settings, and what
 * "re-authorise at 2pm Manila time" has to become in the database for that to
 * actually happen.
 *
 * ── The trap this whole module is built around ────────────────────────
 *
 * Changing a group's rate limit does nothing to anyone already online. RADIUS
 * hands its attributes out at authentication, so a subscriber keeps the speed
 * they connected with until their session ends — which for a fibre customer can
 * be weeks. An operator who changes a limit and walks away has, in practice,
 * changed nothing.
 *
 * So every write here reports its blast radius: how many sessions are still
 * running on the previous settings, and therefore whether to disconnect them. A
 * count is the difference between a decision and a gamble, and "apply to 340
 * live sessions" is a sentence an operator can actually answer.
 *
 * ── Why the router work is not inside a database transaction ──────────
 *
 * It cannot be. A PATCH to a router is not a statement this database can roll
 * back, and wrapping one in DB::transaction buys nothing but a longer-held lock
 * and the illusion of atomicity. What is transactional is MONITOR's own state:
 * the queue row and its audit entry are written together or not at all, so the
 * trail can never record an instruction that was never queued, or queue one that
 * nobody is recorded as having ordered.
 */
class MikrotikRadiusService
{
    /**
     * Bits per second per unit.
     *
     * Decimal, not binary. RouterOS reads `k`, `M` and `G` on a rate limit as
     * 1000, 1000000 and 1000000000 — a 1G queue passes a gigabit, not a gibibit
     * — and using 1024 here would render every converted value slightly wrong in
     * a way nobody would notice until a capacity plan did not add up.
     */
    private const UNITS = ['k' => 1000, 'm' => 1000000, 'g' => 1000000000];

    /** Below this, a link is unusable; almost always a missing or wrong unit. */
    private const SUSPICIOUS_BPS = 1000000;

    /** Absolute ceiling, as a guard against a fat-fingered extra digit. */
    private const MAX_BPS = 1000000000000;

    /**
     * The timezone every scheduled re-authorisation is named in.
     *
     * See schedule() for why this is pinned rather than read from app.timezone.
     */
    public const TIMEZONE = 'Asia/Manila';

    public function __construct(private UserManagerClient $client)
    {
    }

    // ── Rate limits ───────────────────────────────────────────────────────

    /**
     * Turns what an operator typed into what RouterOS writes.
     *
     * Accepts the shapes people actually use, and normalises all of them to
     * `Mikrotik-Rate-Limit` form — `rx/tx`, with `k`, `M` or `G`:
     *
     *     250mb              → 250M/250M      (one figure means symmetric)
     *     250mb/100mb        → 250M/100M
     *     250 Mbps / 50 Mbps → 250M/50M
     *     1.5gb/512kb        → 1500M/512k
     *     250M/250M          → 250M/250M      (already correct, case-normalised)
     *
     * ── Why a bare number is rejected ─────────────────────────────────
     *
     * RouterOS reads an unsuffixed rate limit as *bits per second*, so `250`
     * means 250 bps — a modem from 1962. It is a legal value, the router accepts
     * it without complaint, and an operator who typed it meant 250 Mbps. There
     * is no way to tell those two apart from the input, so this refuses to guess
     * and names the fix instead. Guessing wrong here throttles a region.
     *
     * Fractions are converted down a unit rather than passed through: RouterOS
     * takes no decimals, so `1.5gb` becomes `1500M` and not the `1G` a truncation
     * would produce.
     *
     * `warning` is set — rather than the value rejected — when a rate comes out
     * implausibly low. Sub-megabit groups are real (a restricted or walled-garden
     * tier is exactly that), so this cannot be an error; it is the one shape
     * where a missing unit and a deliberate choice look identical, and the honest
     * response is to say so and let the operator confirm.
     *
     * @return array{value:string,rx_bps:int,tx_bps:int,warning:?string}
     * @throws InvalidArgumentException on anything that cannot be read as a rate
     */
    public function parseRateLimit(string $input): array
    {
        $raw = trim($input);

        if ($raw === '') {
            throw new InvalidArgumentException('Enter a rate limit, for example 250mb or 250M/50M.');
        }

        // The slash is doing two jobs in the wild: it separates download from
        // upload, and it also spells "per second" in "250mb/s". Splitting
        // naively makes the second one look like an upload rate of "s".
        //
        // So the split happens first and the "per second" fragments are dropped
        // after it, which handles both "250mb/s" and "250mbit/s / 50mbit/s"
        // without a lookbehind that would have to guess which slash was which.
        $parts = array_values(array_filter(
            array_map('trim', explode('/', $raw)),
            fn (string $part) => preg_match('/^s(?:ec|econd|econds)?$/i', $part) !== 1
        ));

        if ($parts === []) {
            throw new InvalidArgumentException('Enter a rate limit, for example 250mb or 250M/50M.');
        }

        if (count($parts) > 2) {
            throw new InvalidArgumentException(
                'A rate limit is one figure, or two separated by a slash — for example 250mb/50mb.'
            );
        }

        $rx = $this->toBitsPerSecond($parts[0], 'download');
        // One figure means symmetric, which is what a fibre operator almost
        // always means and what Winbox shows when both halves match.
        $tx = isset($parts[1]) ? $this->toBitsPerSecond($parts[1], 'upload') : $rx;

        $low = min($rx, $tx);

        return [
            'value' => $this->render($rx) . '/' . $this->render($tx),
            'rx_bps' => $rx,
            'tx_bps' => $tx,
            'warning' => $low < self::SUSPICIOUS_BPS
                ? sprintf(
                    'That works out to %s, which is under 1 Mbps. If you meant megabits, write the unit — 250mb rather than 250kb.',
                    $this->render($low)
                )
                : null,
        ];
    }

    /**
     * One side of a rate limit, in bits per second.
     *
     * @throws InvalidArgumentException
     */
    private function toBitsPerSecond(string $side, string $which): int
    {
        $value = strtolower(trim($side));

        if ($value === '') {
            throw new InvalidArgumentException("The {$which} rate is missing.");
        }

        // Optional whitespace, then a number, then a unit that may be spelled
        // any of the ways an operator or a datasheet writes it: M, mb, Mbps,
        // Mbit, Mbit/s.
        // "250m", "250mb", "250mbit", "250mbits", "250mbps". The "/s" spelling
        // has already been split off by the caller — see parseRateLimit.
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])(?:b|bit|bits)?(?:ps)?$/', $value, $match)) {
            // A bare number is called out separately: it is the common mistake
            // and the generic message would not tell anyone what to do about it.
            if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s rate "%s" has no unit. RouterOS would read that as %s bits per second — write kb, mb or gb (for example %smb).',
                    $which,
                    trim($side),
                    trim($side),
                    trim($side)
                ));
            }

            throw new InvalidArgumentException(
                "Could not read \"{$side}\" as a {$which} rate. Use a number and a unit, for example 250mb."
            );
        }

        $bits = (float) $match[1] * self::UNITS[$match[2]];

        if ($bits < 1) {
            throw new InvalidArgumentException("The {$which} rate must be more than zero.");
        }

        if ($bits > self::MAX_BPS) {
            throw new InvalidArgumentException(sprintf(
                'The %s rate is above the %s ceiling this screen will set. Check for an extra digit.',
                $which,
                $this->render(self::MAX_BPS)
            ));
        }

        return (int) round($bits);
    }

    /**
     * Bits per second as the shortest exact RouterOS token.
     *
     * Exact is the requirement: 1500M rather than 1.5G, because RouterOS accepts
     * no decimals and rounding to 1G would quietly hand out a third less
     * bandwidth than was asked for. A value that divides into no unit cleanly is
     * written bare, in bits, which RouterOS reads correctly.
     */
    private function render(int $bits): string
    {
        foreach (['g' => 'G', 'm' => 'M', 'k' => 'k'] as $unit => $suffix) {
            $size = self::UNITS[$unit];

            if ($bits >= $size && $bits % $size === 0) {
                return intdiv($bits, $size) . $suffix;
            }
        }

        return (string) $bits;
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /**
     * Everything the tabbed screen renders, in one pass over the fleet.
     *
     * Five collections and the local queue, fetched once each. The session list
     * is fetched once and indexed twice — by group for the blast-radius counts,
     * by user for the per-user live state — rather than being walked again per
     * row, which on a few thousand users would be a quadratic pass over a list
     * that is already in memory.
     */
    public function overview(): array
    {
        $groups = $this->client->list('groups');
        $profiles = $this->client->list('profiles');
        $limitations = $this->client->list('limitations');
        $attributes = $this->client->list('attributes');
        $sessions = $this->client->list('sessions');

        $shaped = array_map(fn ($row) => $this->session($row), $sessions['rows']);

        return [
            'configured' => true,
            'servers' => array_map(
                fn ($server) => ['key' => $server['key'], 'label' => $server['label']],
                $this->client->servers()
            ),
            'source' => $this->client->source(),

            'groups' => $this->block($groups, fn ($row) => $this->group($row)),
            'profiles' => $this->block($profiles, fn ($row) => $this->profile($row)),
            'limitations' => $this->block($limitations, fn ($row) => $this->limitation($row)),
            'attributes' => $this->block($attributes, fn ($row) => $this->attribute($row)),
            'sessions' => [
                'reachable' => $sessions['reachable'],
                'errors' => $sessions['errors'],
                'rows' => $shaped,
            ],

            'sessions_by_group' => $this->countByGroup($shaped),
            'queued' => $this->queue(),
            'maintenance_window' => [
                'start' => config('mikrotik.maintenance_window.start'),
                'end' => config('mikrotik.maintenance_window.end'),
                'next' => MikrotikKick::nextWindow()->toDateTimeString(),
                'open_now' => MikrotikKick::inWindow(),
            ],
            'timezone' => [
                'name' => self::TIMEZONE,
                'label' => 'GMT+8',
                'now' => Carbon::now(self::TIMEZONE)->toDateTimeString(),
            ],
        ];
    }

    /**
     * Users matching a search, each carrying its live session state.
     *
     * ── Why the sessions are indexed rather than queried per user ─────
     *
     * The obvious shape — for each matching user, ask the router for its
     * sessions — is one HTTP round trip per row against a device that is also
     * serving live authentication. At 250 rows that is 250 requests to answer a
     * question one request already answered. The session list is pulled once and
     * turned into a map, so enriching the page costs a hash lookup per user
     * regardless of how many there are.
     *
     * Filtering happens before the cap, so a search that matches row 4,000 finds
     * it. The cap is on the response rather than in the UI because ten thousand
     * rows of JSON is a slow page whether or not the table paginates them.
     *
     * @return array{reachable:bool,errors:array,total:int,rows:array,truncated:bool}
     */
    public function users(string $search = '', int $limit = 250): array
    {
        $result = $this->client->list('users');
        $sessions = $this->client->list('sessions');

        $byUser = [];

        foreach ($sessions['rows'] as $row) {
            $session = $this->session($row);
            $name = $session['user'];

            if ($name === '') {
                continue;
            }

            // First session wins for the displayed caller ID; the count below is
            // what says whether there are more. A shared account legitimately
            // has several, and picking one arbitrarily to display is honest as
            // long as the count is beside it.
            if (!isset($byUser[$name])) {
                $byUser[$name] = ['sessions' => 0, 'session' => $session];
            }

            $byUser[$name]['sessions']++;
        }

        $needle = strtolower(trim($search));
        $rows = [];

        foreach ($result['rows'] as $raw) {
            $user = $this->user($raw);
            $live = $byUser[$user['username']] ?? null;

            $user['sessions'] = $live['sessions'] ?? 0;
            $user['online'] = $live !== null;
            // The caller ID as the *session* reports it, falling back to the one
            // stored on the user record. They differ: the stored value is a
            // binding the operator configured, the session value is the MAC or
            // circuit actually connected right now, and on a support call it is
            // the second one that matters.
            $user['caller_id'] = $live['session']['caller_id'] ?? $user['caller_id'];
            $user['address'] = $live['session']['address'] ?? '';
            $user['uptime'] = $live['session']['uptime'] ?? '';

            if ($needle !== '' && !$this->matches($user, $needle)) {
                continue;
            }

            $rows[] = $user;
        }

        $total = count($rows);

        return [
            'reachable' => $result['reachable'],
            'errors' => $result['errors'],
            'total' => $total,
            'rows' => array_slice($rows, 0, $limit),
            'truncated' => $total > $limit,
        ];
    }

    /** Whether a user row answers the search, across the fields worth searching. */
    private function matches(array $user, string $needle): bool
    {
        $haystack = strtolower(implode(' ', [
            $user['username'],
            $user['group'],
            $user['caller_id'],
            $user['address'],
            $user['comment'],
        ]));

        return str_contains($haystack, $needle);
    }

    /** @return array<string,int> group name => live sessions */
    private function countByGroup(array $sessions): array
    {
        $counts = [];

        foreach ($sessions as $session) {
            $group = $session['group'];

            if ($group === '') {
                continue;
            }

            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    // ── Writes ────────────────────────────────────────────────────────────

    /**
     * Sets a group's rate limit and/or framed pool, and reports the blast radius.
     *
     * The audit row is written before the attempt rather than after. A change
     * that crashes the router mid-request still happened, and a trail that only
     * records successes cannot answer what was being done at the time — which is
     * the one question worth asking after an outage.
     *
     * @param  array{rate_limit?:string,framed_pool?:string,comment?:string} $changes
     * @return array{server:string,before:array,after:array,rate_limit:?array,live_sessions:int,note:string}
     */
    public function updateGroup(Request $request, string $group, array $changes): array
    {
        $located = $this->client->locate('groups', 'name', $group);
        if ($located === null) {
            throw new InvalidArgumentException("No RADIUS group named '{$group}' exists on any reachable server.");
        }

        $existingRow = $located['row'];
        $existingAttrs = is_array($existingRow['attributes'] ?? null)
            ? implode(',', $existingRow['attributes'])
            : (string) ($existingRow['attributes'] ?? '');

        $parsed = null;
        $newRateLimit = null;
        $newFramedPool = null;

        if (isset($changes['rate_limit']) && trim((string) $changes['rate_limit']) !== '') {
            $parsed = $this->parseRateLimit((string) $changes['rate_limit']);
            $newRateLimit = $parsed['value'];
        }

        if (isset($changes['framed_pool']) && trim((string) $changes['framed_pool']) !== '') {
            $newFramedPool = trim((string) $changes['framed_pool']);
        }

        $updatedAttrs = $this->updateAttributesString($existingAttrs, $newRateLimit, $newFramedPool);

        $payload = [
            'attributes' => $updatedAttrs,
        ];

        if (array_key_exists('comment', $changes) && $changes['comment'] !== null) {
            $payload['comment'] = (string) $changes['comment'];
        }

        AuditLog::record(
            $request,
            'updated',
            'mikrotik-group',
            $group,
            "Changed RADIUS group '{$group}'",
            $payload
        );

        $result = $this->client->update('groups', 'name', $group, $payload);

        $live = $this->liveSessionsIn($group);

        return [
            'server' => $result['server'],
            'before' => $this->group($result['before']),
            'after' => $this->group($result['after']),
            'rate_limit' => $parsed,
            'live_sessions' => $live,
            'note' => $live > 0
                ? "{$live} session(s) are still running on the previous settings. Disconnect them to apply the change now, or schedule it."
                : 'No live sessions on this group — the change applies to the next connection.',
        ];
    }

    private function updateAttributesString(string $existing, ?string $rateLimit, ?string $framedPool): string
    {
        $parts = array_filter(array_map('trim', explode(',', $existing)));
        $attrs = [];

        foreach ($parts as $part) {
            if ($part === '') continue;
            $pair = array_map('trim', explode(':', $part, 2));
            if (!empty($pair[0])) {
                $attrs[$pair[0]] = $pair[1] ?? '';
            }
        }

        if ($rateLimit !== null) {
            $attrs['Mikrotik-Rate-Limit'] = $rateLimit;
        }

        if ($framedPool !== null) {
            $attrs['Framed-Pool'] = $framedPool;
        }

        $formatted = [];
        foreach ($attrs as $k => $v) {
            $formatted[] = "{$k}:{$v}";
        }

        return implode(',', $formatted);
    }

    /**
     * Moves one user to another group.
     *
     * The user keeps their current session, and therefore their current speed,
     * until it ends — the same trap as a rate-limit change and for the same
     * reason. The live session count comes back so the caller can offer to
     * disconnect them, and it is the count for *this user*, not the group: a
     * reassignment is about one subscriber and kicking the whole group to move
     * one of them would be wildly disproportionate.
     *
     * @return array{server:string,before:array,after:array,live_sessions:int,note:string}
     */
    public function moveUser(Request $request, string $username, string $group): array
    {
        // Verified before the write. RouterOS accepts a group name that does not
        // exist and the user then authenticates against nothing, which presents
        // as "this one customer is offline and nobody knows why".
        if (!$this->groupExists($group)) {
            throw new InvalidArgumentException("No RADIUS group named '{$group}' exists on any reachable server.");
        }

        AuditLog::record(
            $request,
            'updated',
            'mikrotik-user',
            $username,
            "Moved RADIUS user '{$username}' into group '{$group}'",
            ['group' => $group]
        );

        $result = $this->client->update('users', 'name', $username, ['group' => $group]);

        $live = count($this->client->sessions($username)['rows']);

        return [
            'server' => $result['server'],
            'before' => $this->user($result['before']),
            'after' => $this->user($result['after']),
            'live_sessions' => $live,
            'note' => $live > 0
                ? "{$username} has {$live} live session(s) still on the old group's settings. Disconnect to re-authorise now."
                : "{$username} will authenticate into {$group} on the next connection.",
        ];
    }

    /**
     * Disconnects sessions immediately.
     *
     * @param  string[] $usernames
     * @return array{killed:int,failed:int,attempted:int,errors:array<int,string>}
     */
    public function kickNow(Request $request, array $usernames, ?string $group): array
    {
        AuditLog::record(
            $request,
            'executed',
            'mikrotik-kick',
            $group ?? implode(',', $usernames),
            'Disconnected RADIUS sessions immediately',
            ['group' => $group, 'usernames' => $usernames]
        );

        $result = $this->client->kick($usernames, $group);

        // Warning rather than info: this is the log line somebody reads at 3am
        // when asked why a region dropped, and it needs to be visible at the
        // default level.
        Log::warning('MikroTik RADIUS sessions terminated', [
            'actor' => $request->user()?->username,
            'group' => $group,
            'usernames' => $usernames,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Queues a disconnection for a wall-clock time in Asia/Manila.
     *
     * ── Why the timezone is pinned rather than inherited ──────────────
     *
     * `config('app.timezone')` is Asia/Manila today, which makes this look like
     * ceremony. It is not: the operator is naming a time in the field, the field
     * is in GMT+8, and that stays true whether or not the application timezone
     * is changed later or the container is redeployed somewhere with a different
     * one. Parsing in the named zone and storing the absolute instant is the only
     * arrangement where "2pm" means 2pm in a year's time.
     *
     * The zone the operator typed against is stored beside the timestamp so a row
     * can be read back without assuming the server's configuration never moved.
     *
     * Both writes — the queue row and its audit entry — are one transaction. A
     * queued disconnection with no record of who ordered it, or a record of an
     * order that was never queued, are both worse than the request failing.
     *
     * @param  string[] $usernames
     */
    public function schedule(
        Request $request,
        array $usernames,
        ?string $group,
        string $at,
        ?string $reason
    ): MikrotikKick {
        $when = $this->parseManilaTime($at);

        return DB::transaction(function () use ($request, $usernames, $group, $when, $reason, $at) {
            $kick = MikrotikKick::create([
                'target_group' => $group,
                'target_usernames' => $usernames ?: null,
                'reason' => $reason,
                'requested_by' => $request->user()?->id,
                'requested_by_name' => $request->user()?->username,
                'status' => MikrotikKick::STATUS_PENDING,
                'mode' => MikrotikKick::MODE_AT,
                'scheduled_for' => $when,
                'scheduled_timezone' => self::TIMEZONE,
            ]);

            AuditLog::record(
                $request,
                'queued',
                'mikrotik-kick',
                (string) $kick->id,
                sprintf(
                    'Scheduled a RADIUS re-authorisation for %s at %s (%s)',
                    $kick->targetLabel(),
                    $when->copy()->setTimezone(self::TIMEZONE)->format('D j M Y, H:i'),
                    self::TIMEZONE
                ),
                ['group' => $group, 'usernames' => $usernames, 'at' => $at]
            );

            return $kick;
        });
    }

    /** Queues a disconnection for the next maintenance window instead. */
    public function scheduleForWindow(
        Request $request,
        array $usernames,
        ?string $group,
        ?string $reason
    ): MikrotikKick {
        return DB::transaction(function () use ($request, $usernames, $group, $reason) {
            $kick = MikrotikKick::create([
                'target_group' => $group,
                'target_usernames' => $usernames ?: null,
                'reason' => $reason,
                'requested_by' => $request->user()?->id,
                'requested_by_name' => $request->user()?->username,
                'status' => MikrotikKick::STATUS_PENDING,
                'mode' => MikrotikKick::MODE_WINDOW,
                'scheduled_for' => MikrotikKick::nextWindow(),
                'scheduled_timezone' => config('app.timezone'),
            ]);

            AuditLog::record(
                $request,
                'queued',
                'mikrotik-kick',
                (string) $kick->id,
                "Queued a session kick for {$kick->targetLabel()} at {$kick->scheduled_for}",
                ['group' => $group, 'usernames' => $usernames]
            );

            return $kick;
        });
    }

    /**
     * Cancels a queued kick that has not fired.
     *
     * Conditional on the row still being pending, in one statement, so two
     * operators cancelling at once cannot both believe they did it and neither
     * can cancel one that started running a millisecond earlier.
     */
    public function cancel(Request $request, MikrotikKick $kick): bool
    {
        return DB::transaction(function () use ($request, $kick) {
            $cancelled = MikrotikKick::query()
                ->whereKey($kick->getKey())
                ->where('status', MikrotikKick::STATUS_PENDING)
                ->update(['status' => MikrotikKick::STATUS_CANCELLED]);

            if ($cancelled === 0) {
                return false;
            }

            AuditLog::record(
                $request,
                'cancelled',
                'mikrotik-kick',
                (string) $kick->id,
                "Cancelled the queued session kick for {$kick->targetLabel()}"
            );

            return true;
        });
    }

    /**
     * A wall-clock time in Asia/Manila, as an absolute instant.
     *
     * Refuses the past. "Schedule for 20 minutes ago" has no sane reading: the
     * drain would fire it on its next tick, which is indistinguishable from
     * pressing Disconnect Now — and if that is what somebody meant, that button
     * is right there and says so.
     *
     * @throws InvalidArgumentException
     */
    public function parseManilaTime(string $at): Carbon
    {
        try {
            $when = Carbon::parse(trim($at), self::TIMEZONE);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'Could not read that as a date and time. Use YYYY-MM-DD HH:MM.'
            );
        }

        if ($when->isPast()) {
            throw new InvalidArgumentException(sprintf(
                'That time has already passed in %s (it is now %s there). Pick a later time, or disconnect now.',
                self::TIMEZONE,
                Carbon::now(self::TIMEZONE)->format('D j M Y, H:i')
            ));
        }

        // A year out is not a schedule, it is a typo in the year field. The row
        // would sit in the queue outliving the group it names.
        if ($when->greaterThan(Carbon::now(self::TIMEZONE)->addYear())) {
            throw new InvalidArgumentException('That is more than a year away. Check the date.');
        }

        return $when;
    }

    // ── Queue ─────────────────────────────────────────────────────────────

    /**
     * The recent queue, newest first.
     *
     * Timestamps are rendered in Asia/Manila whatever the server runs in, and
     * labelled, because "02:00" with no zone on a screen that disconnects people
     * is the kind of ambiguity that gets a maintenance window run at the wrong
     * hour.
     */
    public function queue(int $limit = 25): array
    {
        return MikrotikKick::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MikrotikKick $kick) => [
                'id' => $kick->id,
                'target' => $kick->targetLabel(),
                'group' => $kick->target_group,
                'usernames' => $kick->target_usernames ?? [],
                'reason' => $kick->reason,
                'status' => $kick->status,
                'mode' => $kick->mode,
                'requested_by' => $kick->requested_by_name,
                'scheduled_for' => $kick->scheduled_for
                    ?->copy()
                    ->setTimezone(self::TIMEZONE)
                    ->toDateTimeString(),
                'scheduled_timezone' => $kick->scheduled_timezone ?? config('app.timezone'),
                'executed_at' => $kick->executed_at
                    ?->copy()
                    ->setTimezone(self::TIMEZONE)
                    ->toDateTimeString(),
                'sessions_killed' => $kick->sessions_killed,
                'sessions_failed' => $kick->sessions_failed,
                'result_note' => $kick->result_note,
            ])
            ->all();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function client(): UserManagerClient
    {
        return $this->client;
    }

    /** How many sessions are live on a group right now. */
    public function liveSessionsIn(string $group): int
    {
        $sessions = $this->client->sessions();

        return count(array_filter(
            $sessions['rows'],
            fn ($row) => (string) ($row['user-group'] ?? $row['group'] ?? '') === $group
        ));
    }

    private function groupExists(string $group): bool
    {
        return $this->client->locate('groups', 'name', $group) !== null;
    }

    /** @param callable $shape */
    private function block(array $result, callable $shape): array
    {
        return [
            'reachable' => $result['reachable'],
            'errors' => $result['errors'],
            'rows' => array_map($shape, $result['rows']),
        ];
    }

    // ── Shapers ───────────────────────────────────────────────────────────
    //
    // RouterOS returns kebab-case keys, a leading-dot id, and different spellings
    // between major versions. Normalised once here so the frontend reads one
    // shape and a RouterOS upgrade cannot become a UI change.

    private function group(array $row): array
    {
        $attributesRaw = $row['attributes'] ?? '';
        $attributesStr = is_array($attributesRaw)
            ? implode(', ', $attributesRaw)
            : (string) $attributesRaw;

        $rateLimit = (string) ($row['rate-limit'] ?? '');
        $framedPool = (string) ($row['framed-pool'] ?? '');

        // Extract from attributes string if missing at root level (RouterOS v7 User Manager)
        if ($rateLimit === '' && $attributesStr !== '') {
            if (preg_match('/Mikrotik-Rate-Limit[:=]\s*([^,\s]+)/i', $attributesStr, $m)) {
                $rateLimit = trim($m[1]);
            }
        }

        if ($framedPool === '' && $attributesStr !== '') {
            if (preg_match('/Framed-Pool[:=]\s*([^,\s]+)/i', $attributesStr, $m)) {
                $framedPool = trim($m[1]);
            }
        }

        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'rate_limit' => $rateLimit,
            'framed_pool' => $framedPool,
            'shared_users' => (string) ($row['shared-users'] ?? ''),
            'outer_auths' => (string) ($row['outer-auths'] ?? ''),
            'inner_auths' => (string) ($row['inner-auths'] ?? ''),
            'attributes' => $attributesStr,
            'comment' => (string) ($row['comment'] ?? ''),
        ];
    }

    private function profile(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price' => (string) ($row['price'] ?? ''),
            'validity' => (string) ($row['validity'] ?? ''),
            'starts_at' => (string) ($row['starts-at'] ?? ''),
        ];
    }

    /**
     * One RADIUS dictionary entry.
     *
     * `packet-types` is spelled differently across RouterOS versions and is
     * sometimes a list; flattened to a string here so the table renders one shape
     * whichever the router returned.
     */
    private function attribute(array $row): array
    {
        $packets = $row['packet-types'] ?? $row['packet-type'] ?? '';

        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'type_id' => (string) ($row['type-id'] ?? ''),
            'value_type' => (string) ($row['value-type'] ?? ''),
            'vendor_id' => (string) ($row['vendor-id'] ?? ''),
            'packet_types' => is_array($packets) ? implode(', ', $packets) : (string) $packets,
        ];
    }

    private function limitation(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'rate_limit' => (string) ($row['rate-limit'] ?? ''),
            'download_limit' => (string) ($row['download-limit'] ?? ''),
            'upload_limit' => (string) ($row['upload-limit'] ?? ''),
            'uptime_limit' => (string) ($row['uptime-limit'] ?? ''),
        ];
    }

    private function user(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'username' => (string) ($row['name'] ?? ''),
            'group' => (string) ($row['group'] ?? ''),
            'shared_users' => (string) ($row['shared-users'] ?? ''),
            'caller_id' => (string) ($row['caller-id'] ?? ''),
            'attributes' => (string) ($row['attributes'] ?? ''),
            'disabled' => filter_var($row['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'comment' => (string) ($row['comment'] ?? ''),
            // Deliberately no password field. This screen has no reason to read
            // one and every reason not to put it in a JSON payload.
        ];
    }

    private function session(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'user' => (string) ($row['user'] ?? ''),
            'group' => (string) ($row['user-group'] ?? $row['group'] ?? ''),
            'address' => (string) ($row['user-address'] ?? $row['address'] ?? ''),
            'caller_id' => (string) ($row['calling-station-id'] ?? $row['caller-id'] ?? ''),
            'nas' => (string) ($row['nas-ip-address'] ?? ''),
            'uptime' => (string) ($row['uptime'] ?? ''),
            'started' => (string) ($row['started'] ?? ''),
            'download' => (string) ($row['download'] ?? ''),
            'upload' => (string) ($row['upload'] ?? ''),
        ];
    }
}
