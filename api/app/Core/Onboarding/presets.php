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
 *   keys and units, ONB-03).
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
            'modules' => ['pos', 'inventory', 'restaurant', 'manufacturing'],
            'quantities' => ['outlet' => 1, 'device' => 3, 'employee' => 12],
        ],
        [
            'key' => 'bar',
            'pos_mode' => 'bar',
            'role_templates' => ['admin', 'branch_manager', 'cashier', 'waiter', 'bartender'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'restaurant'],
            'quantities' => ['outlet' => 1, 'device' => 2, 'employee' => 8],
        ],
        [
            'key' => 'hotel',
            'pos_mode' => 'hotel',
            // HOT-33: the hotel module (rooms, bookings, folios, housekeeping, night audit) and the front-desk roles.
            'role_templates' => ['admin', 'branch_manager', 'receptionist', 'housekeeping', 'night_auditor', 'cashier', 'waiter', 'storekeeper', 'accountant'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'restaurant', 'stores', 'hotel', 'accounting_essentials'],
            'quantities' => ['outlet' => 2, 'device' => 4, 'employee' => 25],
        ],
        [
            'key' => 'pharmacy',
            'pos_mode' => 'pharmacy',
            // PHA-29: the pharmacy module (batches, expiry, prescriptions, the controlled-drug register) and its roles.
            'role_templates' => ['admin', 'branch_manager', 'pharmacist', 'pharmacy_technician', 'cashier', 'storekeeper'],
            'flows' => [],
            'modules' => ['pos', 'inventory', 'pharmacy', 'loyalty'],
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
        'sell' => ['pos', 'inventory', 'pharmacy', 'restaurant', 'hotel', 'loyalty', 'ecommerce'],
        'operate' => ['stores', 'procurement', 'manufacturing', 'fixed_assets'],
        'finance_people' => ['accounting_essentials', 'accounting_pro', 'crm', 'hr', 'payroll'],
    ],
];
