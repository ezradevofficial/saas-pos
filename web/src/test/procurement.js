// Fixtures of the Procurement screens' tests (docs/modules/procurement.md):
// a Kenyan company with one branch and location, a supplier, an item, a
// unit, a tax code, and the GET answers every Procurement page reads.
import { mockRoutes, tenantWide } from '@/test/renderApp'

export const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
export const named = (id, name) => ({ id, name })
export const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
export const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
export const BRANCH = { id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }
export const LOCATION = { id: 'l-1', name: 'Main store', branch_id: 'br-1', branch: named('br-1', 'Westlands'), archived_at: null }
export const SUPPLIER = { id: 'p-1', name: 'Mombasa Millers', roles: ['supplier'], archived_at: null }
export const ITEM = { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
export const UOM = { id: 'u-pcs', code: 'pcs', name: 'Pieces', archived_at: null }
export const VAT = { id: 'tx-1', code: 'VAT16', name: 'VAT 16 %', rate_needed: false, archived_at: null }

/** Every Procurement permission, tenant-wide, plus what the pickers read. */
export const ALL = tenantWide([
  'core.company.view',
  'core.uom.view',
  ...['requisition.view', 'requisition.create', 'requisition.cancel', 'rfq.view', 'rfq.create', 'rfq.cancel'].map((name) => `procurement.${name}`),
  ...['tender.view', 'tender.create', 'tender.open', 'tender.evaluate', 'tender.award', 'tender.cancel'].map((name) => `procurement.${name}`),
  ...['po.view', 'po.create', 'po.cancel', 'po.print', 'receipt.view', 'receipt.create', 'receipt.cancel'].map((name) => `procurement.${name}`),
  ...['invoice.view', 'invoice.create', 'invoice.approve', 'invoice.override', 'invoice.cancel', 'landed_cost.view', 'landed_cost.create', 'landed_cost.cancel'].map((name) => `procurement.${name}`),
  'procurement.performance.view',
  'procurement.settings.edit',
])

/** GET answers: `routes` first (exact path or RegExp), then the places, suppliers, items, units, tax codes and currencies. */
export function mockProcurement(api, routes = [], { permissions = ALL } = {}) {
  mockRoutes(
    api,
    [
      ...routes,
      [/^companies\?/, { data: [COMPANY] }],
      [/^branches\?/, { data: [BRANCH] }],
      [/^locations\?/, { data: [LOCATION] }],
      [/^parties\?role=supplier/, page([SUPPLIER])],
      [/^items\?/, page([ITEM])],
      [/^uoms\?/, { data: [UOM] }],
      [/^companies\/c-1\/tax-codes/, page([VAT])],
      [/^companies\/c-1\/(departments|cost-centres|projects)/, page([])],
      ['tenant/currencies', { data: [{ code: 'KES', active: true }, { code: 'USD', active: true }] }],
      [/^users\?/, page([named('u-1', 'Amina Otieno'), named('u-2', 'Joseph Mwangi')])],
    ],
    { permissions, modules: ['core', 'procurement'], companies: [COMPANY] },
  )
}
