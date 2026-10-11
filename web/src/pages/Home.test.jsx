// LAY-01: the home page draws the dashboard that applies to the signed-in user.
import { screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const DASHBOARD = {
  data: {
    payload: {
      title: 'Shop floor',
      widgets: [
        { id: 'w1', type: 'approval_count', source: 'approvals.waiting', params: {}, title: 'Waiting for me', x: 0, y: 0, w: 4, h: 1 },
        { id: 'w2', type: 'kpi', source: 'pos.sales_today', params: {}, title: 'Sales today', x: 4, y: 0, w: 4, h: 1 },
        { id: 'w3', type: 'gauge', source: 'pos.sales_today', params: {}, title: 'Unknown type', x: 8, y: 0, w: 4, h: 1 },
      ],
    },
    source: { scope: { type: 'role', id: 'r-1' } },
  },
}

describe('Home (LAY-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('renders the resolved dashboard and hides a widget whose source is refused', async () => {
    mockRoutes(api, [
      ['config/dashboard/resolved', DASHBOARD],
      [/^dashboard\/sources\/approvals\.waiting/, { data: { value: 3, to: '/approvals' } }],
      [/^dashboard\/sources\/pos\.sales_today/, apiError(403, 'forbidden', 'This action is unauthorised.')],
    ])
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Shop floor' })).toBeInTheDocument()
    const waiting = await screen.findByRole('region', { name: 'Waiting for me' })
    await waitFor(() => expect(waiting).toHaveTextContent('3'))
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('dashboard/sources/pos.sales_today'))
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Sales today' })).not.toBeInTheDocument())
    // An unknown widget type is skipped (LAY-07).
    expect(screen.queryByText('Unknown type')).not.toBeInTheDocument()
  })

  it('falls back to the default dashboard when none can be read', async () => {
    mockRoutes(api, [
      ['config/dashboard/resolved', apiError(500, 'server_error', 'Something went wrong.')],
      [/^dashboard\/sources\//, { data: { value: 0, links: [] } }],
    ])
    renderApp('/')
    expect(await screen.findByRole('region', { name: 'Approvals waiting for me' })).toBeInTheDocument()
  })
})
