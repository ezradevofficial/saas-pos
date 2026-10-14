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
 * `except` (optional) removes exact names the patterns matched.
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
        // TPL-04: shares POS documents by email and link (pos.sale.share).
        // LAY-05: designs the tills' sell screen once the POS module is active.
        // INV-12, INV-01: sets a company's costing and items' tracking, and reads stock and its value.
        // INV-11, INV-21: sets reorder levels and prints barcode labels.
        // LOY-01, LOY-08: sets up the loyalty programme and promotions once the module is active.
        'permissions' => ['core.*', 'pos.sale.share', 'pos.layout.*', 'inventory.settings.edit', 'inventory.item.configure', 'inventory.*.view', 'inventory.label.print', 'inventory.reorder.edit', 'loyalty.programme.*', 'loyalty.promotion.*',
            // PRC-01: a company's procurement tolerances, and the procurement views.
            'procurement.settings.edit', 'procurement.*.view',
            // STR-10, STR-12: reads Stores' documents, ledger, consumption and values.
            'stores.*.view'],
        // EXP-02: the full tenant export is the Owner's alone. ONB-02, ONB-03:
        // so are the business type and the modules (what the tenant pays for).
        'except' => ['core.tenant.export', 'core.onboarding.modules'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'branch_manager',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.branch.edit', 'core.currency.view', 'core.exchange_rate.view',
            'core.tax.view', 'core.price_list.view', 'core.price.view', 'core.party.view', 'core.party.create',
            // WF-01: asks for credit limit changes (decided through their flow).
            'core.credit_limit.request',
            'core.item.view', 'core.item.create', 'core.item.edit', 'core.item_category.view', 'core.uom.view',
            'core.payment_method.view', 'core.payment.view', 'core.fiscal.view',
            'core.location.*', 'core.device.*',
            'core.user.view', 'core.user.invite', 'core.user.edit',
            'core.role.view', 'core.role.assign', 'core.audit.view',
            // APR-06: reassigns pending approvals at their branch (and is a manager there).
            'core.approval.reassign',
            // LAY-06: sees the layouts and themes that apply at their branch.
            'core.config.view',
            // CF-04: fills in, changes and approves the organisation's custom forms at their branch.
            'core.custom_form.view', 'core.custom_form.create', 'core.custom_form.edit', 'core.custom_form.approve',
            // TPL-01: sees the document templates that apply at their branch.
            'core.template.view',
            'pos.*', 'inventory.*', 'sales.*', 'reports.*.view', 'approvals.*',
            // Procurement (docs/modules/procurement.md): reads it all, raises requisitions, approves
            // requisitions and POs (the default approver, PRC-02, PRC-13) and sits on tender committees (PRC-10, PRC-11).
            'procurement.*.view', 'procurement.requisition.*', 'procurement.po.approve', 'procurement.tender.open', 'procurement.tender.evaluate',
            // Loyalty (docs/modules/loyalty.md): promotions, points, credit and gift cards at their branch.
            'loyalty.*',
            // Stores (docs/modules/stores.md): requisitions, issues, returns and reports at their branch.
            'stores.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'cashier',
        'permissions' => [
            'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view', 'core.price.view',
            // POS-01, POS-04: sells, opens and closes their own shift. Discounts need a manager
            // (pos.discount.give approves overrides, so its holders need 6-digit PINs, AUTH-08).
            'pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            // AUTH-06: signs in at the tills where the role is held.
            'pos.till.sign_in',
            // LOY-05, LOY-23: sees customers' points and gift card balances at the till. Selling on
            // credit (loyalty.credit.sell) is granted by the tenant, not by this template.
            'loyalty.points.view', 'loyalty.gift_card.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'waiter',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view',
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
            'inventory.*',
            // PRC-14: receives goods against POs and asks for what the store needs (PRC-02).
            // RBAC-05 (procurement.md decision 27): quantities only, never procurement.value.view.
            'procurement.receipt.*', 'procurement.po.view', 'procurement.requisition.view', 'procurement.requisition.create',
            // STR-02 to STR-12: requisitions, issues, returns, the store ledger and consumption.
            'stores.*',
        ],
        // INV-08 to INV-10, INV-12: storekeepers request; managers and approvers decide.
        // Costs belong to Accountants and Admins: storekeepers see quantities only (RBAC-05).
        // STR-03, STR-05: departments approve and acknowledge what the store issues, not the storekeeper.
        'except' => ['inventory.transfer.approve', 'inventory.adjustment.approve', 'inventory.count.approve', 'inventory.settings.edit', 'inventory.valuation.view',
            'stores.requisition.approve', 'stores.issue.acknowledge', 'stores.value.view'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'accountant',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.audit.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.tax.view', 'core.price_list.view', 'core.price.view', 'core.party.view', 'core.party.create', 'core.party.edit',
            'core.credit_limit.request', 'core.credit_limit.approve',
            'core.payment_method.view', 'core.dimension.*', 'core.payment.*', 'core.fiscal.view', 'core.fiscal.edit',
            // CF-04: fills in and approves custom forms (petty cash and the like).
            'core.custom_form.view', 'core.custom_form.create', 'core.custom_form.approve',
            // IMP-01: imports customers and suppliers (core.party.create|edit).
            'core.import.run',
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
            'core.template.view',
            'accounting.*', 'sales.*.view', 'pos.*.view', 'reports.*',
            // PRC-15, PRC-17: supplier invoices (match, approval, exceptions) and landed costs; reads procurement.
            'procurement.*.view', 'procurement.invoice.*', 'procurement.landed_cost.*',
            // LOY-14..LOY-18: customer credit (accounts, payments, adjustments, statements, ageing).
            'loyalty.credit.view', 'loyalty.credit.manage', 'loyalty.credit.receive', 'loyalty.credit.adjust', 'loyalty.*.view',
            // INV-20: stock, its value and the valuation report.
            'inventory.*.view',
            // STR-12: Stores' documents, ledger, consumption and values.
            'stores.*.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'hr_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.user.view', 'core.dimension.view',
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
            'hr.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'payroll_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.user.view',
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
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
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
            // Procurement (docs/modules/procurement.md): runs requisitions, RFQs, tenders, POs, GRNs,
            // invoices and landed costs; decisions belong to others (except below).
            'procurement.*',
            // INV-11, INV-19: stock, reorder levels and stock documents; never costs (RBAC-05: no inventory.valuation.view).
            'inventory.stock.view', 'inventory.reorder.view', 'inventory.transfer.view', 'inventory.adjustment.view', 'inventory.count.view',
            // STR-02, STR-10, STR-12: what departments ask stores for and consume; never values.
            'stores.requisition.view', 'stores.ledger.view', 'stores.report.view',
        ],
        // PRC-02, PRC-11, PRC-13, PRC-15, PRC-01: buyers do not approve, award, approve invoice exceptions or set tolerances.
        'except' => [
            'procurement.requisition.approve', 'procurement.po.approve', 'procurement.invoice.approve', 'procurement.invoice.override',
            'procurement.tender.award', 'procurement.settings.edit',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'approver',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view',
            'approvals.*', '*.approve',
            // What they approve, they can read: journals (ACC-04) and stock documents (INV-08..INV-10); never stock values.
            'accounting.journal.view', 'inventory.transfer.view', 'inventory.adjustment.view', 'inventory.count.view',
            // PRC-02, PRC-13, PRC-15: the requisitions, POs and supplier invoices they approve, with their values (RBAC-05).
            'procurement.requisition.view', 'procurement.po.view', 'procurement.invoice.view', 'procurement.value.view',
            // STR-03: the requisitions they approve.
            'stores.requisition.view',
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
