<?php

/*
|--------------------------------------------------------------------------
| Canonical datasets
|--------------------------------------------------------------------------
|
| The vocabulary every schema profile maps onto. Dashboards ask for these
| names and never for a real table or column, which is what lets a new site
| be onboarded by filling in a form instead of editing code.
|
| Each dataset declares:
|   label     shown in the mapping admin
|   area      which dashboard consumes it
|   required  fields a profile must map for the dataset to be usable
|   optional  fields that enable extra breakdowns when present
|   measure   the numeric field aggregated by default (null = row counts)
|   date      the field periods are measured against
|
| Adding a field here makes it mappable everywhere; it does not force any
| existing profile to provide it.
|
*/

return [

    'payments' => [
        'label' => 'Payments / Collections',
        'area' => 'financials',
        'measure' => 'amount',
        'date' => 'occurred_at',
        'required' => ['amount', 'occurred_at'],
        'optional' => ['method', 'type', 'status', 'reference', 'account', 'channel', 'processed_by'],
        'description' => 'Money received. Drives income, collection trends and payment-method mix.',
    ],

    'expenses' => [
        'label' => 'Expenses',
        'area' => 'financials',
        'measure' => 'amount',
        'date' => 'occurred_at',
        'required' => ['amount', 'occurred_at'],
        'optional' => ['category', 'branch', 'period_type', 'remark', 'recorded_by'],
        'description' => 'Money spent. Without this a site can show collections but not net or margin.',
    ],

    'receivables' => [
        'label' => 'Receivables / Balances',
        'area' => 'financials',
        'measure' => 'balance',
        'date' => null,
        'required' => ['balance'],
        'optional' => ['account', 'customer', 'status', 'installed_at'],
        'description' => 'Outstanding balances per account. A snapshot, not a period.',
    ],

    'applications' => [
        'label' => 'Applications',
        'area' => 'operations',
        'measure' => null,
        'date' => 'occurred_at',
        'required' => ['occurred_at'],
        'optional' => ['status', 'plan', 'location', 'created_by'],
        'description' => 'New service applications. Front of the install funnel.',
    ],

    'installs' => [
        'label' => 'Installs / Job Orders',
        'area' => 'operations',
        'measure' => null,
        'date' => 'occurred_at',
        'required' => ['occurred_at'],
        'optional' => ['status', 'assigned_to', 'location', 'completed_at'],
        'description' => 'Onsite installation work.',
    ],

    'repairs' => [
        'label' => 'Repairs / Service Orders',
        'area' => 'operations',
        'measure' => null,
        'date' => 'occurred_at',
        'required' => ['occurred_at'],
        'optional' => ['status', 'visit_status', 'concern', 'category', 'assigned_to'],
        'description' => 'Support and repair visits.',
    ],

    'inventory' => [
        'label' => 'Inventory',
        'area' => 'operations',
        'measure' => 'quantity',
        'date' => null,
        'required' => ['item'],
        'optional' => ['quantity', 'category', 'status', 'location', 'unit_cost'],
        'description' => 'Stock on hand. A snapshot, not a period.',
    ],

    'activity' => [
        'label' => 'Employee Activity',
        'area' => 'employees',
        'measure' => null,
        'date' => 'occurred_at',
        'required' => ['occurred_at', 'actor'],
        'optional' => ['action', 'module', 'description', 'subject'],
        'description' => 'Who did what, and when. Drives the employee monitoring view.',
    ],

    'subscribers' => [
        'label' => 'Subscribers / Accounts',
        'area' => 'operations',
        'measure' => null,
        'date' => null,
        'required' => [],
        'optional' => ['status', 'plan', 'location', 'created_at', 'account'],
        'description' => 'Customer base, used for active counts and plan mix.',
    ],

    'sessions' => [
        'label' => 'Connection Status',
        'area' => 'operations',
        'measure' => null,
        'date' => null,
        'required' => ['status'],
        'optional' => ['account', 'updated_at'],
        'description' => 'Online / offline / disconnected counts from the network side.',
    ],

];
