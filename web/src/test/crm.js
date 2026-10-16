// Fixtures of the CRM screens' tests (docs/modules/crm.md): a Kenyan
// company with one branch, a pipeline with stages, options, people, a
// customer, an item, a tax code, and the GET answers every CRM page reads.
import { mockRoutes, tenantWide } from '@/test/renderApp'

export const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
export const named = (id, name) => ({ id, name })
export const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
export const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
export const BRANCH = { id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }
export const CUSTOMER = { id: 'pt-1', name: 'Duka Moja', roles: ['customer'], archived_at: null }
export const ITEM = { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock', base_uom_id: 'u-pcs', uoms: [] }
export const UOM = { id: 'u-pcs', code: 'pcs', name: 'Pieces', archived_at: null }
export const VAT = { id: 'tx-1', code: 'VAT16', name: 'VAT 16 %', rate_needed: false, archived_at: null }
export const STAGES = [
  { id: 'st-1', name: 'Qualification', position: 1, probability: 10, archived_at: null },
  { id: 'st-2', name: 'Proposal', position: 2, probability: 50, archived_at: null },
  { id: 'st-3', name: 'Negotiation', position: 3, probability: 75, archived_at: null },
]
export const PIPELINE = { id: 'pl-1', company: named('c-1', 'Amani Retail'), name: 'Sales', is_default: true, archived_at: null, stages: STAGES }
export const SOURCES = [{ id: 'src-1', company: named('c-1', 'Amani Retail'), kind: 'source', name: 'Walk-in', position: 1, archived_at: null }]
export const WON_REASONS = [{ id: 'wr-1', company: named('c-1', 'Amani Retail'), kind: 'won_reason', name: 'Best price', position: 1, archived_at: null }]
export const LOST_REASONS = [{ id: 'lr-1', company: named('c-1', 'Amani Retail'), kind: 'lost_reason', name: 'Too expensive', position: 1, archived_at: null }]
export const PEOPLE = [named('u-1', 'Amina Otieno'), named('u-2', 'Joseph Mwangi')]
export const SETTINGS = { company_id: 'c-1', currency: 'KES', quote_approval_minor: '10000000', quote_validity_days: 30, default_team: null, lost_reason_required: true, can: { manage: true } }

export const LEAD = {
  id: 'l-1',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  owner: named('u-1', 'Amina Otieno'),
  team: null,
  status: 'new',
  name: 'Wanjiku Kamau',
  organisation: 'Kamau Stores',
  title: 'Buyer',
  email: 'wanjiku@kamau.example',
  phone: '+254700000001',
  source: named('src-1', 'Walk-in'),
  channel: 'manual',
  estimated_value: money('5000000'),
  notes: null,
  disqualify_reason: null,
  party: null,
  opportunity: null,
  converted_at: null,
  custom: {},
  archived_at: null,
  created_at: '2026-10-10T06:00:00Z',
  updated_at: '2026-10-10T06:00:00Z',
  can: { edit: true, assign: true, convert: true, archive: true },
}

export const OPPORTUNITY = {
  id: 'o-1',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  owner: named('u-1', 'Amina Otieno'),
  team: null,
  party: named('pt-1', 'Duka Moja'),
  lead: null,
  pipeline: named('pl-1', 'Sales'),
  stage: { id: 'st-1', name: 'Qualification', probability: 10 },
  name: 'Duka Moja shelving',
  value: money('12000000'),
  expected_close_on: '2026-11-30',
  probability: 10,
  weighted_minor: '1200000',
  status: 'open',
  reason: null,
  close_note: null,
  closed_on: null,
  base_value: null,
  notes: null,
  custom: {},
  archived_at: null,
  created_at: '2026-10-10T06:00:00Z',
  can: { edit: true, close: true, archive: true },
}

/** Every CRM permission, tenant-wide, plus what the pickers read. */
export const ALL = tenantWide([
  'core.company.view',
  'core.uom.view',
  'core.party.view',
  ...['lead.view', 'lead.create', 'lead.edit', 'lead.assign', 'lead.convert', 'lead.archive'].map((name) => `crm.${name}`),
  ...['opportunity.view', 'opportunity.create', 'opportunity.edit', 'opportunity.close', 'opportunity.archive'].map((name) => `crm.${name}`),
  ...['quote.view', 'quote.create', 'quote.discount', 'quote.price', 'quote.approve', 'quote.send', 'quote.convert', 'quote.cancel'].map((name) => `crm.${name}`),
  'crm.activity.view',
  'crm.activity.manage',
  'crm.target.view',
  'crm.target.manage',
  'crm.settings.manage',
  'crm.scope.branch',
])

/** GET answers: `routes` first (exact path or RegExp), then the places, CRM configuration, customers, items, units and tax codes. */
export function mockCrm(api, routes = [], { permissions = ALL, modules = ['core', 'crm'] } = {}) {
  mockRoutes(
    api,
    [
      ...routes,
      [/^companies\?/, { data: [COMPANY] }],
      [/^branches\?/, { data: [BRANCH] }],
      [/^crm\/pipelines\?/, { data: [PIPELINE] }],
      [/^crm\/options\?.*kind=source/, { data: SOURCES }],
      [/^crm\/options\?.*kind=won_reason/, { data: WON_REASONS }],
      [/^crm\/options\?.*kind=lost_reason/, { data: LOST_REASONS }],
      [/^crm\/teams\?/, { data: [] }],
      [/^crm\/assignees/, { data: PEOPLE }],
      ['crm/companies/c-1/settings', { data: SETTINGS }],
      [/^custom-fields\/schema/, { data: [] }],
      [/^parties\?role=customer/, page([CUSTOMER])],
      [/^items\?/, page([ITEM])],
      [/^uoms\?/, { data: [UOM] }],
      [/^companies\/c-1\/tax-codes/, page([VAT])],
      ['tenant/currencies', { data: [{ code: 'KES', active: true }, { code: 'USD', active: true }, { code: 'CDF', active: true }] }],
      [/^document-workflows\//, { data: null }],
    ],
    { permissions, modules, companies: [COMPANY] },
  )
}
