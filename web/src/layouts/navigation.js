import { mergeGrouped } from '@/lib/catalogueMerge'

/**
 * Navigation (RBAC-09): an item with `permission` (a name, or a list of
 * which any one is enough) shows only when the user has it somewhere (with
 * `tenantWide`, only at tenant scope); an item with `module` only while that
 * module is active; an item with `needsCompany` (a page that works on one
 * company) only when the user can view a company, so a cashier scoped to a
 * location is not offered a page that would open empty (RBAC-04, RBAC-09).
 * Overview, Catalogue (items, categories, units), Contacts (customers,
 * suppliers, credit limit changes), Settings, Finance (currencies, rates, taxes, payment methods)
 * Workspace (approvals), Automation (workflows, automation rules) and Master data; later modules add their groups here.
 * Point of sale (POS module): dashboard, sales, shifts and held records.
 * Inventory (Inventory module): stock, movements, transfers, counts, adjustments, reorder levels, valuation, labels and settings.
 * Sales (CRM module): leads, opportunities, quotes, calendar, targets and settings.
 * Procurement (Procurement module): requisitions, RFQs, tenders, POs, goods received, supplier invoices, landed costs, supplier performance and settings.
 * Manufacturing (Manufacturing module): BOMs and recipes, production orders, consumption, yield, backflushes, recipe costing and settings.
 * Payroll (Payroll module): runs, employees' pay setup, loans, payslips, statutory returns and settings; My payslips in self-service.
 */
// A user who sees any level of the organisation (a cashier sees their
// location) gets the Organisation page, filtered to their scope (RBAC-04).
export const ORGANISATION_VIEW = ['core.company.view', 'core.branch.view', 'core.location.view']

// Phase 2 settings: who may read each page (any one is enough); the API checks again.
export const EXCHANGE_RATE_VIEW = ['core.exchange_rate.view', 'core.exchange_rate.override']
export const TAX_VIEW = ['core.tax.view', 'core.tax.edit']
export const PAYMENT_METHOD_VIEW = ['core.payment_method.view', 'core.payment_method.create', 'core.payment_method.edit', 'core.payment_method.archive']
export const DIMENSION_VIEW = ['core.dimension.view', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.archive']

// MD-01, MD-02: who may read the catalogue and contacts (the API checks again).
export const ITEM_VIEW = 'core.item.view'
export const CATEGORY_VIEW = 'core.item_category.view'
export const UOM_VIEW = ['core.uom.view', 'core.uom.edit']
export const PARTY_VIEW = 'core.party.view'

// WF-02, spec 6.4: who may open the workflows (any one is enough; the API checks again).
export const WORKFLOW_VIEW = ['core.workflow.view', 'core.workflow.edit', 'core.workflow.publish']
// NOT-03, NOT-06: the organisation's notification texts and delivery log (tenant-wide).
export const NOTIFICATION_TEMPLATE_VIEW = ['core.notification_template.view', 'core.notification_template.edit']
export const NOTIFICATION_DELIVERY_VIEW = 'core.notification_delivery.view'
// AUTO-01..AUTO-07: who may open the automation rules and their run log (the API checks again).
export const AUTOMATION_VIEW = ['core.automation.view', 'core.automation.edit']

// POS module (docs/modules/pos.md): who may open each page (any one is enough; the API checks again).
export const POS_SALE_VIEW = 'pos.sale.view'
export const POS_SHIFT_VIEW = 'pos.shift.view'
export const POS_HELD_VIEW = ['pos.sale.void', 'pos.sale.refund', 'pos.cash.move']
// Inventory module (docs/modules/inventory.md, RBAC-09): who may open each page (any one is enough; the API checks again).
export const INVENTORY_STOCK_VIEW = 'inventory.stock.view'
export const INVENTORY_TRANSFER_VIEW = 'inventory.transfer.view'
export const INVENTORY_COUNT_VIEW = 'inventory.count.view'
export const INVENTORY_ADJUSTMENT_VIEW = 'inventory.adjustment.view'
export const INVENTORY_REORDER_VIEW = ['inventory.reorder.view', 'inventory.reorder.edit']
export const INVENTORY_VALUATION_VIEW = 'inventory.valuation.view'
export const INVENTORY_LABEL_PRINT = 'inventory.label.print'
export const INVENTORY_SETTINGS_VIEW = ['inventory.stock.view', 'inventory.settings.edit']
// Procurement module (docs/modules/procurement.md, Screens; RBAC-09): who may open each page (the API checks again, PRC-22).
export const PROCUREMENT_REQUISITION_VIEW = 'procurement.requisition.view'
export const PROCUREMENT_RFQ_VIEW = 'procurement.rfq.view'
export const PROCUREMENT_TENDER_VIEW = 'procurement.tender.view'
export const PROCUREMENT_PO_VIEW = 'procurement.po.view'
export const PROCUREMENT_RECEIPT_VIEW = 'procurement.receipt.view'
export const PROCUREMENT_INVOICE_VIEW = 'procurement.invoice.view'
export const PROCUREMENT_LANDED_COST_VIEW = 'procurement.landed_cost.view'
export const PROCUREMENT_PERFORMANCE_VIEW = 'procurement.performance.view'
export const PROCUREMENT_SETTINGS_VIEW = ['procurement.po.view', 'procurement.invoice.view', 'procurement.settings.edit']

// Stores module (docs/modules/stores.md, RBAC-09): who may open each page (the API checks again at each store).
export const STORES_REQUISITION_VIEW = 'stores.requisition.view'
export const STORES_ISSUE_VIEW = 'stores.issue.view'
export const STORES_RETURN_VIEW = 'stores.return.view'
export const STORES_LEDGER_VIEW = 'stores.ledger.view'
export const STORES_REPORT_VIEW = 'stores.report.view'
// Manufacturing module (docs/modules/manufacturing.md, Screens; RBAC-09): who may open each page (the API checks again at each place).
export const MANUFACTURING_BOM_VIEW = 'manufacturing.bom.view'
export const MANUFACTURING_ORDER_VIEW = 'manufacturing.order.view'
export const MANUFACTURING_REPORT_VIEW = 'manufacturing.report.view'
export const MANUFACTURING_SETTINGS_VIEW = ['manufacturing.settings.edit', 'manufacturing.bom.view', 'manufacturing.order.view']
// HR module (docs/modules/hr.md, Screens; RBAC-09): who may open each page (the API checks again at the employee's place).
export const HR_EMPLOYEE_VIEW = 'hr.employee.view'
export const HR_POSITION_VIEW = ['hr.position.view', 'hr.position.manage']
export const HR_LEAVE_VIEW = 'hr.leave.view'
export const HR_ROSTER_VIEW = ['hr.roster.view', 'hr.roster.manage']
export const HR_ATTENDANCE_VIEW = ['hr.attendance.view', 'hr.device.manage']
export const HR_CHECKLIST_VIEW = 'hr.checklist.view'
export const HR_DISCIPLINARY_VIEW = 'hr.disciplinary.view'
export const HR_APPRAISAL_VIEW = 'hr.appraisal.view'
// Fixed assets module (docs/modules/fixed-assets.md, Screens; RBAC-09): who may open each page (the API checks again at each asset's place).
export const FA_ASSET_VIEW = 'fixed_assets.asset.view'
export const FA_DEPRECIATION_VIEW = ['fixed_assets.depreciation.view', 'fixed_assets.depreciation.post']
export const FA_MAINTENANCE_VIEW = 'fixed_assets.maintenance.view'
export const FA_LABEL_PRINT = 'fixed_assets.label.print'
export const FA_REPORT_VIEW = ['fixed_assets.report.view', 'fixed_assets.tax.view']
export const FA_SETTINGS_VIEW = ['fixed_assets.asset.view', 'fixed_assets.settings.edit']
// Payroll module (docs/modules/payroll.md, Screens; RBAC-09): who may open each page (the API checks again, PAY-27).
export const PAYROLL_RUN_VIEW = 'payroll.run.view'
export const PAYROLL_EMPLOYEE_VIEW = 'payroll.employee.view'
export const PAYROLL_LOAN_VIEW = 'payroll.loan.view'
export const PAYROLL_PAYSLIP_VIEW = 'payroll.payslip.view'
export const PAYROLL_RETURN_VIEW = ['payroll.return.view', 'payroll.return.generate']
export const PAYROLL_SETTINGS_VIEW = ['payroll.settings.view', 'payroll.settings.manage']
// Concept note 7.1, 7.2: payments received and the tax authority (the API checks again at the company).
export const PAYMENT_VIEW = ['core.payment.view', 'core.payment.match']
export const FISCAL_VIEW = ['core.fiscal.view', 'core.fiscal.edit', 'core.fiscal.configure']
// LAY-01, LAY-02, LAY-04: the organisation's and roles' dashboards, menus and shared list views.
export const LAYOUT_VIEW = ['core.layout.view', 'core.layout.edit', 'core.layout.publish']
// NUM-01: number formats.
export const THEME_VIEW = ['core.theme.view', 'core.theme.edit']
// LAY-05: the tills' sell screen layout (POS module).
export const POS_LAYOUT_VIEW = ['pos.layout.view', 'pos.layout.edit', 'pos.layout.publish']
export const NUMBERING_VIEW = ['core.numbering.view', 'core.numbering.edit']
// CF-01: custom fields of items and contacts.
export const CUSTOM_FIELD_VIEW = ['core.custom_field.view', 'core.custom_field.manage']
// TPL-01: document templates (the API checks again at the template's place).
export const TEMPLATE_VIEW = ['core.template.view', 'core.template.edit', 'core.template.publish']

export const NAV_GROUPS = [
  {
    id: 'overview',
    label: (t) => t('nav.groups.overview'),
    items: [{ to: '/', end: true, icon: 'dashboard', label: (t) => t('nav.dashboard') }],
  },
  {
    // APR-04: everyone has an approvals inbox; the badge counts what waits for them.
    id: 'workspace',
    label: (t) => t('nav.groups.workspace'),
    items: [{ to: '/approvals', icon: 'approvals', label: (t) => t('nav.approvals'), badge: 'approvals', module: 'core' }],
  },
  {
    // POS-12, TEN-07, H2: only while the POS module is active (RBAC-08).
    id: 'pos',
    label: (t) => t('nav.groups.pos'),
    items: [
      { to: '/pos/dashboard', icon: 'chart', label: (t) => t('nav.posDashboard'), permission: POS_SALE_VIEW, module: 'pos' },
      { to: '/pos/sales', icon: 'sales', label: (t) => t('nav.posSales'), permission: POS_SALE_VIEW, module: 'pos' },
      { to: '/pos/shifts', icon: 'shifts', label: (t) => t('nav.posShifts'), permission: POS_SHIFT_VIEW, module: 'pos' },
      { to: '/pos/held', icon: 'held', label: (t) => t('nav.posHeld'), permission: POS_HELD_VIEW, module: 'pos' },
    ],
  },
  {
    // Loyalty (docs/modules/loyalty.md): only while the module is active (RBAC-08).
    id: 'loyalty',
    label: (t) => t('nav.groups.loyalty'),
    items: [
      { to: '/loyalty/promotions', icon: 'promotions', label: (t) => t('nav.loyaltyPromotions'), permission: ['loyalty.promotion.view', 'loyalty.promotion.create', 'loyalty.promotion.edit'], module: 'loyalty' },
      { to: '/loyalty/gift-cards', icon: 'giftCards', label: (t) => t('nav.loyaltyGiftCards'), permission: 'loyalty.gift_card.view', module: 'loyalty' },
      { to: '/loyalty/credit', icon: 'creditLimits', label: (t) => t('nav.loyaltyCredit'), permission: 'loyalty.credit.view', module: 'loyalty' },
      { to: '/loyalty/ageing', icon: 'chart', label: (t) => t('nav.loyaltyAgeing'), permission: 'loyalty.credit.view', module: 'loyalty' },
      { to: '/loyalty/review', icon: 'alert', label: (t) => t('nav.loyaltyReview'), permission: 'loyalty.sale.view', module: 'loyalty' },
      { to: '/loyalty/programme', icon: 'loyalty', label: (t) => t('nav.loyaltyProgramme'), permission: ['loyalty.programme.view', 'loyalty.programme.edit'], module: 'loyalty' },
    ],
  },
  {
    // CRM and sales (docs/modules/crm.md, Screens): only while the module is active (RBAC-08).
    id: 'crm',
    label: (t) => t('nav.groups.crm'),
    items: [
      { to: '/crm/leads', icon: 'leads', label: (t) => t('nav.crmLeads'), permission: 'crm.lead.view', module: 'crm' },
      { to: '/crm/opportunities', icon: 'opportunities', label: (t) => t('nav.crmOpportunities'), permission: 'crm.opportunity.view', module: 'crm' },
      { to: '/crm/quotes', icon: 'quotes', label: (t) => t('nav.crmQuotes'), permission: 'crm.quote.view', module: 'crm' },
      { to: '/crm/calendar', icon: 'calendar', label: (t) => t('nav.crmCalendar'), permission: 'crm.activity.view', module: 'crm' },
      { to: '/crm/targets', icon: 'targets', label: (t) => t('nav.crmTargets'), permission: ['crm.target.view', 'crm.target.manage'], module: 'crm' },
      {
        to: '/crm/settings',
        needsCompany: true,
        icon: 'settings',
        label: (t) => t('nav.crmSettings'),
        permission: ['crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view', 'crm.settings.manage'],
        module: 'crm',
      },
    ],
  },
  {
    // E-commerce and delivery (docs/modules/ecommerce.md, Screens): only while the module is active (RBAC-08).
    id: 'ecommerce',
    label: (t) => t('nav.groups.ecommerce'),
    items: [
      { to: '/ecommerce/orders', icon: 'orders', label: (t) => t('nav.ecommerceOrders'), permission: 'ecommerce.order.view', module: 'ecommerce' },
      { to: '/ecommerce/deliveries', icon: 'deliveries', label: (t) => t('nav.ecommerceDeliveries'), permission: 'ecommerce.delivery.view', module: 'ecommerce' },
      { to: '/ecommerce/inbox', icon: 'whatsapp', label: (t) => t('nav.ecommerceInbox'), permission: 'ecommerce.whatsapp.view', module: 'ecommerce' },
      { to: '/ecommerce/cashups', icon: 'payments', label: (t) => t('nav.ecommerceCashups'), permission: 'ecommerce.cashup.view', module: 'ecommerce' },
      { to: '/ecommerce/riders', icon: 'users', label: (t) => t('nav.ecommerceRiders'), permission: ['ecommerce.delivery.view', 'ecommerce.rider.manage'], module: 'ecommerce' },
      { to: '/ecommerce/reports', icon: 'chart', label: (t) => t('nav.ecommerceReports'), permission: 'ecommerce.report.view', module: 'ecommerce' },
      { to: '/ecommerce/settings', icon: 'settings', label: (t) => t('nav.ecommerceSettings'), permission: ['ecommerce.store.view', 'ecommerce.store.manage'], module: 'ecommerce' },
    ],
  },
  {
    // INV-19, docs/modules/inventory.md (Screens): only while the Inventory module is active (RBAC-08).
    id: 'inventory',
    label: (t) => t('nav.groups.inventory'),
    items: [
      { to: '/inventory/stock', icon: 'stock', label: (t) => t('nav.inventoryStock'), permission: INVENTORY_STOCK_VIEW, module: 'inventory' },
      { to: '/inventory/movements', icon: 'movements', label: (t) => t('nav.inventoryMovements'), permission: INVENTORY_STOCK_VIEW, module: 'inventory' },
      { to: '/inventory/transfers', icon: 'transfers', label: (t) => t('nav.inventoryTransfers'), permission: INVENTORY_TRANSFER_VIEW, module: 'inventory' },
      { to: '/inventory/counts', icon: 'counts', label: (t) => t('nav.inventoryCounts'), permission: INVENTORY_COUNT_VIEW, module: 'inventory' },
      { to: '/inventory/adjustments', icon: 'adjustments', label: (t) => t('nav.inventoryAdjustments'), permission: INVENTORY_ADJUSTMENT_VIEW, module: 'inventory' },
      { to: '/inventory/reorder', icon: 'reorder', label: (t) => t('nav.inventoryReorder'), permission: INVENTORY_REORDER_VIEW, module: 'inventory' },
      { to: '/inventory/valuation', icon: 'valuation', label: (t) => t('nav.inventoryValuation'), permission: INVENTORY_VALUATION_VIEW, module: 'inventory' },
      { to: '/inventory/labels', icon: 'labels', label: (t) => t('nav.inventoryLabels'), permission: INVENTORY_LABEL_PRINT, module: 'inventory' },
      { to: '/inventory/settings', icon: 'settings', label: (t) => t('nav.inventorySettings'), permission: INVENTORY_SETTINGS_VIEW, module: 'inventory' },
    ],
  },
  {
    // Procurement (docs/modules/procurement.md, Screens): only while the Procurement module is active (RBAC-08).
    id: 'procurement',
    label: (t) => t('nav.groups.procurement'),
    items: [
      { to: '/procurement/requisitions', icon: 'requisitions', label: (t) => t('nav.procurementRequisitions'), permission: PROCUREMENT_REQUISITION_VIEW, module: 'procurement' },
      { to: '/procurement/rfqs', icon: 'rfqs', label: (t) => t('nav.procurementRfqs'), permission: PROCUREMENT_RFQ_VIEW, module: 'procurement' },
      { to: '/procurement/tenders', icon: 'tenders', label: (t) => t('nav.procurementTenders'), permission: PROCUREMENT_TENDER_VIEW, module: 'procurement' },
      { to: '/procurement/orders', icon: 'orders', label: (t) => t('nav.procurementOrders'), permission: PROCUREMENT_PO_VIEW, module: 'procurement' },
      { to: '/procurement/receipts', icon: 'receipts', label: (t) => t('nav.procurementReceipts'), permission: PROCUREMENT_RECEIPT_VIEW, module: 'procurement' },
      { to: '/procurement/invoices', icon: 'supplierInvoices', label: (t) => t('nav.procurementInvoices'), permission: PROCUREMENT_INVOICE_VIEW, module: 'procurement' },
      { to: '/procurement/landed-costs', icon: 'landedCosts', label: (t) => t('nav.procurementLandedCosts'), permission: PROCUREMENT_LANDED_COST_VIEW, module: 'procurement' },
      { to: '/procurement/supplier-performance', icon: 'performance', label: (t) => t('nav.procurementPerformance'), permission: PROCUREMENT_PERFORMANCE_VIEW, module: 'procurement' },
      { to: '/procurement/settings', needsCompany: true, icon: 'settings', label: (t) => t('nav.procurementSettings'), permission: PROCUREMENT_SETTINGS_VIEW, module: 'procurement' },
    ],
  },
  {
    // Stores (docs/modules/stores.md, Screens): only while the Stores module is active (RBAC-08).
    id: 'stores',
    label: (t) => t('nav.groups.stores'),
    items: [
      { to: '/stores/requisitions', icon: 'templates', label: (t) => t('nav.storesRequisitions'), permission: STORES_REQUISITION_VIEW, module: 'stores' },
      { to: '/stores/issues', icon: 'transfers', label: (t) => t('nav.storesIssues'), permission: STORES_ISSUE_VIEW, module: 'stores' },
      { to: '/stores/returns', icon: 'undo', label: (t) => t('nav.storesReturns'), permission: STORES_RETURN_VIEW, module: 'stores' },
      { to: '/stores/ledger', icon: 'movements', label: (t) => t('nav.storesLedger'), permission: STORES_LEDGER_VIEW, module: 'stores' },
      { to: '/stores/stock', icon: 'stock', label: (t) => t('nav.storesStock'), permission: STORES_LEDGER_VIEW, module: 'stores' },
      { to: '/stores/consumption', icon: 'chart', label: (t) => t('nav.storesConsumption'), permission: STORES_REPORT_VIEW, module: 'stores' },
    ],
  },
  {
    // Manufacturing (docs/modules/manufacturing.md, Screens): only while the Manufacturing module is active (RBAC-08).
    id: 'manufacturing',
    label: (t) => t('nav.groups.manufacturing'),
    items: [
      { to: '/manufacturing/boms', icon: 'templates', label: (t) => t('nav.manufacturingBoms'), permission: MANUFACTURING_BOM_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/orders', icon: 'orders', label: (t) => t('nav.manufacturingOrders'), permission: MANUFACTURING_ORDER_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/consumption', icon: 'chart', label: (t) => t('nav.manufacturingConsumption'), permission: MANUFACTURING_REPORT_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/yield', icon: 'performance', label: (t) => t('nav.manufacturingYield'), permission: MANUFACTURING_REPORT_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/backflushes', icon: 'movements', label: (t) => t('nav.manufacturingBackflushes'), permission: MANUFACTURING_REPORT_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/recipe-costing', icon: 'valuation', label: (t) => t('nav.manufacturingRecipeCosting'), permission: MANUFACTURING_REPORT_VIEW, module: 'manufacturing' },
      { to: '/manufacturing/settings', needsCompany: true, icon: 'settings', label: (t) => t('nav.manufacturingSettings'), permission: MANUFACTURING_SETTINGS_VIEW, module: 'manufacturing' },
    ],
  },
  {
    // HR (docs/modules/hr.md, Screens): only while the HR module is active (RBAC-08).
    id: 'people',
    label: (t) => t('nav.groups.people'),
    items: [
      { to: '/hr/employees', icon: 'users', label: (t) => t('nav.hrEmployees'), permission: HR_EMPLOYEE_VIEW, module: 'hr' },
      { to: '/hr/positions', icon: 'organisation', label: (t) => t('nav.hrPositions'), permission: HR_POSITION_VIEW, module: 'hr' },
      { to: '/hr/leave', icon: 'held', label: (t) => t('nav.hrLeave'), permission: HR_LEAVE_VIEW, module: 'hr' },
      { to: '/hr/rosters', icon: 'shifts', label: (t) => t('nav.hrRosters'), permission: HR_ROSTER_VIEW, module: 'hr' },
      { to: '/hr/attendance', icon: 'clock', label: (t) => t('nav.hrAttendance'), permission: HR_ATTENDANCE_VIEW, module: 'hr' },
      { to: '/hr/onboarding', icon: 'templates', label: (t) => t('nav.hrOnboarding'), permission: HR_CHECKLIST_VIEW, module: 'hr' },
      { to: '/hr/disciplinary', icon: 'alert', label: (t) => t('nav.hrDisciplinary'), permission: HR_DISCIPLINARY_VIEW, module: 'hr' },
      { to: '/hr/appraisals', icon: 'chart', label: (t) => t('nav.hrAppraisals'), permission: HR_APPRAISAL_VIEW, module: 'hr' },
    ],
  },
  {
    // Fixed assets (docs/modules/fixed-assets.md, Screens): only while the module is active (RBAC-08).
    id: 'fixedAssets',
    label: (t) => t('nav.groups.fixedAssets'),
    items: [
      { to: '/fixed-assets/assets', icon: 'items', label: (t) => t('nav.faAssets'), permission: FA_ASSET_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/categories', icon: 'categories', label: (t) => t('nav.faCategories'), permission: FA_ASSET_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/runs', icon: 'valuation', label: (t) => t('nav.faRuns'), permission: FA_DEPRECIATION_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/transfers', icon: 'transfers', label: (t) => t('nav.faTransfers'), permission: FA_ASSET_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/disposals', icon: 'archive', label: (t) => t('nav.faDisposals'), permission: FA_ASSET_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/revaluations', icon: 'adjustments', label: (t) => t('nav.faRevaluations'), permission: FA_ASSET_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/maintenance', icon: 'settings', label: (t) => t('nav.faMaintenance'), permission: FA_MAINTENANCE_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/labels', icon: 'labels', label: (t) => t('nav.faLabels'), permission: FA_LABEL_PRINT, module: 'fixed_assets' },
      { to: '/fixed-assets/reports', icon: 'chart', label: (t) => t('nav.faReports'), permission: FA_REPORT_VIEW, module: 'fixed_assets' },
      { to: '/fixed-assets/settings', icon: 'numbering', label: (t) => t('nav.faSettings'), permission: FA_SETTINGS_VIEW, module: 'fixed_assets' },
    ],
  },
  {
    // Payroll (docs/modules/payroll.md, Screens): only while the Payroll module is active (RBAC-08).
    id: 'payroll',
    label: (t) => t('nav.groups.payroll'),
    items: [
      { to: '/payroll/runs', icon: 'payments', label: (t) => t('nav.payrollRuns'), permission: PAYROLL_RUN_VIEW, module: 'payroll' },
      { to: '/payroll/employees', icon: 'users', label: (t) => t('nav.payrollEmployees'), permission: PAYROLL_EMPLOYEE_VIEW, module: 'payroll' },
      { to: '/payroll/loans', icon: 'currencies', label: (t) => t('nav.payrollLoans'), permission: PAYROLL_LOAN_VIEW, module: 'payroll' },
      { to: '/payroll/payslips', icon: 'templates', label: (t) => t('nav.payrollPayslips'), permission: PAYROLL_PAYSLIP_VIEW, module: 'payroll' },
      { to: '/payroll/returns', icon: 'fiscal', label: (t) => t('nav.payrollReturns'), permission: PAYROLL_RETURN_VIEW, module: 'payroll' },
      { to: '/payroll/settings', needsCompany: true, icon: 'settings', label: (t) => t('nav.payrollSettings'), permission: PAYROLL_SETTINGS_VIEW, module: 'payroll' },
    ],
  },
  {
    // HR-31, AUTH-12: self-service, for a user linked to an employee (no permission needed); `employee` items need the link.
    id: 'myHr',
    label: (t) => t('nav.groups.myHr'),
    items: [
      { to: '/hr/me/profile', icon: 'users', label: (t) => t('nav.myProfile'), module: 'hr', employee: true },
      { to: '/hr/me/leave', icon: 'held', label: (t) => t('nav.myLeave'), module: 'hr', employee: true },
      { to: '/hr/me/roster', icon: 'shifts', label: (t) => t('nav.myRoster'), module: 'hr', employee: true },
      { to: '/hr/me/attendance', icon: 'clock', label: (t) => t('nav.myAttendance'), module: 'hr', employee: true },
      { to: '/hr/me/swaps', icon: 'transfers', label: (t) => t('nav.mySwaps'), module: 'hr', employee: true },
      { to: '/hr/me/appraisals', icon: 'chart', label: (t) => t('nav.myAppraisals'), module: 'hr', employee: true },
      // PAY-24: My payslips, while Payroll is active.
      { to: '/payroll/me/payslips', icon: 'payments', label: (t) => t('nav.myPayslips'), module: 'payroll', employee: true },
    ],
  },
  {
    // Accounting (docs/modules/accounting.md): only while the module is active (RBAC-08); budgets need Accounting Pro.
    id: 'accounting',
    label: (t) => t('nav.groups.accounting'),
    items: [
      { to: '/accounting/journals', icon: 'templates', label: (t) => t('nav.accountingJournals'), permission: 'accounting.journal.view', module: 'accounting' },
      { to: '/accounting/documents', icon: 'sales', label: (t) => t('nav.accountingDocuments'), permission: 'accounting.invoice.view', module: 'accounting' },
      { to: '/accounting/payments', icon: 'payments', label: (t) => t('nav.accountingPayments'), permission: 'accounting.payment.view', module: 'accounting' },
      { to: '/accounting/bank', icon: 'currencies', label: (t) => t('nav.accountingBank'), permission: 'accounting.bank.view', module: 'accounting' },
      { to: '/accounting/budgets', icon: 'chart', label: (t) => t('nav.accountingBudgets'), permission: 'accounting.budget.view', module: 'accounting_pro' },
      { to: '/accounting/periods', icon: 'lock', label: (t) => t('nav.accountingPeriods'), permission: 'accounting.period.view', module: 'accounting' },
      { to: '/accounting/reports', icon: 'chart', label: (t) => t('nav.accountingReports'), permission: ['accounting.report.view', 'accounting.report.consolidate'], module: 'accounting' },
      { to: '/accounting/chart', icon: 'numbering', label: (t) => t('nav.accountingChart'), permission: ['accounting.account.view', 'accounting.account.manage'], module: 'accounting' },
    ],
  },
  {
    id: 'catalogue',
    label: (t) => t('nav.groups.catalogue'),
    items: [
      { to: '/catalogue/items', icon: 'items', label: (t) => t('nav.items'), permission: ITEM_VIEW, module: 'core' },
      { to: '/catalogue/categories', icon: 'categories', label: (t) => t('nav.categories'), permission: CATEGORY_VIEW, module: 'core' },
      { to: '/catalogue/units', icon: 'units', label: (t) => t('nav.units'), permission: UOM_VIEW, module: 'core' },
    ],
  },
  {
    id: 'contacts',
    label: (t) => t('nav.groups.contacts'),
    items: [
      { to: '/contacts/customers', icon: 'customers', label: (t) => t('nav.customers'), permission: PARTY_VIEW, module: 'core' },
      { to: '/contacts/suppliers', icon: 'suppliers', label: (t) => t('nav.suppliers'), permission: PARTY_VIEW, module: 'core' },
      { to: '/contacts/credit-limit-changes', icon: 'creditLimits', label: (t) => t('nav.creditLimitChanges'), permission: PARTY_VIEW, module: 'core' },
    ],
  },
  {
    id: 'settings',
    label: (t) => t('nav.groups.settings'),
    items: [
      { to: '/settings/organisation', icon: 'organisation', label: (t) => t('nav.organisation'), permission: ORGANISATION_VIEW, module: 'core' },
      { to: '/settings/users', icon: 'users', label: (t) => t('nav.users'), permission: 'core.user.view', module: 'core' },
      { to: '/settings/roles', icon: 'roles', label: (t) => t('nav.roles'), permission: 'core.role.view', module: 'core' },
      { to: '/settings/security', icon: 'security', label: (t) => t('nav.security'), permission: 'core.settings.edit', tenantWide: true, module: 'core' },
      // AUTH-11: single sign-on (Owners).
      { to: '/settings/sso', icon: 'key', label: (t) => t('nav.sso'), permission: 'core.sso.manage', tenantWide: true, module: 'core' },
      // RBAC-13: who may see the organisation from our support team, and what they did.
      { to: '/settings/security/support-access', icon: 'lock', label: (t) => t('nav.supportAccess'), permission: 'core.support_access.view', tenantWide: true, module: 'core' },
      { to: '/settings/numbering', icon: 'numbering', label: (t) => t('nav.numbering'), permission: NUMBERING_VIEW, module: 'core' },
      { to: '/settings/custom-fields', icon: 'columns', label: (t) => t('nav.customFields'), permission: CUSTOM_FIELD_VIEW, module: 'core' },
      // IMP-01..IMP-03: imports of items, contacts and prices.
      { to: '/settings/imports', icon: 'upload', label: (t) => t('nav.imports'), permission: 'core.import.run', module: 'core' },
      // CF-04: the organisation's own forms (types); their records are the Forms group, made from data.
      { to: '/settings/forms', icon: 'templates', label: (t) => t('nav.formTypes'), permission: 'core.custom_form_type.manage', tenantWide: true, module: 'core' },
      { to: '/settings/document-templates', icon: 'templates', label: (t) => t('nav.documentTemplates'), permission: TEMPLATE_VIEW, module: 'core' },
      { to: '/settings/appearance', icon: 'appearance', label: (t) => t('nav.appearance') },
      // BR-02, BR-08: the business's theme; BR-04..BR-06: its hosts and senders (Owner, Admin).
      { to: '/settings/brand', icon: 'brand', label: (t) => t('nav.brand'), permission: THEME_VIEW, module: 'core' },
      // LAY-05: the tills' sell screen, while the POS module is active.
      { to: '/settings/pos-layout', icon: 'device', label: (t) => t('nav.posLayout'), permission: POS_LAYOUT_VIEW, module: 'pos' },
      { to: '/settings/domains', icon: 'domains', label: (t) => t('nav.domains'), permission: 'core.domain.manage', tenantWide: true, module: 'core' },
      // API-02: API keys for integrations; the developer portal links from the page.
      { to: '/settings/api-keys', icon: 'developers', label: (t) => t('nav.apiKeys'), permission: 'core.api_key.manage', tenantWide: true, module: 'core' },
      // MKT-01, MKT-02: the marketplace, the apps installed here and the organisation's own apps.
      { to: '/marketplace', icon: 'marketplace', label: (t) => t('nav.marketplace'), permission: 'core.marketplace.view', tenantWide: true, module: 'core' },
      { to: '/settings/apps', icon: 'apps', label: (t) => t('nav.installedApps'), permission: 'core.marketplace.view', tenantWide: true, module: 'core' },
      { to: '/settings/developer-apps', icon: 'developers', label: (t) => t('nav.developerApps'), permission: 'core.developer.manage', tenantWide: true, module: 'core' },
      // SUB-02..SUB-06: the subscription (Owner, Admin).
      { to: '/settings/subscription', icon: 'subscription', label: (t) => t('nav.subscription'), permission: 'core.subscription.view', tenantWide: true, module: 'core' },
      // LIM-01, LIM-02: usage against the plan (Owners only).
      { to: '/settings/usage', icon: 'chart', label: (t) => t('nav.usage'), owner: true },
      // EXP-02: the full data export (Owners only).
      { to: '/settings/data-export', icon: 'download', label: (t) => t('nav.dataExport'), permission: 'core.tenant.export', tenantWide: true, module: 'core' },
      // HOOK-01: webhooks to other systems (Owner, Admin).
      { to: '/settings/webhooks', icon: 'link', label: (t) => t('nav.webhooks'), permission: 'core.webhook.manage', tenantWide: true, module: 'core' },
      // NFR-08: data subject requests and marketing consent.
      { to: '/settings/privacy', icon: 'security', label: (t) => t('nav.privacy'), permission: ['core.privacy_request.view', 'core.consent.view'], tenantWide: true, module: 'core' },
      // LAY-01, LAY-02: the dashboards and menus of the organisation and its roles.
      { to: '/settings/layouts/dashboards', icon: 'dashboard', label: (t) => t('nav.dashboards'), permission: LAYOUT_VIEW, tenantWide: true, module: 'core' },
      { to: '/settings/layouts/navigation', icon: 'menu', label: (t) => t('nav.navigationEditor'), permission: LAYOUT_VIEW, tenantWide: true, module: 'core' },
      // LAY-03: form layouts (item, party, custom forms).
      { to: '/settings/layouts/forms', icon: 'layouts', label: (t) => t('nav.formLayouts'), permission: LAYOUT_VIEW, tenantWide: true, module: 'core' },
      { to: '/settings/sessions', icon: 'sessions', label: (t) => t('nav.sessions') },
      // AUTH-06: one's own POS PIN, while the POS module is active.
      { to: '/settings/pos-pin', icon: 'key', label: (t) => t('nav.posPin'), module: 'pos' },
      // NOT-04: everyone chooses their own notification channels.
      { to: '/settings/notifications', icon: 'bell', label: (t) => t('nav.notifications') },
      {
        to: '/settings/notification-templates',
        icon: 'templates',
        label: (t) => t('nav.notificationTemplates'),
        permission: NOTIFICATION_TEMPLATE_VIEW,
        tenantWide: true,
        module: 'core',
      },
      {
        to: '/settings/notification-deliveries',
        icon: 'deliveries',
        label: (t) => t('nav.notificationDeliveries'),
        permission: NOTIFICATION_DELIVERY_VIEW,
        tenantWide: true,
        module: 'core',
      },
    ],
  },
  {
    id: 'finance',
    label: (t) => t('nav.groups.finance'),
    items: [
      { to: '/settings/currencies', icon: 'currencies', label: (t) => t('nav.currencies'), permission: 'core.currency.view', module: 'core' },
      { to: '/settings/exchange-rates', needsCompany: true, icon: 'exchangeRates', label: (t) => t('nav.exchangeRates'), permission: EXCHANGE_RATE_VIEW, module: 'core' },
      { to: '/settings/taxes', needsCompany: true, icon: 'taxes', label: (t) => t('nav.taxes'), permission: TAX_VIEW, module: 'core' },
      { to: '/settings/payment-methods', needsCompany: true, icon: 'paymentMethods', label: (t) => t('nav.paymentMethods'), permission: PAYMENT_METHOD_VIEW, module: 'core' },
      { to: '/settings/payments', needsCompany: true, icon: 'payments', label: (t) => t('nav.payments'), permission: PAYMENT_VIEW, module: 'core' },
      { to: '/settings/fiscal', needsCompany: true, icon: 'fiscal', label: (t) => t('nav.fiscal'), permission: FISCAL_VIEW, module: 'core' },
    ],
  },
  {
    id: 'automation',
    label: (t) => t('nav.groups.automation'),
    items: [
      { to: '/settings/workflows', icon: 'workflows', label: (t) => t('nav.workflows'), permission: WORKFLOW_VIEW, module: 'core' },
      { to: '/settings/automation-rules', icon: 'automation', label: (t) => t('nav.automationRules'), permission: AUTOMATION_VIEW, module: 'core' },
    ],
  },
  {
    id: 'masterData',
    label: (t) => t('nav.groups.masterData'),
    items: [
      { to: '/settings/dimensions', needsCompany: true, icon: 'dimensions', label: (t) => t('nav.dimensions'), permission: DIMENSION_VIEW, module: 'core' },
      { to: '/settings/sharing', icon: 'sharing', label: (t) => t('nav.sharing'), permission: 'core.master_data_settings.edit', tenantWide: true, module: 'core' },
    ],
  },
]

/**
 * The groups and items the user may see; empty groups are dropped. Items
 * with `employee` show only to a user linked to an employee (`isEmployee`, HR-31).
 */
export function visibleGroups(groups, { can, hasModule, tenantWide = can, hasCompany = true, isOwner = false, isEmployee = false }) {
  const allowed = (item) => {
    if (item.needsCompany && !hasCompany) return false
    if (item.employee && !isEmployee) return false
    if (item.owner) return isOwner
    if (!item.permission) return true
    if (!item.tenantWide) return can(item.permission)
    return (Array.isArray(item.permission) ? item.permission : [item.permission]).some((name) => tenantWide(name))
  }
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => allowed(item) && (!item.module || hasModule(item.module))),
    }))
    .filter((group) => group.items.length > 0)
}

/**
 * LAY-02: the editable navigation tree of a layout, merged with the catalogue
 * (LAY-07): `[{ id, label, items: [{ id, label, hidden }] }]`, where labels
 * are the layout's own names (typed once) or null for the catalogue's, and
 * item ids are routes. Groups the layout does not name come at the end; new
 * items go to their default group; items that no longer exist are skipped.
 */
export function navigationTree(groups, layout) {
  const named = (layout?.groups ?? []).map((group) => ({
    id: group.id,
    label: group.label ?? null,
    items: (group.items ?? []).map((item) => ({ id: item.id, label: item.label ?? null, hidden: Boolean(item.hidden) })),
  }))
  for (const group of groups) if (!named.some((entry) => entry.id === group.id)) named.push({ id: group.id, label: null, items: [] })
  const catalogue = groups.flatMap((group) => group.items.map((item) => ({ id: item.to, label: null, hidden: false, group: group.id })))
  return mergeGrouped(named, catalogue, { newGroup: { id: groups[0]?.id ?? 'main', label: null } })
}

/**
 * LAY-02: the catalogue's groups laid out by `layout` (null: unchanged):
 * renamed, reordered, items moved or hidden. Permissions and modules still
 * filter afterwards (visibleGroups), so hiding never grants (RBAC-09).
 */
export function applyNavigationLayout(groups, layout) {
  if (!layout?.groups) return groups
  const catalogueGroups = new Map(groups.map((group) => [group.id, group]))
  const items = new Map(groups.flatMap((group) => group.items.map((item) => [item.to, item])))
  return navigationTree(groups, layout)
    .map((group) => {
      const base = catalogueGroups.get(group.id)
      return {
        id: group.id,
        label: group.label ? () => group.label : (base?.label ?? (() => group.id)),
        items: group.items
          .filter((entry) => !entry.hidden && items.has(entry.id))
          .map((entry) => (entry.label ? { ...items.get(entry.id), label: () => entry.label } : items.get(entry.id))),
      }
    })
    .filter((group) => group.items.length > 0)
}

/** The layout's home page when the user may open it (one of their visible items), else null. */
export function homeOf(layout, visible) {
  const home = layout?.home
  if (!home || home === '/') return null
  return visible.some((group) => group.items.some((item) => item.to === home)) ? home : null
}

/**
 * CF-04: the Forms group, made from data: one item per active custom form
 * the user may see (the API lists only those). Placed after the workspace
 * group; empty when there are none.
 */
export function withFormsGroup(groups, types) {
  const items = (types ?? []).filter((type) => !type.archived_at && type.can?.view).map((type) => ({ to: `/forms/${type.key}`, icon: 'templates', label: () => type.name }))
  if (items.length === 0) return groups
  const group = { id: 'forms', label: (t) => t('nav.groups.forms'), items }
  const at = groups.findIndex((entry) => entry.id === 'workspace')
  return at < 0 ? [...groups, group] : [...groups.slice(0, at + 1), group, ...groups.slice(at + 1)]
}
