// L10N-02: Procurement's status and enum words are looked up with dynamic
// keys, which the i18n check cannot see. Each key set is pinned to the values
// the API sends (api/modules/Procurement/Models), and the navigation group
// shows only while the module is active (RBAC-08).
import en from '@/locales/en.json'
import fr from '@/locales/fr.json'
import { NAV_GROUPS, visibleGroups } from '@/layouts/navigation'
import { BID_OUTCOMES, BID_STATUSES, LANDED_COST_METHODS, MATCH_STATUSES, QUOTE_STATUSES, STATUS_TONES, STATUSES, TENDER_KINDS, basisPoints } from './procurementData'

const node = (messages, path) => path.split('.').reduce((entry, key) => entry?.[key], messages) ?? {}

describe.each([
  ['en', en],
  ['fr', fr],
])('Procurement words (%s)', (_, messages) => {
  it('has a word for every status the API sends, and a tone for each', () => {
    const all = new Set([...Object.values(STATUSES).flat(), ...MATCH_STATUSES, ...BID_STATUSES, ...BID_OUTCOMES, ...QUOTE_STATUSES])
    for (const status of all) {
      expect(node(messages, 'procurement.statuses')[status], status).toBeTruthy()
      expect(STATUS_TONES[status], status).toBeTruthy()
    }
  })

  it('names the tender kinds, landed cost methods and list areas', () => {
    expect(Object.keys(node(messages, 'procurement.tenderKinds')).sort()).toEqual([...TENDER_KINDS].sort())
    expect(Object.keys(node(messages, 'procurement.methods')).sort()).toEqual([...LANDED_COST_METHODS].sort())
    expect(Object.keys(node(messages, 'procurement.landedCost.methodHelp')).sort()).toEqual([...LANDED_COST_METHODS].sort())
    for (const area of ['requisitions', 'rfqs', 'tenders', 'orders', 'receipts', 'invoices', 'landedCosts']) {
      for (const key of ['title', 'description', 'new', 'search', 'empty', 'back']) expect(node(messages, `procurement.${area}`)[key], `${area}.${key}`).toBeTruthy()
    }
  })
})

describe('Procurement navigation (RBAC-08, RBAC-09)', () => {
  const can = (name) => (Array.isArray(name) ? name : [name]).some((one) => one.startsWith('procurement.'))

  it('shows the Procurement group only while the module is active', () => {
    const on = visibleGroups(NAV_GROUPS, { can, hasModule: (module) => module === 'procurement' }).find((group) => group.id === 'procurement')
    expect(on.items.map((item) => item.to)).toEqual([
      '/procurement/requisitions',
      '/procurement/rfqs',
      '/procurement/tenders',
      '/procurement/orders',
      '/procurement/receipts',
      '/procurement/invoices',
      '/procurement/landed-costs',
      '/procurement/supplier-performance',
      '/procurement/settings',
    ])
    expect(visibleGroups(NAV_GROUPS, { can, hasModule: () => false }).some((group) => group.id === 'procurement')).toBe(false)
  })

  it('shows an item only to a holder of its view permission', () => {
    const group = visibleGroups(NAV_GROUPS, { can: (name) => name === 'procurement.receipt.view', hasModule: () => true }).find((entry) => entry.id === 'procurement')
    expect(group.items.map((item) => item.to)).toEqual(['/procurement/receipts'])
  })
})

describe('basisPoints (PRC-11, PRC-18)', () => {
  it('shows basis points as a percentage with two decimals', () => {
    expect(basisPoints(8765, 'en')).toBe('87.65 %')
    expect(basisPoints(10000, 'en')).toBe('100.00 %')
    expect(basisPoints(5, 'en')).toBe('0.05 %')
    expect(basisPoints(-125, 'en')).toBe('-1.25 %')
    expect(basisPoints(null, 'en')).toBe('—')
  })
})
