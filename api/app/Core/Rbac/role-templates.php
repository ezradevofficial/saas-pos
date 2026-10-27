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
            'stores.*.view',
            // HR-09: reads HR's records, never its sensitive groups (identity, bank, pay, medical, disciplinary).
            'hr.*.view',
            // MFG-02, MFG-29: keeps BOMs and recipes and a company's production rates; reads orders, reports and costs.
            'manufacturing.*.view', 'manufacturing.bom.manage', 'manufacturing.settings.edit',
            // Fixed assets (docs/modules/fixed-assets.md): reads it all, sets categories and a company's convention, prints labels.
            'fixed_assets.*.view', 'fixed_assets.settings.edit', 'fixed_assets.category.manage', 'fixed_assets.label.print',
            // CRM (docs/modules/crm.md): reads every CRM record at the branches it reaches and sets the module up.
            'crm.*.view', 'crm.settings.manage', 'crm.scope.branch',
            // E-commerce (docs/modules/ecommerce.md): reads it all, sets up stores and riders.
            'ecommerce.*.view', 'ecommerce.store.manage', 'ecommerce.rider.manage',
            // Restaurant (docs/modules/restaurant.md): reads it all, sets up the floor, stations, modifiers and service charge.
            'restaurant.*.view', 'restaurant.floor.manage', 'restaurant.station.manage', 'restaurant.modifier.manage', 'restaurant.settings.edit',
            // Van sales (docs/modules/van-sales.md): reads it all, sets up vans, routes and the company's GPS and credit settings.
            'van_sales.*.view', 'van_sales.van.manage', 'van_sales.route.manage', 'van_sales.settings.edit',
            // SAL-28: reads the salon's records, sets up the menu, staff, chairs, online booking, the company's fees and commission rules.
            'salon.*.view', 'salon.settings.edit', 'salon.service.manage', 'salon.staff.manage', 'salon.resource.manage', 'salon.online.manage', 'salon.commission.rule',
            // PHA-02, PHA-03: sets a company's pharmacy rules and items' pharmacy profiles, and reads the module's records except prescriptions (personal data).
            'pharmacy.settings.edit', 'pharmacy.item.configure', 'pharmacy.*.view',
            // HOT-02, HOT-03, HOT-06: sets a company's hotel rules, room types, rooms and rate plans, and reads the module's records (guest ID numbers are `hotel.guest.identity`, not a view).
            'hotel.settings.edit', 'hotel.room.manage', 'hotel.rate.manage', 'hotel.*.view',
            // CP-01: exempting the company from a pack component (payroll.statutory.exempt).
            'payroll.statutory.exempt'],
        // EXP-02: the full tenant export is the Owner's alone. ONB-02, ONB-03:
        // so are the business type and the modules (what the tenant pays for).
        // AUTH-11: and single sign-on (whoever controls the IdP signs in as anyone linked).
        'except' => ['core.tenant.export', 'core.onboarding.modules', 'core.sso.manage', 'hr.identity.view', 'hr.bank.view', 'hr.pay.view', 'hr.medical.view', 'hr.disciplinary.view', 'pharmacy.prescription.view'],
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
            // HR (docs/modules/hr.md): their staff's records (never the sensitive groups), leave approvals,
            // rosters and swaps, attendance, checklists and appraisals at their branch.
            'hr.employee.view', 'hr.position.view', 'hr.contract.view', 'hr.leave.view', 'hr.leave.request', 'hr.leave.approve',
            'hr.roster.*', 'hr.swap.approve', 'hr.attendance.view', 'hr.attendance.manage', 'hr.checklist.view', 'hr.appraisal.*',
            // Manufacturing (docs/modules/manufacturing.md, review fix 3, least privilege): production orders,
            // materials, reports and costs at their branch; BOMs read and exploded only (keeping BOMs and the
            // company's rates is company-level: Admin, Owner).
            'manufacturing.bom.view', 'manufacturing.order.*', 'manufacturing.material.*', 'manufacturing.production.*',
            'manufacturing.report.view', 'manufacturing.cost.view',
            // Fixed assets (docs/modules/fixed-assets.md): the branch's assets, transfers (the default approver, FA-14),
            // disposal requests, maintenance, labels, reports and values.
            'fixed_assets.asset.*', 'fixed_assets.transfer.*', 'fixed_assets.disposal.request', 'fixed_assets.maintenance.*',
            'fixed_assets.label.print', 'fixed_assets.report.view', 'fixed_assets.value.view',
            // CRM (docs/modules/crm.md): the branch's leads, opportunities, quotes (the default quote approver, CRM-21),
            // activities and targets, with the branch scope (CRM-08); the module's settings are the Admin's.
            'crm.*',
            // E-commerce (docs/modules/ecommerce.md): the branch's online orders, deliveries, riders, cash-ups,
            // WhatsApp inbox and store settings; riding is the Rider's.
            'ecommerce.*',
            // Restaurant (docs/modules/restaurant.md): the branch's floor, orders, tickets and reports; the service charge is the company's (Admin).
            'restaurant.*',
            // Van sales (docs/modules/van-sales.md): vans, routes, load-out, credit review and reconciliation at their branch (the default approver after the Route supervisor); the settings are the Admin's.
            'van_sales.*',
            // Pharmacy (docs/modules/pharmacy.md): lots, write-offs (the default approver), recalls, prescriptions, the register
            // and reports at their branch; authorising a restricted dispense is a pharmacist's, and the settings the Admin's.
            'pharmacy.*',
            // Hotel (docs/modules/hotel.md): the property's bookings, front desk, folios, housekeeping, night audit and reports; the settings are the Admin's.
            'hotel.*',
            // Salon (docs/modules/salon.md): the branch's diary, queue, clients, packages, commissions (approves batches, never their own) and reports; the settings and commission rules are the Admin's.
            'salon.*',
        ],
        'except' => ['crm.settings.manage', 'ecommerce.delivery.deliver', 'restaurant.settings.edit', 'van_sales.settings.edit', 'pharmacy.dispense.authorise', 'pharmacy.settings.edit', 'pharmacy.item.configure', 'hotel.settings.edit', 'salon.settings.edit', 'salon.commission.rule'],
        // RBAC-06 (owner decision A, 2026-10-16; defaults the owner may change): a Branch Manager discounts quotes
        // up to 10 % without approval (CRM-20), so their own discounted quotes do not all escalate, and refunds up
        // to 10,000 in the company's base currency (POS-05, E-commerce refunds), so a manager can refund at all.
        // The till discount keeps no default (no rule: discounts need a manager's override, POS-07).
        'limits' => ['max_quote_discount_percent' => '10', 'max_refund_amount' => '10000'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // Owner decision B (2026-10-15, docs/modules/crm.md): a sales rep works their own leads, opportunities,
        // quotes and activities (no team or branch scope, CRM-08). Quote discounts stay within the role's
        // max_quote_discount_percent, seeded at 0 % (tenants raise it on a copy of the role, RBAC-02); above it
        // the quote goes for approval.
        // Sales managers use the Branch Manager role (crm.scope.branch).
        'key' => 'sales_rep',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.currency.view', 'core.exchange_rate.view', 'core.tax.view', 'core.price_list.view', 'core.price.view',
            'core.party.view', 'core.party.create', 'core.item.view', 'core.item_category.view', 'core.uom.view',
            'crm.lead.view', 'crm.lead.create', 'crm.lead.edit', 'crm.lead.convert', 'crm.lead.archive',
            'crm.opportunity.view', 'crm.opportunity.create', 'crm.opportunity.edit', 'crm.opportunity.close',
            'crm.quote.view', 'crm.quote.create', 'crm.quote.discount', 'crm.quote.send', 'crm.quote.cancel',
            'crm.activity.view', 'crm.activity.manage', 'crm.target.view',
        ],
        'limits' => ['max_quote_discount_percent' => '0'],
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
            // ECOM-22, ECOM-25: packs online orders at the outlet, takes phone orders, answers WhatsApp.
            'ecommerce.order.view', 'ecommerce.order.create', 'ecommerce.order.manage', 'ecommerce.whatsapp.view', 'ecommerce.whatsapp.reply',
            // PHA-14: takes down a prescription with the sale; reading them is the pharmacy team's.
            'pharmacy.prescription.create',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // ECOM-28 (docs/modules/ecommerce.md decision 7): a rider sees and updates only their own deliveries,
        // from the mobile app, signed in as themselves: status, location (ECOM-29) and proof photo (ECOM-28).
        // RBAC-03 lists it (added). Reading riders' positions is ecommerce.delivery.track, not the rider's.
        'key' => 'rider',
        'permissions' => ['ecommerce.delivery.deliver'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // REST-19 (docs/modules/restaurant.md): takes orders at tables and tabs, sends them, moves tables to a colleague,
        // and rings the bill of a table or tab at the till (a sale carrying extensions.restaurant; no general pos.sale.create).
        // Merging tables is a manager's, and only the kitchen starts and finishes tickets (a waiter marks them served); voiding a sent item needs a manager
        // (no pos.sale.void, no limit); discounts too.
        'key' => 'waiter',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view',
            'pos.sale.view', 'pos.sale.print', 'pos.till.sign_in',
            'restaurant.floor.view', 'restaurant.station.view', 'restaurant.modifier.view',
            'restaurant.order.view', 'restaurant.order.create', 'restaurant.order.edit', 'restaurant.order.send',
            'restaurant.order.transfer', 'restaurant.order.close',
            'restaurant.ticket.view', 'restaurant.ticket.serve',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // REST-19: the kitchen display only: sees and moves tickets, never money or orders.
        'key' => 'kitchen',
        'permissions' => [
            'core.location.view', 'core.item.view', 'core.item_category.view', 'core.uom.view',
            'restaurant.floor.view', 'restaurant.station.view', 'restaurant.ticket.view', 'restaurant.ticket.update',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // REST-19: runs the bar: tabs and rounds like a waiter, serves the bar station's tickets and works its own shift.
        'key' => 'bartender',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view',
            'pos.sale.view', 'pos.sale.print', 'pos.till.sign_in', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            'restaurant.floor.view', 'restaurant.station.view', 'restaurant.modifier.view',
            'restaurant.order.view', 'restaurant.order.create', 'restaurant.order.edit', 'restaurant.order.send',
            'restaurant.order.transfer', 'restaurant.order.close',
            'restaurant.ticket.view', 'restaurant.ticket.update', 'restaurant.ticket.serve',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // VAN-26 (docs/modules/van-sales.md): the rep of a van works the day from the van's device: confirms the load,
        // visits and sells (cash, M-Pesa, credit, returns through POS), collects balances and counts the van. Reaches
        // only the van's location; no prices, discounts or voids beyond their limits.
        'key' => 'van_rep',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view',
            'pos.sale.view', 'pos.sale.print', 'pos.sale.create', 'pos.sale.refund', 'pos.till.sign_in', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            'van_sales.van.view', 'van_sales.route.view', 'van_sales.load.view', 'van_sales.load.confirm',
            'van_sales.run.view', 'van_sales.run.start', 'van_sales.visit.record',
            'van_sales.collection.view', 'van_sales.collection.record',
            'van_sales.reconciliation.view', 'van_sales.reconciliation.submit',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // VAN-26, VAN-21: sets up the branch's vans and routes, requests and releases loads, reviews flagged credit
        // and decides reconciliations (never their own count, APR-07), receives unloads and reads the reports.
        'key' => 'route_supervisor',
        'permissions' => [
            'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create', 'core.user.view',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view',
            'pos.sale.view', 'pos.shift.view',
            'van_sales.van.view', 'van_sales.van.manage', 'van_sales.route.view', 'van_sales.route.manage',
            // Loads are requested here and released by the storekeeper at the warehouse (VAN-07); money given back is within the limit below (RBAC-06).
            'van_sales.load.view', 'van_sales.load.create',
            'van_sales.run.view', 'van_sales.visit.track', 'van_sales.collection.view', 'van_sales.collection.void',
            'van_sales.credit.view', 'van_sales.credit.review',
            'van_sales.reconciliation.view', 'van_sales.reconciliation.approve', 'van_sales.unload.receive', 'van_sales.report.view',
        ],
        'limits' => ['max_refund_amount' => '10000'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // SAL-28 (docs/modules/salon.md): a stylist, barber or therapist works their own diary from the till: sees their appointments,
        // checks clients in, starts and finishes services, reads and writes their clients' allergies and formulas, books walk-ins and sells.
        // No refunds, fees, deposits or other people's pay.
        'key' => 'stylist',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.currency.view', 'core.exchange_rate.view', 'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view', 'core.tax.view',
            'pos.sale.view', 'pos.sale.print', 'pos.sale.create', 'pos.till.sign_in', 'pos.shift.view',
            'salon.service.view', 'salon.staff.view', 'salon.resource.view', 'salon.appointment.view_own', 'salon.appointment.create', 'salon.appointment.serve',
            'salon.queue.view', 'salon.client.view', 'salon.client.manage', 'salon.client.notes', 'salon.package.view', 'salon.commission.view_own',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // SAL-28: runs the salon: everything in the diary, queue, clients, packages, deposits, fees, exceptions, commissions (builds and approves
        // batches, never their own, SAL-23) and reports. Not the company's settings or commission rules (the Admin's).
        'key' => 'salon_manager',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view', 'core.user.view',
            'core.party.view', 'core.party.create', 'core.party.edit', 'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.price.view', 'core.payment_method.view', 'core.tax.view',
            'pos.sale.view', 'pos.sale.print', 'pos.sale.create', 'pos.sale.refund', 'pos.till.sign_in', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            'salon.*',
        ],
        'except' => ['salon.settings.edit', 'salon.commission.rule'],
        'limits' => ['max_refund_amount' => '10000'],
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
            // MFG-12: issues materials to production orders and takes back what is unused; quantities only (RBAC-05).
            'manufacturing.bom.view', 'manufacturing.order.view', 'manufacturing.material.*',
            // PHA-06, PHA-12, PHA-13: receives and moves lots and asks for write-offs; never quarantines, recalls or approves.
            'pharmacy.item.view', 'pharmacy.lot.view', 'pharmacy.lot.receive', 'pharmacy.lot.transfer', 'pharmacy.writeoff.view', 'pharmacy.writeoff.create', 'pharmacy.report.view',
            // VAN-07, VAN-23: releases the load sheets of vans from the warehouse and receives the unloads.
            'van_sales.load.view', 'van_sales.load.release', 'van_sales.unload.receive',
        ],
        // INV-08 to INV-10, INV-12: storekeepers request; managers and approvers decide.
        // Costs belong to Accountants and Admins: storekeepers see quantities only (RBAC-05).
        // STR-03, STR-05: departments approve and acknowledge what the store issues, not the storekeeper.
        'except' => ['inventory.transfer.approve', 'inventory.adjustment.approve', 'inventory.count.approve', 'inventory.settings.edit', 'inventory.valuation.view',
            'stores.requisition.approve', 'stores.issue.acknowledge', 'stores.value.view', 'stores.stock.approve'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // PHA-17, PHA-29: the pharmacist sells and authorises restricted dispensing at their place; everything in
        // the module except the company's settings. A 6-digit PIN is needed (config sync.override_permissions).
        'key' => 'pharmacist',
        'permissions' => [
            'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view', 'core.price.view',
            'pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            'pos.till.sign_in',
            'inventory.stock.view', 'loyalty.points.view', 'loyalty.gift_card.view',
            'pharmacy.*',
        ],
        'except' => ['pharmacy.settings.edit'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // PHA-29: the technician sells, receives and moves stock and takes down prescriptions; they do not authorise
        // restricted dispensing, quarantine, issue recalls or correct the register.
        'key' => 'pharmacy_technician',
        'permissions' => [
            'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create',
            'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view', 'core.price.view',
            'pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.shift.view', 'pos.shift.open', 'pos.shift.close',
            'pos.till.sign_in',
            'inventory.stock.view', 'loyalty.points.view', 'loyalty.gift_card.view',
            'pharmacy.item.view', 'pharmacy.lot.view', 'pharmacy.lot.receive', 'pharmacy.lot.transfer',
            'pharmacy.writeoff.view', 'pharmacy.writeoff.create',
            'pharmacy.prescription.view', 'pharmacy.prescription.create',
            'pharmacy.recall.view', 'pharmacy.register.view', 'pharmacy.report.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // HOT-33: the front desk. Bookings, check-in and out, folios (charges, payments, transfers, refunds within the role's refund limit, RBAC-06);
        // not rates, voids, fee waivers, leaving a balance on account, the night audit or the settings. Sees guests' ID numbers masked and may reveal them (audited, HOT-38).
        'key' => 'receptionist',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create', 'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view', 'core.price.view', 'core.tax.view',
            'hotel.room.view', 'hotel.rate.view', 'hotel.guest.view', 'hotel.guest.manage', 'hotel.guest.identity',
            'hotel.reservation.*', 'hotel.stay.checkin', 'hotel.stay.checkout', 'hotel.stay.move',
            'hotel.folio.view', 'hotel.folio.charge', 'hotel.folio.payment', 'hotel.folio.transfer', 'hotel.folio.refund',
            'hotel.housekeeping.view', 'hotel.report.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // SAL-28: the salon's front desk (the Hotel receptionist stays as it was). Books, checks clients in, runs the queue, takes deposits and rings the
        // visit at the till; clients' notes (allergies, formulas), refunds, fee waivers and commissions are not theirs.
        'key' => 'salon_receptionist',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.device.view', 'core.currency.view', 'core.exchange_rate.view',
            'core.party.view', 'core.party.create', 'core.item.view', 'core.item_category.view', 'core.uom.view', 'core.payment_method.view', 'core.price.view', 'core.tax.view',
            'pos.sale.view', 'pos.sale.print', 'pos.sale.create', 'pos.till.sign_in', 'pos.shift.view',
            'salon.service.view', 'salon.staff.view', 'salon.resource.view', 'salon.online.view', 'salon.appointment.view', 'salon.appointment.create', 'salon.appointment.edit',
            'salon.appointment.cancel', 'salon.appointment.serve', 'salon.queue.*', 'salon.client.view', 'salon.client.manage', 'salon.package.view', 'salon.deposit.take', 'salon.exception.view',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // HOT-29: a housekeeper sees rooms and their tasks and marks rooms clean or dirty, from the phone, signed in as themselves. No guest data.
        'key' => 'housekeeping',
        'permissions' => ['core.branch.view', 'core.location.view', 'hotel.room.view', 'hotel.housekeeping.view', 'hotel.housekeeping.update'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        // HOT-30: the night auditor runs the audit, reads folios and reports, marks no-shows and resolves room charges that could not be posted.
        'key' => 'night_auditor',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view',
            'hotel.room.view', 'hotel.guest.view', 'hotel.reservation.view', 'hotel.reservation.cancel', 'hotel.folio.view', 'hotel.charge.resolve',
            'hotel.housekeeping.view', 'hotel.audit.view', 'hotel.audit.run', 'hotel.report.view',
        ],
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
            // CRM-24: reads quotes and turns accepted ones into draft invoices.
            'crm.quote.view', 'crm.quote.convert', 'crm.scope.branch',
            // LOY-14..LOY-18: customer credit (accounts, payments, adjustments, statements, ageing).
            'loyalty.credit.view', 'loyalty.credit.manage', 'loyalty.credit.receive', 'loyalty.credit.adjust', 'loyalty.*.view',
            // INV-20: stock, its value and the valuation report.
            'inventory.*.view',
            // STR-12: Stores' documents, ledger, consumption and values.
            'stores.*.view',
            // MFG-16, MFG-18, MFG-19: production orders, their costs and the consumption and variance reports.
            'manufacturing.*.view',
            // Fixed assets (docs/modules/fixed-assets.md): the register, categories, runs, revaluations, disposals and the tax book.
            'fixed_assets.*',
            // ECOM-30, ECOM-32, ECOM-36: online orders, rider cash-ups and reports.
            'ecommerce.order.view', 'ecommerce.cashup.view', 'ecommerce.cashup.manage', 'ecommerce.report.view',
            // PHA-12, PHA-24: lots, write-offs and the expiry and slow-mover reports.
            'pharmacy.item.view', 'pharmacy.lot.view', 'pharmacy.writeoff.view', 'pharmacy.report.view',
            // HOT-20, HOT-27, HOT-31: folios, the night audits behind the room revenue, and the reports.
            'hotel.room.view', 'hotel.rate.view', 'hotel.folio.view', 'hotel.audit.view', 'hotel.report.view',
        ],
        // FA-14, FA-17: transfers and disposals are approved by others.
        'except' => ['fixed_assets.transfer.approve', 'fixed_assets.disposal.approve'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'hr_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.location.view', 'core.user.view', 'core.dimension.view',
            // CUR-01, HR-04, HR-07: the tenant's currencies, for contract pay and pay accounts.
            'core.currency.view',
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
            // IMP-01, HR-34: imports employees (hr.employee.create|edit).
            'core.import.run',
            'hr.*',
        ],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
    [
        'key' => 'payroll_officer',
        'permissions' => [
            'core.company.view', 'core.branch.view', 'core.user.view',
            // CUR-01: the tenant's currencies, for pay currency, pay items and loans.
            'core.currency.view',
            // LAY-06: sees layouts and keeps personal copies (dashboards, list views).
            'core.config.view',
            // PAY-01 to PAY-28 (docs/modules/payroll.md); maker-checker: APR-07 keeps the preparer off their own run's approval.
            'payroll.*', 'hr.employee.view',
            // HR-33: what payroll pays from: contracts and pay, pay accounts and identifiers, leave and attendance.
            'hr.contract.view', 'hr.pay.view', 'hr.bank.view', 'hr.identity.view', 'hr.leave.view', 'hr.attendance.view',
        ],
        // CP-01: exempting the company from a pack component is the Owner's and Admin's (payroll.statutory.exempt).
        'except' => ['payroll.statutory.exempt'],
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
            // HR-14, HR-19: the leave and shift swaps they approve.
            'hr.leave.view', 'hr.roster.view',
            // MFG-11: the production orders whose release they approve.
            'manufacturing.order.view',
            // FA-14, FA-17: the assets whose transfers and disposals they approve, with their values (RBAC-05).
            'fixed_assets.asset.view', 'fixed_assets.value.view',
            // CRM-21 (stage 7D closing review): the quotes they approve, at the branches they approve for, not only their own (CRM-08).
            'crm.quote.view', 'crm.scope.branch',
            // PAY-16: the payroll runs they approve, by their totals, department totals and variances
            // (payroll.md decision 36: per-employee lines need payroll.pay.view; field rules may hide the totals, PAY-23).
            'payroll.run.view',
            // PHA-12: the write-offs they approve.
            'pharmacy.writeoff.view',
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
        // CRM-08: an auditor reads every CRM record, not only their own.
        'permissions' => ['*.view', 'core.audit.*', 'core.access_review.*', 'crm.scope.branch'],
        // HR-09: personal data is not an auditor's to read; nor is pay (PAY-23: payroll settings only).
        'except' => ['hr.identity.view', 'hr.bank.view', 'hr.pay.view', 'hr.medical.view', 'hr.disciplinary.view',
            'payroll.employee.view', 'payroll.loan.view', 'payroll.run.view', 'payroll.pay.view', 'payroll.payslip.view', 'payroll.return.view',
            // PHA-28: patients' prescriptions are personal data.
            'pharmacy.prescription.view'],
        'is_owner' => false,
        'requires_two_factor' => false,
    ],
];
