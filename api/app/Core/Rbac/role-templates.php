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
            'core.company.view', 'core.branch.view', 'core.branch.edit', 'core.currency.view', 'core.exchange_rate.view',
            'core.tax.view', 'core.price_list.view', 'core.party.view', 'core.party.create',
            // WF-01: asks for credit limit changes (decided through their flow).
            'core.credit_limit.request',
            'core.item.view', 'core.item.create', 'core.item.edit', 'core.item_category.view', 'core.uom.view',
            'core.payment_method.view',
            'core.location.*', 'core.device.*',
            'core.user.view', 'core.user.invite', 'core.user.edit',
            'core.role.view', 'core.role.assign', 'core.audit.view',
            // APR-06: reassigns pending approvals at their branch (and is a manager there).
            'core.approval.reassign',
            'pos.*', 'inventory.*', 'sales.*', 'purchasing.*.view', 'reports.*.view', 'approvals.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'cashier',
        'permissions' => [
            'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view',
            // POS-01, POS-04, POS-07: sells, opens and closes their own shift, gives discounts within their limit.
            'pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close', 'pos.discount.give',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'waiter',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view',
            'pos.order.*', 'pos.table.*', 'pos.sale.view', 'pos.sale.print',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'storekeeper',
        'permissions' => [
            'core.location.view',
            'core.item.view', 'core.item.create', 'core.item.edit',
            'core.item_category.view', 'core.item_category.create', 'core.item_category.edit', 'core.uom.view',
            'inventory.*', 'purchasing.receipt.*', 'purchasing.order.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'accountant',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.audit.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.tax.view', 'core.price_list.view', 'core.party.view', 'core.party.create', 'core.party.edit',
            'core.credit_limit.request', 'core.credit_limit.approve',
            'core.payment_method.view', 'core.dimension.*',
            'accounting.*', 'sales.*.view', 'purchasing.*.view', 'pos.*.view', 'reports.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'hr_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.user.view', 'core.dimension.view',
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
            'core.party.view', 'core.party.create', 'core.party.edit',
            'core.item.view', 'core.item.create', 'core.item.edit', 'core.item_category.view', 'core.uom.view',
            'core.dimension.view',
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
