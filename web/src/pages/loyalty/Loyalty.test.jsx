import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const page = (rows) => ({ data: rows, meta: { total: rows.length, last_page: 1, from: 1, to: rows.length } })
const COMPANY = { id: 'c-1', name: 'Amani Retail', base_currency: 'KES' }

const PROMOTION = {
  id: 'p-1', company_id: 'c-1', company: { id: 'c-1', name: 'Amani Retail' }, name: 'Happy hour drinks', type: 'percent', status: 'active', priority: 5, version: 3,
  requires_coupon: true, percent: '20', amount_minor: null, currency: null, buy_qty: null, get_qty: null, targets: { items: [], categories: [] }, get_targets: null, components: [],
  min_spend_minor: null, branch_ids: [], customer_tags: ['vip'], schedule: { starts_on: null, ends_on: null, days: [1, 2, 3, 4, 5], time_from: '17:00', time_to: '19:00' },
  coupons: [], updated_at: '2026-10-13T10:00:00Z',
}
const ACCOUNT = {
  id: 'a-1', kind: 'credit', company: { id: 'c-1', name: 'Amani Retail' }, party: { id: 'pt-1', name: 'Duka Moja' }, status: 'open', currency: 'KES',
  balance: money('110000'), credit_limit: money('300000'), available: money('190000'), points: null,
}
const CARD = { id: 'g-1', company_id: 'c-1', kind: 'gift_card', last4: 'PQRS', currency: 'KES', initial: money('250000'), balance: money('120000'), status: 'active', expired: false, expires_on: null, party: null }

const ALL = ['core.company.view', 'core.party.view', 'loyalty.promotion.view', 'loyalty.promotion.edit', 'loyalty.promotion.create', 'loyalty.promotion.archive', 'loyalty.credit.view', 'loyalty.credit.receive', 'loyalty.credit.manage', 'loyalty.gift_card.view', 'loyalty.gift_card.issue', 'loyalty.points.view', 'loyalty.points.adjust', 'loyalty.programme.view', 'loyalty.programme.edit']

function setup(routes = [], permissions = ALL) {
  mockRoutes(
    api,
    [
      [/^companies\?/, { data: [COMPANY] }],
      [/^loyalty\/promotions\?/, page([PROMOTION])],
      ['loyalty/promotions/p-1', { data: PROMOTION }],
      ['loyalty/promotions/p-1/coupons', { data: [{ id: 'cp-1', promotion_id: 'p-1', code: 'SAVE20', max_uses: 5, uses: 2, remaining: 3, archived_at: null }] }],
      [/^loyalty\/accounts\?/, page([ACCOUNT])],
      [/^loyalty\/ageing\?/, page([{ ...ACCOUNT, ageing: { buckets: { '0_30': '50000', '31_60': '0', '61_90': '60000', '90_plus': '0' }, open_minor: '110000', credit_minor: '0' } }])],
      ['loyalty/accounts/a-1', { data: { ...ACCOUNT, entries: [{ id: 'e-1', kind: 'payment', amount: money('-40000'), balance: money('110000'), occurred_at: '2026-10-13T09:00:00Z', reference: 'MPESA-1', note: null, flags: [] }], ageing: { buckets: { '0_30': '50000', '31_60': '0', '61_90': '60000', '90_plus': '0' }, open_minor: '110000' }, statements: [{ id: 's-1', number: 'ST-2026-00001', period_from: '2026-10-01', period_to: '2026-10-13', closing: money('110000'), opening: money('100000') }] } }],
      [/^loyalty\/gift-cards\?/, page([CARD])],
      [/^(items|item-categories)\?/, { data: [] }],
      [/^companies\/c-1\/branches/, { data: [] }],
      ['companies/c-1/loyalty-programme', { data: { company_id: 'c-1', saved: true, active: true, currency: 'KES', earn_points: 1, earn_spend_minor: '10000', point_value_minor: '100', min_redeem_points: '0', expiry_months: 12, expiry_notice_days: 14, credit_warning_percent: 80, gift_card_low_minor: null, gift_card_item: null } }],
      ...routes,
    ],
    { permissions: tenantWide(permissions), modules: ['core', 'pos', 'loyalty'] },
  )
}

describe('Loyalty back office', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists promotions with their status as a dot and a word (LOY-08)', async () => {
    setup()
    renderApp('/loyalty/promotions')
    const row = (await screen.findByText('Happy hour drinks')).closest('tr')
    expect(within(row).getByText('Percentage off')).toBeInTheDocument()
    expect(within(row).getByText('Active').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(within(row).getByText('With a coupon')).toBeInTheDocument()
  })

  it('shows a promotion with its version, schedule and coupons, and saves a change (LOY-08, LOY-09)', async () => {
    setup()
    api.patch.mockResolvedValue({ data: { ...PROMOTION, percent: '25', version: 4 } })
    renderApp('/loyalty/promotions/p-1')
    expect(await screen.findByText('Version 3')).toBeInTheDocument()
    expect(await screen.findByText('SAVE20')).toBeInTheDocument()
    expect(screen.getByText('2 of 5')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Discount'), { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('loyalty/promotions/p-1', expect.objectContaining({ percent: '25', customer_tags: ['vip'], schedule: expect.objectContaining({ time_from: '17:00' }) })))
    expect(await screen.findByText('Saved as version 4. Tills get it with their next sync.')).toBeInTheDocument()
  })

  it('opens a credit account with its ledger, ageing and statements (LOY-14..LOY-18)', async () => {
    setup()
    renderApp('/loyalty/credit/a-1')
    expect(await screen.findByText('Duka Moja')).toBeInTheDocument()
    expect(screen.getByText('MPESA-1')).toBeInTheDocument()
    expect(screen.getByText('ST-2026-00001')).toBeInTheDocument()
    expect(screen.getByText('1 Oct 2026 – 13 Oct 2026')).toBeInTheDocument()
    expect(screen.getByText('KES 600.00')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Record payment' })).toBeInTheDocument()
  })

  it('shows the ageing report by bucket (LOY-18)', async () => {
    setup()
    renderApp('/loyalty/ageing')
    const row = (await screen.findByText('Duka Moja')).closest('tr')
    expect(within(row).getAllByText('600.00').length).toBeGreaterThan(0)
  })

  it('issues gift cards and shows their codes once (LOY-20)', async () => {
    setup()
    api.post.mockResolvedValue({ data: [{ ...CARD, id: 'g-2', status: 'inactive', code: 'ABCD-EFGH-JKMN-PQRS', balance: money('0') }] })
    renderApp('/loyalty/gift-cards')
    expect(await screen.findByText('•••• PQRS')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Issue cards' }))
    fireEvent.change(await screen.findByLabelText('Value'), { target: { value: '2500' } })
    fireEvent.blur(screen.getByLabelText('Value'))
    fireEvent.click(screen.getByRole('button', { name: 'Issue 1' }))
    expect(await screen.findByText('ABCD-EFGH-JKMN-PQRS')).toBeInTheDocument()
    expect(screen.getByText('Print or copy these codes now. They are not shown again.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('loyalty/gift-cards', expect.objectContaining({ company_id: 'c-1', amount_minor: '250000', currency: 'KES' }))
  })

  it('saves the programme (LOY-01)', async () => {
    setup()
    api.put.mockResolvedValue({ data: { company_id: 'c-1', saved: true, active: true, currency: 'KES', earn_points: 2, earn_spend_minor: '10000', point_value_minor: '100', min_redeem_points: '0', expiry_months: 12, expiry_notice_days: 14, credit_warning_percent: 80, gift_card_low_minor: null } })
    renderApp('/loyalty/programme')
    fireEvent.change(await screen.findByLabelText('Points earned'), { target: { value: '2' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save programme' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('companies/c-1/loyalty-programme', expect.objectContaining({ earn_points: 2, expiry_months: 12 })))
    expect(await screen.findByText('Programme saved.')).toBeInTheDocument()
  })

  it('lists flagged sales to review with each reason, filtered by reason, only with loyalty.sale.view (LOY-12, H2)', async () => {
    const SALE = { id: 's-9', company_id: 'c-1', receipt_number: 'R-WL2-000042', sold_at: '2026-10-13T14:30:00Z', party: { id: 'pt-1', name: 'Duka Moja' }, total: money('82500'), status: 'processed', points_earned: '8', voided: false,
      flags: [{ code: 'promotion_differs', detail: null }, { code: 'points_overdrawn', detail: null }] }
    setup([[/^loyalty\/sales\?/, page([SALE])]], [...ALL, 'loyalty.sale.view'])
    renderApp('/loyalty/review')
    const row = (await screen.findByText('R-WL2-000042')).closest('tr')
    expect(within(row).getByText('Promotion discount differs').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(row).getByText('Points overdrawn')).toBeInTheDocument()
    expect(within(row).getByText('Duka Moja')).toBeInTheDocument()
    expect(screen.getAllByText('Sales to review').length).toBeGreaterThan(0)

    openFilters()
    chooseOption('To review', 'Coupon used too often')
    await closeFilters()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('loyalty/sales?flag=coupon_overused&sort=-sold_at&per_page=25&page=1'))
  })

  it('hides the review list without loyalty.sale.view (LOY-12)', async () => {
    setup()
    renderApp('/loyalty/gift-cards')
    expect(await screen.findByText('•••• PQRS')).toBeInTheDocument()
    expect(screen.queryByText('Sales to review')).not.toBeInTheDocument()
  })

  it('hides the Loyalty menu without the module (RBAC-08)', async () => {
    mockRoutes(api, [[/^companies\?/, { data: [COMPANY] }]], { permissions: tenantWide(ALL), modules: ['core'] })
    renderApp('/')
    await screen.findAllByText('Dashboard')
    expect(screen.queryByText('Gift cards')).not.toBeInTheDocument()
  })
})
