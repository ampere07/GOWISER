<?php

namespace App\Support;

/**
 * The whole permission vocabulary, in one place.
 *
 * Three tiers, because "can this person see the Financial tab" and "can this
 * person mark a payable as paid" are genuinely different questions and
 * collapsing them into one list is how an auditor ends up able to edit:
 *
 *   MODULES   a navigable section. Matches the sidebar item ids exactly, which
 *             is what lets the frontend filter the menu from the same list the
 *             backend gates routes with.
 *   WIDGETS   a panel inside a section. Absent means the panel is hidden, and —
 *             for money — that the figures behind it are masked server-side
 *             before the payload leaves. See PayloadMasker.
 *   ACTIONS   something that changes state or leaves the building: a payable
 *             toggle, an export, a role assignment.
 *
 * Ids are namespaced by tier ('widget.', 'action.') so a permission list can be
 * partitioned without a lookup, and so a module id can never collide with a
 * widget id.
 *
 * Failing closed is deliberate throughout: an id that is not in a user's
 * effective list is denied. A new widget added to this file is invisible to
 * every existing role until someone grants it, which is the right default for a
 * portal whose whole content is commercially sensitive.
 */
class Permissions
{
    // ── Modules (tabs) ───────────────────────────────────────────────────

    public const MODULE_SUBSCRIBERS = 'subscriber-analytics';
    public const MODULE_FINANCIAL = 'financial';
    public const MODULE_OPERATIONS = 'field-operations';
    public const MODULE_TECH = 'tech';
    public const MODULE_EMPLOYEE = 'employee';
    public const MODULE_EXECUTIVE = 'executive-overview';
    public const MODULE_USERS = 'users';
    public const MODULE_ROLES = 'roles';
    public const MODULE_AUDIT = 'audit';
    public const MODULE_SETTINGS = 'settings';
    public const MODULE_DATABASES = 'databases';
    public const MODULE_MIKROTIK = 'mikrotik-radius';

    // ── Widgets ──────────────────────────────────────────────────────────

    /** Revenue, net, margin and every peso figure on the Financial module. */
    public const WIDGET_FINANCIAL_REVENUE = 'widget.financial.revenue';
    public const WIDGET_FINANCIAL_CHANNELS = 'widget.financial.channels';
    public const WIDGET_FINANCIAL_METRICS = 'widget.financial.metrics';
    public const WIDGET_FINANCIAL_OPEX = 'widget.financial.opex-capex';
    public const WIDGET_FINANCIAL_PAYABLES = 'widget.financial.payables';
    public const WIDGET_SUBSCRIBER_BILLING = 'widget.subscriber.billing';
    public const WIDGET_SUBSCRIBER_BARANGAY = 'widget.subscriber.barangay';
    public const WIDGET_OPERATIONS_SLA = 'widget.operations.sla';
    public const WIDGET_EXECUTIVE_FINANCE = 'widget.executive.finance';

    // ── Actions ──────────────────────────────────────────────────────────

    /** Mark a payable paid or unpaid for a month. */
    public const ACTION_PAYABLE_TOGGLE = 'action.payables.toggle';
    /** Open the print overlay and pull the line-level printable ledger. */
    public const ACTION_FINANCIAL_EXPORT = 'action.financial.export';
    /** Change a widget's date range away from its default. */
    public const ACTION_FILTERS_MODIFY = 'action.filters.modify';
    public const ACTION_USERS_MANAGE = 'action.users.manage';
    public const ACTION_ROLES_MANAGE = 'action.roles.manage';
    public const ACTION_AUDIT_VIEW = 'action.audit.view';
    /** Change the logo and the colour palette the whole portal renders in. */
    public const ACTION_SETTINGS_MANAGE = 'action.settings.manage';

    /**
     * Change a RADIUS group's rate limit or framed pool.
     *
     * Deliberately separate from MODULE_MIKROTIK. Reading the User Manager is a
     * reasonable thing to grant an operations lead; re-shaping the speed profile
     * every subscriber in a group receives is not the same act, and one grant
     * covering both would hand out the second every time somebody wanted the
     * first.
     */
    public const ACTION_MIKROTIK_WRITE = 'action.mikrotik.write';

    /**
     * Terminate live sessions.
     *
     * Its own grant again, and the narrowest one in this file. Everything else
     * here changes what happens next; this disconnects people who are online
     * right now.
     */
    public const ACTION_MIKROTIK_KICK = 'action.mikrotik.kick';

    /**
     * Every module id, including the executive rollups this refactor left in
     * place. Order is the order the sidebar presents them.
     */
    public const MODULES = [
        self::MODULE_EXECUTIVE,
        self::MODULE_SUBSCRIBERS,
        self::MODULE_FINANCIAL,
        self::MODULE_OPERATIONS,
        self::MODULE_TECH,
        self::MODULE_EMPLOYEE,
        self::MODULE_USERS,
        self::MODULE_ROLES,
        self::MODULE_AUDIT,
        self::MODULE_SETTINGS,
        self::MODULE_DATABASES,
        self::MODULE_MIKROTIK,
    ];

    public const WIDGETS = [
        self::WIDGET_FINANCIAL_REVENUE,
        self::WIDGET_FINANCIAL_CHANNELS,
        self::WIDGET_FINANCIAL_METRICS,
        self::WIDGET_FINANCIAL_OPEX,
        self::WIDGET_FINANCIAL_PAYABLES,
        self::WIDGET_SUBSCRIBER_BILLING,
        self::WIDGET_SUBSCRIBER_BARANGAY,
        self::WIDGET_OPERATIONS_SLA,
        self::WIDGET_EXECUTIVE_FINANCE,
    ];

    public const ACTIONS = [
        self::ACTION_PAYABLE_TOGGLE,
        self::ACTION_FINANCIAL_EXPORT,
        self::ACTION_FILTERS_MODIFY,
        self::ACTION_USERS_MANAGE,
        self::ACTION_ROLES_MANAGE,
        self::ACTION_AUDIT_VIEW,
        self::ACTION_SETTINGS_MANAGE,
        self::ACTION_MIKROTIK_WRITE,
        self::ACTION_MIKROTIK_KICK,
    ];

    /**
     * Which section endpoint each module gates.
     *
     * Used by ReportingController to reject a section the caller's role cannot
     * open, so a hidden tab is not merely hidden — the data behind it is
     * unreachable by hand-typing the URL.
     */
    public const SECTION_MODULES = [
        'subscriber_analytics' => self::MODULE_SUBSCRIBERS,
        'financial' => self::MODULE_FINANCIAL,
        'operations' => self::MODULE_OPERATIONS,
        'tech' => self::MODULE_TECH,
        'employee' => self::MODULE_EMPLOYEE,
    ];

    /**
     * Human labels for the permission-mapping screen.
     *
     * Kept beside the ids rather than in the frontend so a new permission cannot
     * appear in the UI as a raw slug nobody can interpret.
     */
    public const LABELS = [
        self::MODULE_EXECUTIVE => 'Executive Group Overview',
        self::MODULE_SUBSCRIBERS => 'Subscriber Analytics',
        self::MODULE_FINANCIAL => 'Financial Analytics',
        self::MODULE_OPERATIONS => 'Operations Analytics',
        self::MODULE_TECH => 'Tech Analytics',
        self::MODULE_EMPLOYEE => 'Employee',
        self::MODULE_USERS => 'User Management',
        self::MODULE_ROLES => 'Roles & Permissions',
        self::MODULE_AUDIT => 'Audit Trail',
        self::MODULE_SETTINGS => 'Settings',
        self::MODULE_DATABASES => 'Databases',
        self::MODULE_MIKROTIK => 'Mikrotik Radius Shortcut',

        self::WIDGET_FINANCIAL_REVENUE => 'Revenue figures (unmasked)',
        self::WIDGET_FINANCIAL_CHANNELS => 'Income channels (Cash / PNB / Payment Portal)',
        self::WIDGET_FINANCIAL_METRICS => 'Executive financial metrics',
        self::WIDGET_FINANCIAL_OPEX => 'OpEx and CapEx',
        self::WIDGET_FINANCIAL_PAYABLES => 'Accounts payable and recurring expenses',
        self::WIDGET_SUBSCRIBER_BILLING => 'Billing status summary',
        self::WIDGET_SUBSCRIBER_BARANGAY => 'Barangay breakdown',
        self::WIDGET_OPERATIONS_SLA => 'SLA and turnaround',
        self::WIDGET_EXECUTIVE_FINANCE => 'Executive financial summary',

        self::ACTION_PAYABLE_TOGGLE => 'Mark payables paid / unpaid',
        self::ACTION_FINANCIAL_EXPORT => 'Export and print financial reports',
        self::ACTION_FILTERS_MODIFY => 'Change widget date ranges',
        self::ACTION_USERS_MANAGE => 'Create and edit users',
        self::ACTION_ROLES_MANAGE => 'Edit role permission maps',
        self::ACTION_AUDIT_VIEW => 'Read the audit trail',
        self::ACTION_SETTINGS_MANAGE => 'Change the logo and colour palette',
        self::ACTION_MIKROTIK_WRITE => 'Change RADIUS group rate limits and pools',
        self::ACTION_MIKROTIK_KICK => 'Disconnect live RADIUS sessions',
    ];

    /**
     * The five shipped roles.
     *
     * Presets rather than hardcoded checks: a deployment can reshape any of them
     * on the User Management screen, and the seeder only creates what is missing.
     * What each one deliberately *omits* is the point:
     *
     *   Finance Admin   no technician roster, no employee attribution
     *   Operations Lead no money at all — not even masked, the tab is absent
     *   Tech Lead       field work only
     *   Auditor         every module it can read, and not one action
     */
    public const ROLE_PRESETS = [
        'Super Admin' => [
            'description' => 'Unrestricted. Every module, every widget, every action.',
            'permissions' => null, // null = everything; expanded by all()
        ],
        'Executive' => [
            'description' => 'Full read across the business, plus export. No user or database administration.',
            'permissions' => [
                self::MODULE_EXECUTIVE, self::MODULE_SUBSCRIBERS, self::MODULE_FINANCIAL,
                self::MODULE_OPERATIONS, self::MODULE_TECH, self::MODULE_EMPLOYEE,
                self::WIDGET_FINANCIAL_REVENUE, self::WIDGET_FINANCIAL_CHANNELS,
                self::WIDGET_FINANCIAL_METRICS, self::WIDGET_FINANCIAL_OPEX,
                self::WIDGET_FINANCIAL_PAYABLES, self::WIDGET_SUBSCRIBER_BILLING,
                self::WIDGET_SUBSCRIBER_BARANGAY, self::WIDGET_OPERATIONS_SLA,
                self::WIDGET_EXECUTIVE_FINANCE,
                self::ACTION_FINANCIAL_EXPORT, self::ACTION_FILTERS_MODIFY,
            ],
        ],
        'Finance Admin' => [
            'description' => 'Money and subscribers. Owns the payables ledger.',
            'permissions' => [
                self::MODULE_SUBSCRIBERS, self::MODULE_FINANCIAL,
                self::WIDGET_FINANCIAL_REVENUE, self::WIDGET_FINANCIAL_CHANNELS,
                self::WIDGET_FINANCIAL_METRICS, self::WIDGET_FINANCIAL_OPEX,
                self::WIDGET_FINANCIAL_PAYABLES, self::WIDGET_SUBSCRIBER_BILLING,
                self::WIDGET_SUBSCRIBER_BARANGAY,
                self::ACTION_PAYABLE_TOGGLE, self::ACTION_FINANCIAL_EXPORT,
                self::ACTION_FILTERS_MODIFY,
            ],
        ],
        'Operations Lead' => [
            'description' => 'Field delivery and the subscriber base. No financial module.',
            'permissions' => [
                self::MODULE_SUBSCRIBERS, self::MODULE_OPERATIONS, self::MODULE_TECH,
                self::WIDGET_SUBSCRIBER_BILLING, self::WIDGET_SUBSCRIBER_BARANGAY,
                self::WIDGET_OPERATIONS_SLA,
                self::ACTION_FILTERS_MODIFY,
            ],
        ],
        'Tech Lead' => [
            'description' => 'Technician roster, workload and the repair queues.',
            'permissions' => [
                self::MODULE_OPERATIONS, self::MODULE_TECH,
                self::WIDGET_OPERATIONS_SLA,
                self::ACTION_FILTERS_MODIFY,
            ],
        ],
        'Auditor' => [
            'description' => 'Read-only across every module, including the audit trail. No actions.',
            'permissions' => [
                self::MODULE_EXECUTIVE, self::MODULE_SUBSCRIBERS, self::MODULE_FINANCIAL,
                self::MODULE_OPERATIONS, self::MODULE_TECH, self::MODULE_EMPLOYEE,
                self::MODULE_AUDIT,
                self::WIDGET_FINANCIAL_REVENUE, self::WIDGET_FINANCIAL_CHANNELS,
                self::WIDGET_FINANCIAL_METRICS, self::WIDGET_FINANCIAL_OPEX,
                self::WIDGET_FINANCIAL_PAYABLES, self::WIDGET_SUBSCRIBER_BILLING,
                self::WIDGET_SUBSCRIBER_BARANGAY, self::WIDGET_OPERATIONS_SLA,
                self::WIDGET_EXECUTIVE_FINANCE,
                self::ACTION_AUDIT_VIEW,
            ],
        ],
    ];

    /**
     * Roles allowed to open the consolidated executive view.
     *
     * A second gate on top of the module permission, matching the brief's "role
     * validation" requirement: this view puts every company's money on one
     * screen, so it is not something a custom role should acquire by being
     * granted a module id.
     */
    public const EXECUTIVE_ROLES = ['super admin', 'executive', 'auditor'];

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(self::MODULES, self::WIDGETS, self::ACTIONS);
    }

    public static function label(string $id): string
    {
        return self::LABELS[$id] ?? $id;
    }

    /** Keeps only ids this build knows, so a stale grant cannot linger. */
    public static function sanitise(array $ids): array
    {
        return array_values(array_intersect(self::all(), array_map('strval', $ids)));
    }

    /**
     * The permission list for a named preset, with Super Admin expanded.
     *
     * @return string[]
     */
    public static function preset(string $role): array
    {
        $preset = self::ROLE_PRESETS[$role] ?? null;

        if ($preset === null) {
            return [];
        }

        return $preset['permissions'] === null ? self::all() : $preset['permissions'];
    }

    /** Grouped for the permission-mapping UI. */
    public static function catalogue(): array
    {
        $shape = fn (array $ids) => array_map(
            fn (string $id) => ['id' => $id, 'label' => self::label($id)],
            $ids
        );

        return [
            'modules' => $shape(self::MODULES),
            'widgets' => $shape(self::WIDGETS),
            'actions' => $shape(self::ACTIONS),
        ];
    }
}
