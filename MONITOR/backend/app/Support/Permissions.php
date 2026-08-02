<?php

namespace App\Support;

/**
 * MONITOR's permission vocabulary, and how a stored role expands into effective grants.
 *
 * A permission is `<section>.<verb>` — `financial.view`, `financial.export`, `databases.edit`.
 * Sections match the frontend menu ids so one string identifies a page on both sides.
 *
 * BACKWARD COMPATIBILITY IS THE POINT OF THIS CLASS.
 *
 * Roles in the wild store bare section ids — `['financial', 'databases']` — from before verbs
 * existed. Those rows keep working: a bare id expands to the verbs that id could actually
 * perform before this change, so nobody loses access on deploy.
 *
 * For the read-only reporting sections that means view AND export: the print/export button was
 * never separately gated, so anyone who could open Financial could already print it. Granting
 * only `view` would be a silent regression dressed up as a security improvement.
 *
 * Bare `databases` keeps the full management verb set, matching EnsureDatabaseAdmin's existing
 * all-or-nothing check on that one permission.
 */
class Permissions
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const EXPORT = 'export';

    public const VERBS = [self::VIEW, self::CREATE, self::EDIT, self::DELETE, self::EXPORT];

    /**
     * Sections, and the verbs each one can meaningfully carry.
     *
     * Reporting and executive sections are reads: offering create/edit/delete on them would
     * invent permissions that no endpoint honours, and a permission that grants nothing is worse
     * than no permission at all — an admin ticks it and believes something changed.
     *
     * Databases is the only writable section, so it is the only one with the write verbs.
     */
    public const SECTIONS = [
        // Reporting sections.
        'subscriber-analytics' => [self::VIEW, self::EXPORT],
        'financial' => [self::VIEW, self::EXPORT],
        'field-operations' => [self::VIEW, self::EXPORT],
        'tech' => [self::VIEW, self::EXPORT],
        'employee' => [self::VIEW, self::EXPORT],

        // Executive rollups.
        'overview' => [self::VIEW, self::EXPORT],
        'operations' => [self::VIEW, self::EXPORT],
        'revenue' => [self::VIEW, self::EXPORT],
        'financials' => [self::VIEW, self::EXPORT],
        'consolidated' => [self::VIEW, self::EXPORT],

        // Configuration — the sections that write, to MONITOR's own database only.
        'databases' => [self::VIEW, self::CREATE, self::EDIT, self::DELETE],
        'users' => [self::VIEW, self::CREATE, self::EDIT, self::DELETE],
    ];

    /**
     * Verbs a bare, verb-less section id is taken to grant.
     *
     * Read the class docblock before narrowing this: it is what stops a deploy from revoking
     * access every existing role already has.
     */
    private const LEGACY_VERBS = [
        'databases' => [self::VIEW, self::CREATE, self::EDIT, self::DELETE],

        // `users` postdates the bare-id era, so no stored role can hold it verb-less. Listed
        // anyway so that if one ever is written by hand it grants management rather than
        // silently falling through to the read-only default and confusing whoever set it.
        'users' => [self::VIEW, self::CREATE, self::EDIT, self::DELETE],
    ];

    private const LEGACY_DEFAULT_VERBS = [self::VIEW, self::EXPORT];

    /**
     * Expands a stored permission list into the full set of `section.verb` grants.
     *
     * Unknown sections are dropped rather than passed through. A role naming a section that no
     * longer exists should grant nothing, not a permission string that quietly matches a future
     * section of the same name.
     */
    public static function expand(array $stored): array
    {
        $effective = [];

        foreach ($stored as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '.')) {
                foreach (self::legacyVerbsFor($entry) as $verb) {
                    $effective[] = "{$entry}.{$verb}";
                }
                continue;
            }

            [$section, $verb] = explode('.', $entry, 2);

            if (!isset(self::SECTIONS[$section])) {
                continue;
            }

            // A verb the section cannot carry is dropped for the same reason: `financial.delete`
            // matches no endpoint, so honouring it would only mislead whoever reads the role.
            if (in_array($verb, self::SECTIONS[$section], true)) {
                $effective[] = "{$section}.{$verb}";
            }
        }

        return array_values(array_unique($effective));
    }

    private static function legacyVerbsFor(string $section): array
    {
        if (!isset(self::SECTIONS[$section])) {
            return [];
        }

        $verbs = self::LEGACY_VERBS[$section] ?? self::LEGACY_DEFAULT_VERBS;

        // Intersected so a legacy default can never grant a verb the section does not support.
        return array_values(array_intersect($verbs, self::SECTIONS[$section]));
    }

    /**
     * Every grant this application defines.
     *
     * Derived from SECTIONS rather than written out, so a section added there is covered without
     * anyone remembering to update a second list. This is what a superadmin role resolves to —
     * see User::effectivePermissions().
     */
    public static function all(): array
    {
        $grants = [];

        foreach (self::SECTIONS as $section => $verbs) {
            foreach ($verbs as $verb) {
                $grants[] = "{$section}.{$verb}";
            }
        }

        return $grants;
    }

    /**
     * Does this expanded list grant the permission?
     *
     * A bare section id as the *required* permission means "any verb on it" — used where an
     * endpoint just needs the section to be reachable at all.
     */
    public static function granted(array $effective, string $required): bool
    {
        $required = strtolower(trim($required));

        if (!str_contains($required, '.')) {
            foreach ($effective as $grant) {
                if (str_starts_with($grant, $required . '.')) {
                    return true;
                }
            }

            return false;
        }

        return in_array($required, $effective, true);
    }
}
