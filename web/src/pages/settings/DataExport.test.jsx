import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { browser } from '@/lib/browser'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const READY = {
  id: 'e-1', status: 'ready', requested_by: { id: 'u-1', name: 'Amina' }, size: 2048, sha256: 'abc', tables: 90, rows: 1200, files: 3,
  failure: null, created_at: '2026-10-12T09:00:00Z', completed_at: '2026-10-12T09:01:00Z', expires_at: '2026-10-19T09:01:00Z', downloaded_at: null,
  can_download: true,
}
const OTHERS = { ...READY, id: 'e-3', requested_by: { id: 'u-2', name: 'Baraka' }, can_download: false, created_at: '2026-10-10T09:00:00Z' }
const FAILED = { ...READY, id: 'e-2', status: 'failed', failure: 'too_large', size: null, expires_at: null, can_download: false, created_at: '2026-10-11T09:00:00Z' }
const META = { per_day: 3, link_minutes: 5, keep_days: 7 }
const LINK = 'http://localhost:8026/api/v1/tenant-exports/e-1/download?signature=x'

describe('Data export page (EXP-02)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    mockRoutes(api, [['tenant-exports', { data: [READY, FAILED, OTHERS], meta: META }]], { permissions: tenantWide(['core.tenant.export']) })
  })

  it('lists past exports with their status and size, and says what is left out', async () => {
    renderApp('/settings/data-export')

    expect(await screen.findByRole('heading', { name: 'Data export' })).toBeInTheDocument()
    expect((await screen.findAllByText('Ready')).length).toBe(2)
    expect(screen.getByText('Failed')).toBeInTheDocument()
    expect(screen.getByText(/larger than an export may be/)).toBeInTheDocument()
    expect(screen.getAllByText(/2 KB/).length).toBeGreaterThan(0)
    expect(screen.getByText(/Secrets: password and PIN hashes/)).toBeInTheDocument()
    // Only the requester's own ready export can be downloaded; nothing is a plain link.
    expect(screen.getAllByRole('button', { name: /Download the export/ })).toHaveLength(1)
    expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument()
  })

  it('mints a short-lived link on Download and follows it', async () => {
    const go = vi.spyOn(browser, 'go').mockImplementation(() => {})
    api.post.mockResolvedValue({ data: { url: LINK, expires_at: '2026-10-12T09:06:00Z' } })
    renderApp('/settings/data-export')

    fireEvent.click(await screen.findByRole('button', { name: /Download the export/ }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('tenant-exports/e-1/link'))
    await waitFor(() => expect(go).toHaveBeenCalledWith(LINK))
    go.mockRestore()
  })

  it('requests a new export after confirming the password', async () => {
    api.post.mockResolvedValue({ data: { ...READY, id: 'e-4', status: 'queued', can_download: false } })
    renderApp('/settings/data-export')

    fireEvent.click(await screen.findByRole('button', { name: 'Request export' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('Confirm with your password')
    fireEvent.change(screen.getByLabelText(/Your password/), { target: { value: 'Tusker-Lager-2026' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Request export' }).at(-1))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('tenant-exports', { password: 'Tusker-Lager-2026' }))
  })

  it('shows a wrong password on the field', async () => {
    api.post.mockRejectedValue(
      Object.assign(new Error('The password is incorrect.'), { status: 422, code: 'invalid_password', errors: { password: ['The password is incorrect.'] } }),
    )
    renderApp('/settings/data-export')

    fireEvent.click(await screen.findByRole('button', { name: 'Request export' }))
    fireEvent.change(await screen.findByLabelText(/Your password/), { target: { value: 'wrong' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Request export' }).at(-1))
    expect(await screen.findByText('The password is incorrect.')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('is hidden from users without the permission', async () => {
    mockRoutes(api, [['tenant-exports', { data: [], meta: META }]], { permissions: tenantWide(['core.domain.manage']) })
    renderApp('/settings/data-export')

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Request export' })).not.toBeInTheDocument())
    expect(api.get).not.toHaveBeenCalledWith('tenant-exports')
  })

  it('checks a building export every 2 seconds, then stops once it is ready', async () => {
    let calls = 0
    mockRoutes(api, [['tenant-exports', () => {
      calls += 1
      return { data: [calls === 1 ? { ...READY, id: 'e-4', status: 'running', can_download: false } : { ...READY, id: 'e-4' }], meta: META }
    }]], { permissions: tenantWide(['core.tenant.export']) })
    renderApp('/settings/data-export')

    expect(await screen.findByText('Preparing')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Ready')).toBeInTheDocument(), { timeout: 2600 })
    const settled = calls
    await new Promise((resolve) => setTimeout(resolve, 2300))
    expect(calls).toBe(settled)
  }, 10000)
})

