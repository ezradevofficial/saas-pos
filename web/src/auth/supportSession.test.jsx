import { screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, OWNER, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const CHECKLIST = { visible: true, dismissed: false, done: 0, total: 1, items: [{ key: 'company', done: false, video_url: null }] }
const session = (mode) => ({ ...OWNER, name: 'Wanjiru (support)', support_session: { mode, expires_at: '2026-10-22T12:00:00Z' } })

function setUp(user) {
  mockRoutes(
    api,
    [
      ['onboarding/checklist', { data: CHECKLIST }],
      ['onboarding/trial', { data: { state: 'none', days_left: 0 } }],
      [/^dashboard/, { data: { widgets: [] } }],
      [/^config/, { data: [] }],
      [/^approvals/, { data: [], meta: { total: 0 } }],
      [/^me\/delegations/, { data: [] }],
    ],
    { user, permissions: tenantWide(['core.onboarding.view', 'core.onboarding.edit', 'core.approval.view']) },
  )
}

// RBAC-13, Phase 8 walkthrough: a read-only support session saw "Customise my dashboard"
// and "Getting started" (writes no permission guards); the API refuses them, the UI hides them.
describe('Support sessions in the back office (RBAC-13)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    vi.clearAllMocks()
  })

  it('hides the dashboard designer and the setup checklist in a read-only session', async () => {
    setUp(session('read'))
    renderApp('/')
    expect(await screen.findByText('Support session')).toBeInTheDocument()
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^dashboard/)))
    expect(screen.queryByRole('button', { name: 'Customise my dashboard' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Getting started' })).not.toBeInTheDocument()
  })

  it('sends a read-only session away from the dashboard designer', async () => {
    setUp(session('read'))
    const { router } = renderApp('/dashboard/customise')
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })

  it('keeps them for a session the tenant allowed to make changes', async () => {
    setUp(session('write'))
    renderApp('/')
    expect(await screen.findByRole('button', { name: 'Customise my dashboard' })).toBeInTheDocument()
  })

  it('never offers delegation in a support session, which never approves', async () => {
    setUp(session('write'))
    renderApp('/approvals')
    expect(await screen.findByRole('heading', { level: 1, name: 'Approvals' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Set up delegation' })).not.toBeInTheDocument()
  })
})
