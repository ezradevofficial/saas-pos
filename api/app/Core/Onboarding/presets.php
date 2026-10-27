<?php

/*
 * ONB-02, ONB-03: onboarding presets, kept as data (no per-type code).
 *
 * Business types, in the order the picker shows them. Each one
 * pre-selects:
 * - `pos_mode`: the till's mode for the POS verticals (concept note 3),
 *   stored on the tenant (settings.pos_mode); null for no till;
 * - `role_templates`: the system role templates (RBAC-03) the business
 *   usually staffs, offered first in the invite step; every template is
 *   seeded at sign-up whatever the type;
 * - `flows`: document types whose default flow is adopted (published as
 *   version 1, WF-02) when the type is chosen; types not registered are
 *   skipped;
 * - `modules` and `quantities`: the module picker's suggestion (catalogue
 *   keys and units, ONB-03);
 * - `features` (optional): modules that are part of the till's mode rather
 *   than sold on their own (docs/modules/restaurant.md): they are switched
 *   on when the type is chosen and off when another type replaces it.
 *
 * Names and short descriptions are the translation keys
 * `onboarding.business_types.{key}.name|description` (en, fr).
 *
 * `module_groups`: how the picker groups catalogue modules (keys missing
 * here are shown under the last group); names are
 * `onboarding.module_groups.{key}`.
 */

return [
    'business_types' => [
        [
            'key' => 'retail',
            'pos_mode' => 'retail',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'loyalty'],
            'quantities' => ['outlet' => 1, 'device' => 2, 'employee' => 5],
        ],
        [
            'key' => 'supermarket',
            'pos_mode' => 'supermarket',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper', 'procurement_officer'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'loyalty', 'stores', 'procurement'],
            'quantities' => ['outlet' => 1, 'device' => 4, 'employee' => 15],
        ],
        [
            'key' => 'restaurant',
            'pos_mode' => 'restaurant',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'waiter', 'kitchen', 'storekeeper'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'manufacturing'],
            'quantities' => ['outlet' => 1, 'device' => 3, 'employee' => 12],
            'features' => ['restaurant'],
        ],
        [
            'key' => 'bar',
            'pos_mode' => 'bar',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'waiter', 'bartender'],
            'flows' => [],
            'modules' => ['pos', 'inventory'],
            'quantities' => ['outlet' => 1, 'device' => 2, 'employee' => 8],
            'features' => ['restaurant'],
        ],
        [
            'key' => 'hotel',
            'pos_mode' => 'hotel',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'waiter', 'storekeeper', 'accountant'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'stores', 'accounting_essentials'],
            'quantities' => ['outlet' => 2, 'device' => 4, 'employee' => 25],
        ],
        [
            'key' => 'pharmacy',
            'pos_mode' => 'pharmacy',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'loyalty'],
            'quantities' => ['outlet' => 1, 'device' => 2, 'employee' => 6],
        ],
        [
            'key' => 'wholesale',
            'pos_mode' => 'wholesale',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper', 'accountant', 'approver'],
            // WF-01: credit customers' limits change through a flow.
            'flows' => ['core.credit_limit_change'],
            'modules' => ['pos', 'inventory', 'stores', 'crm', 'accounting_essentials'],
            'quantities' => ['outlet' => 1, 'device' => 2, 'employee' => 15],
        ],
        [
            'key' => 'van_sales',
            'pos_mode' => 'van_sales',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper'],
            'flows' => ['core.credit_limit_change'],
            'modules' => ['pos', 'inventory', 'crm'],
            'quantities' => ['outlet' => 1, 'device' => 3, 'employee' => 8],
        ],
        [
            'key' => 'salon',
            'pos_mode' => 'salon',
            'role_templates' => ['admin', 'branch_manager', 'cashier'],
            'flows' => [],
            'modules' => ['pos', 'loyalty'],
            'quantities' => ['outlet' => 1, 'device' => 1, 'employee' => 6],
        ],
        [
            'key' => 'fuel_station',
            'pos_mode' => 'fuel_station',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'storekeeper', 'accountant'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'accounting_essentials'],
            'quantities' => ['outlet' => 1, 'device' => 4, 'employee' => 20],
        ],
        [
            'key' => 'non_pos',
            'pos_mode' => null,
            'role_templates' => ['admin', 'accountant', 'hr_officer', 'payroll_officer', 'procurement_officer', 'approver', 'employee_self_service'],
            'flows' => [],
            'modules' => ['accounting_essentials', 'hr', 'payroll'],
            'quantities' => ['employee' => 20],
        ],
    ],

    'module_groups' => [
        'sell' => ['pos', 'inventory', 'loyalty', 'ecommerce'],
        'operate' => ['stores', 'procurement', 'manufacturing', 'fixed_assets'],
        'finance_people' => ['accounting_essentials', 'accounting_pro', 'crm', 'hr', 'payroll'],
    ],
];
