import { screen, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const money = (amount_minor, currency = 'KES') => ({ amount_minor, currency })
const named = (id, name) => ({ id, name })

const SHIFT = {
  id: 'sh-1',
  status: 'closed',
  opened_at: '2026-10-08T06:00:00Z',
  closed_at: '2026-10-08T15:00:00Z',
  company: named('c-1', 'Amani Retail'),
  branch: named('b-1', 'Westlands'),
  location: named('l-1', 'Front till'),
  device: named('d-1', 'Till 1'),
  opened_by: named('u-2', 'Joseph Mwangi'),
  closed_by: named('u-2', 'Joseph Mwangi'),
  note: null,
  sales_count: 14,
  balances: [
    { currency: 'KES', opening: money('500000'), expected: money('648750'), counted: money('640000'), variance: money('-8750') },
    { currency: 'USD', opening: money('0', 'USD'), expected: money('2000', 'USD'), counted: money('2000', 'USD'), variance: money('0', 'USD') },
  ],
}

const DETAIL = {
  ...SHIFT,
  received_after_close: 2,
  cash_movements: [
    { id: 'm-1', kind: 'pay_out', status: 'held', flags: [{ code: 'actor_unverified' }], user: named('u-2', 'Joseph Mwangi'), approver: null, amount: money('20000'), reason: 'Taxi', occurred_at: '2026-10-08T11:00:00Z' },
  ],
}

function setup() {
  mockRoutes(
    api,
    [
      [/^pos\/shifts\?/, { data: [SHIFT], meta: { total: 1, last_page: 1, from: 1, to: 1 } }],
      ['pos/shifts/sh-1', { data: DETAIL }],
      [/^(branches|locations)\?/, { data: [] }],
    ],
    { permissions: tenantWide(['core.company.view', 'pos.shift.view']), modules: ['core', 'pos'] },
  )
}

describe('POS shifts (POS-04, POS-12)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists shifts with the opening float and the variance per currency as a dot and a word', async () => {
    setup()
    renderApp('/pos/shifts')
    const row = (await screen.findByText('Front till')).closest('tr')
    expect(within(row).getByText('Till 1')).toBeInTheDocument()
    expect(within(row).getByText('KES 5,000.00 · USD 0.00')).toBeInTheDocument()
    const short = within(row).getByText('Short by KES 87.50')
    expect(short.closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(within(row).getByText('USD balanced').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    expect(api.get).toHaveBeenCalledWith('pos/shifts?sort=-opened_at&per_page=25&page=1')
  })

  it('opens a shift with cash per currency, movements, sales count and the received-after-close note', async () => {
    setup()
    renderApp('/pos/shifts/sh-1')
    expect(await screen.findByText('2 records reached the server after this shift closed.')).toBeInTheDocument()
    const balances = screen.getByRole('table', { name: 'Cash per currency' })
    expect(within(balances).getByText('6,487.50')).toBeInTheDocument()
    expect(within(balances).getByText('Short by KES 87.50')).toBeInTheDocument()
    const movements = screen.getByRole('table', { name: 'Cash movements' })
    expect(within(movements).getByText('Pay-out')).toBeInTheDocument()
    expect(within(movements).getByText('Taxi')).toBeInTheDocument()
    expect(within(movements).getByText('Held').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(movements).getByText('Actor unverified')).toBeInTheDocument()
    expect(screen.getByText('14')).toBeInTheDocument()
  })
})
