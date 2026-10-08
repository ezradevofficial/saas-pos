<?php

/*
 * RBAC-03: system role templates, seeded into every tenant as roles that
 * can be copied but not edited. The name is the translation key
 * `rbac.templates.{key}`, stored in the tenant's default locale.
 *
 * Permission patterns match catalogue names (`module.resource.action`) with
 * `*` as a wildcard: `*` (everything), `core.*` (a module), `*.view` (an
 * action in every module) or an exact name. Patterns for modules that are
 * not registered yet match nothing; system roles are refreshed when a
 * tenant activates a module (ModuleRegistry::activate).
 *
 * Two-factor is off for every template; tenant admins turn it on per role.
 */

return [
    [
        'key' => 'owner',
        'permissions' => ['*'],
        'is_owner' => true,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'admin',
        'permissions' => ['core.*'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'branch_manager',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.branch.edit', 'core.currency.view',
            'core.location.*', 'core.device.*',
            'core.user.view', 'core.user.invite', 'core.user.edit',
            'core.role.view', 'core.role.assign', 'core.audit.view',
            'pos.*', 'inventory.*', 'sales.*', 'purchasing.*.view', 'reports.*.view', 'approvals.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'cashier',
        'permissions' => [
            'core.location.view', 'core.device.view',
            'pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.shift.*', 'pos.customer.view', 'pos.customer.create',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'waiter',
        'permissions' => [
            'core.location.view',
            'pos.order.*', 'pos.table.*', 'pos.sale.view', 'pos.sale.print',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'storekeeper',
        'permissions' => [
            'core.location.view',
            'inventory.*', 'purchasing.receipt.*', 'purchasing.order.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'accountant',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.audit.view', 'core.currency.view',
            'accounting.*', 'sales.*.view', 'purchasing.*.view', 'pos.*.view', 'reports.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'hr_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.user.view',
            'hr.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'payroll_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.user.view',
            'payroll.*', 'hr.employee.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'procurement_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view',
            'purchasing.*', 'inventory.*.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'approver',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view',
            'approvals.*', '*.approve',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'employee_self_service',
        'permissions' => ['self_service.*'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'read_only_auditor',
        'permissions' => ['*.view', 'core.audit.*', 'core.access_review.*'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
];
