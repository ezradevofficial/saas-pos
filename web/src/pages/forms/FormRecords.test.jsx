import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { COMPANY, FORM_PERMISSIONS, formRoutes, PETTY_CASH } from './fixtures'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const ROW = {
  id: 'r-1',
  number: 'PETTY_CASH-2026-00001',
  status: 'pending',
  company: { id: 'c-1', name: 'Acme' },
  branch: { id: 'b-1', name: 'Branch A' },
  location: null,
  custom: {},
  totals: {},
  amount: { amount_minor: '280050', currency: 'KES' },
  created_by: { id: 'u-1', name: 'Amina Otieno' },
  created_at: '2026-10-01T08:00:00Z',
  archived_at: null,
}

function setUp(rows = [ROW], types = [PETTY_CASH]) {
  mockRoutes(api, formRoutes([
    ['custom-form-types', { data: types }],
    [/^custom-form-types\/ft-1\/records/, { data: rows, meta: { total: rows.length, current_page: 1, last_page: 1, per_page: 25 } }],
    [/^custom-fields\/schema/, { data: [] }],
    [/^list-views/, { data: [] }],
    [/^config\//, { data: null }],
  ]), { permissions: tenantWide(FORM_PERMISSIONS), companies: [COMPANY] })
}

describe('The records of a custom form (CF-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the records with their number, status, place and amount', async () => {
    setUp()
    renderApp('/forms/petty_cash')
    expect(await screen.findByRole('heading', { name: 'Petty cash request' })).toBeInTheDocument()
    expect(await screen.findByText('PETTY_CASH-2026-00001')).toBeInTheDocument()
    expect(screen.getByText('Waiting for approval')).toBeInTheDocument()
    expect(screen.getByText('Branch A · Acme')).toBeInTheDocument()
    expect(screen.getByText('2,800.50')).toBeInTheDocument()
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => path.startsWith('custom-form-types/ft-1/records'))).toBe(true))
  })

  it('opens a new record', async () => {
    setUp([])
    const { router } = renderApp('/forms/petty_cash')
    fireEvent.click(await screen.findByRole('button', { name: 'New form' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/forms/petty_cash/new'))
  })

  it('says so when the form doesn’t exist for the user', async () => {
    setUp([], [])
    renderApp('/forms/petty_cash')
    expect(await screen.findByText('This form doesn’t exist, or you don’t have access to it. Ask an administrator.')).toBeInTheDocument()
  })
})
