<?php

namespace Database\Seeders;

use App\Models\SchemaProfile;
use Illuminate\Database\Seeder;

/**
 * Ships the profiles MONITOR knows out of the box.
 *
 * Every column here was read off the live schema rather than assumed. A site
 * that deviates does not need a new profile — it declares just the differing
 * fields in its connection's `overrides`.
 */
class SchemaProfileSeeder extends Seeder
{
    public function run()
    {
        SchemaProfile::updateOrCreate(
            ['key' => 'sync'],
            [
                'label' => 'GOWISER',
                'description' => 'The current GOWISER platform. Pick this for a branch running its own copy.',
                'is_system' => true,
                'definition' => ['datasets' => $this->syncDatasets()],
            ]
        );

        SchemaProfile::updateOrCreate(
            ['key' => 'netmanager'],
            [
                'label' => 'NetManager (legacy)',
                'description' => 'The older PHP system. Kept as a reference and for historical figures.',
                'is_system' => true,
                'definition' => ['datasets' => $this->netmanagerDatasets()],
            ]
        );

        $this->command->info('Seeded schema profiles: sync, netmanager.');
    }

    private function syncDatasets(): array
    {
        return [
            'payments' => [
                'table' => 'transactions',
                'alias' => 'src',
                'fields' => [
                    'amount' => 'received_payment',
                    'occurred_at' => 'date_processed',
                    'method' => 'payment_method',
                    'type' => 'transaction_type',
                    'status' => 'status',
                    'reference' => 'reference_no',
                    'account' => 'account_no',
                    'processed_by' => 'processed_by_user',
                ],
                'filters' => [
                    ['column' => 'date_processed', 'op' => 'not_null'],
                    ['column' => 'received_payment', 'op' => 'not_null'],
                    // Cancelled and still-pending rows are not collections.
                    // `nullable` lets rows with no status through.
                    [
                        'column' => 'status',
                        'op' => 'not_in_ci',
                        'value' => ['cancelled', 'pending', 'voided'],
                        'nullable' => true,
                    ],
                ],
            ],

            'expenses' => [
                'table' => 'expenses_logs',
                'alias' => 'src',
                'fields' => [
                    'amount' => 'amount',
                    'occurred_at' => 'date',
                    'category' => 'category',
                    'branch' => 'location',
                    'remark' => 'description',
                    'recorded_by' => 'processed_by',
                ],
                'filters' => [
                    ['column' => 'amount', 'op' => 'not_null'],
                ],
            ],

            'receivables' => [
                'table' => 'billing_accounts',
                'alias' => 'src',
                'fields' => [
                    'balance' => 'account_balance',
                    'account' => 'account_no',
                    'installed_at' => 'date_installed',
                ],
            ],

            'applications' => [
                'table' => 'applications',
                'alias' => 'src',
                'fields' => [
                    'occurred_at' => 'timestamp',
                    'status' => 'status',
                    'plan' => 'desired_plan',
                    'location' => 'city',
                ],
            ],

            'installs' => [
                'table' => 'job_orders',
                'alias' => 'src',
                'fields' => [
                    'occurred_at' => 'timestamp',
                    'status' => 'onsite_status',
                    'assigned_to' => 'username',
                ],
            ],

            'repairs' => [
                'table' => 'service_orders',
                'alias' => 'src',
                'fields' => [
                    'occurred_at' => 'timestamp',
                    'status' => 'support_status',
                    'visit_status' => 'visit_status',
                    'concern' => 'concern',
                    'category' => 'repair_category',
                ],
            ],

            'inventory' => [
                'table' => 'inventory_items',
                'alias' => 'src',
                // category_id is a foreign key; the dashboard needs the name.
                'joins' => [
                    [
                        'table' => 'inventory_category',
                        'alias' => 'icat',
                        'first' => 'src.category_id',
                        'second' => 'icat.id',
                        'type' => 'left',
                    ],
                ],
                'fields' => [
                    'item' => 'item_name',
                    'quantity' => 'total_quantity',
                    'category' => 'icat.category_name',
                ],
            ],

            'activity' => [
                'table' => 'activity_logs',
                'alias' => 'src',
                // user_id alone is unreadable in a report; join for the name.
                'joins' => [
                    [
                        'table' => 'users',
                        'alias' => 'actor',
                        'first' => 'src.user_id',
                        'second' => 'actor.id',
                        'type' => 'left',
                    ],
                ],
                'fields' => [
                    'occurred_at' => 'created_at',
                    'actor' => 'actor.username',
                    'action' => 'action',
                    'module' => 'resource_type',
                    'description' => 'message',
                    'subject' => 'resource_id',
                ],
            ],

            'subscribers' => [
                'table' => 'billing_accounts',
                'alias' => 'src',
                'joins' => [
                    [
                        'table' => 'billing_status',
                        'alias' => 'bstat',
                        'first' => 'src.billing_status_id',
                        'second' => 'bstat.id',
                        'type' => 'left',
                    ],
                ],
                'fields' => [
                    'account' => 'account_no',
                    'status' => 'bstat.status_name',
                    'plan' => 'plan_id',
                    'created_at' => 'date_installed',
                ],
            ],

            'sessions' => [
                'table' => 'online_status',
                'alias' => 'src',
                'fields' => [
                    'status' => 'session_status',
                    'account' => 'account_no',
                    'updated_at' => 'updated_at',
                ],
            ],
        ];
    }

    /**
     * The legacy system. Retained because its historical figures are the only
     * record for periods before SYNC, and because it is the reference the new
     * dashboards were modelled on.
     */
    private function netmanagerDatasets(): array
    {
        return [
            'payments' => [
                'table' => 'payments',
                'alias' => 'src',
                'fields' => [
                    'amount' => 'amount',
                    'occurred_at' => 'payment_date',
                    'method' => 'method',
                    'status' => 'status',
                    'reference' => 'reference_number',
                    'account' => 'subscriber_id',
                ],
                'filters' => [
                    ['column' => 'status', 'op' => 'eq', 'value' => 'paid'],
                ],
            ],

            'expenses' => [
                'table' => 'expenses',
                'alias' => 'src',
                'joins' => [
                    [
                        'table' => 'expense_types',
                        'alias' => 'etype',
                        'first' => 'src.expense_type_id',
                        'second' => 'etype.type_id',
                        'type' => 'left',
                    ],
                ],
                'fields' => [
                    'amount' => 'amount',
                    'occurred_at' => 'expense_date',
                    'category' => 'etype.name',
                    'branch' => 'router_id',
                    'period_type' => 'period_type',
                    'remark' => 'remark',
                ],
                // An expense booked against a longer horizon must not be
                // counted into a shorter view: a month's rent is not a Tuesday
                // expense. Without these, 2026-05-01 reads as a 3.85M loss
                // instead of 12,963.93. NULL means 'daily' in this schema,
                // hence nullable throughout.
                'filters' => [
                    [
                        'column' => 'period_type',
                        'op' => 'in',
                        'value' => ['daily'],
                        'nullable' => true,
                        'granularity' => ['daily', 'weekly'],
                    ],
                    [
                        'column' => 'period_type',
                        'op' => 'in',
                        'value' => ['daily', 'monthly'],
                        'nullable' => true,
                        'granularity' => ['monthly'],
                    ],
                ],
            ],

            'subscribers' => [
                'table' => 'subscribers',
                'alias' => 'src',
                'fields' => [
                    'account' => 'account_number',
                    'status' => 'status',
                    'plan' => 'plan_id',
                    'created_at' => 'created_at',
                ],
            ],

            'activity' => [
                'table' => 'activity_log',
                'alias' => 'src',
                'joins' => [
                    [
                        'table' => 'users',
                        'alias' => 'actor',
                        'first' => 'src.user_id',
                        'second' => 'actor.user_id',
                        'type' => 'left',
                    ],
                ],
                'fields' => [
                    'occurred_at' => 'logged_at',
                    'actor' => 'actor.username',
                    'action' => 'action',
                    'module' => 'module',
                    'description' => 'description',
                ],
            ],
        ];
    }
}
