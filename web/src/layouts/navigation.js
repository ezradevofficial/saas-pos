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
// Concept note 7.1, 7.2: payments received and the tax authority (the API checks again at the company).
export const PAYMENT_VIEW = ['core.payment.view', 'core.payment.match']
export const FISCAL_VIEW = ['core.fiscal.view', 'core.fiscal.edit', 'core.fiscal.configure']
// NUM-01: number formats.
export const NUMBERING_VIEW = ['core.numbering.view', 'core.numbering.edit']

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
      { to: '/settings/numbering', icon: 'numbering', label: (t) => t('nav.numbering'), permission: NUMBERING_VIEW, module: 'core' },
      { to: '/settings/appearance', icon: 'appearance', label: (t) => t('nav.appearance') },
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

/** The groups and items the user may see; empty groups are dropped. */
export function visibleGroups(groups, { can, hasModule, tenantWide = can, hasCompany = true }) {
  const allowed = (item) => {
    if (item.needsCompany && !hasCompany) return false
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
