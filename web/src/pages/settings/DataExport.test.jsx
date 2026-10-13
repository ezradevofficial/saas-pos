import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const READY = {
  id: 'e-1', status: 'ready', requested_by: { id: 'u-1', name: 'Amina' }, size: 2048, sha256: 'abc', tables: 90, rows: 1200, files: 3,
  failure: null, created_at: '2026-10-12T09:00:00Z', completed_at: '2026-10-12T09:01:00Z', expires_at: '2026-10-19T09:01:00Z', downloaded_at: null,
  download_url: 'http://localhost:8026/api/v1/tenant-exports/e-1/download?signature=x',
}
const FAILED = { ...READY, id: 'e-2', status: 'failed', size: null, expires_at: null, download_url: null, created_at: '2026-10-11T09:00:00Z' }
const META = { per_day: 3, link_hours: 24, keep_days: 7 }

describe('Data export page (EXP-02)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    mockRoutes(api, [['tenant-exports', { data: [READY, FAILED], meta: META }]], { permissions: tenantWide(['core.tenant.export']) })
  })

  it('lists past exports with their status, size and download, and says what is left out', async () => {
    renderApp('/settings/data-export')

    expect(await screen.findByRole('heading', { name: 'Data export' })).toBeInTheDocument()
    expect(await screen.findByText('Ready')).toBeInTheDocument()
    expect(screen.getByText('Failed')).toBeInTheDocument()
    expect(screen.getByText(/2 KB/)).toBeInTheDocument()
    expect(screen.getByText(/Secrets: password and PIN hashes/)).toBeInTheDocument()
    const links = screen.getAllByRole('link', { name: /Download the export/ })
    expect(links).toHaveLength(1)
    expect(links[0]).toHaveAttribute('href', READY.download_url)
  })

  it('requests a new export', async () => {
    api.post.mockResolvedValue({ data: { ...READY, id: 'e-3', status: 'queued', download_url: null } })
    renderApp('/settings/data-export')

    fireEvent.click(await screen.findByRole('button', { name: 'Request export' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('tenant-exports'))
  })

  it('shows the API refusal, such as the daily limit', async () => {
    api.post.mockRejectedValue(Object.assign(new Error('limit'), { status: 429, body: { message: 'You can request 3 exports in 24 hours.', code: 'export_limit' } }))
    renderApp('/settings/data-export')

    fireEvent.click(await screen.findByRole('button', { name: 'Request export' }))
    expect(await screen.findByRole('alert')).toBeInTheDocument()
  })

  it('is hidden from users without the permission', async () => {
    mockRoutes(api, [['tenant-exports', { data: [], meta: META }]], { permissions: tenantWide(['core.domain.manage']) })
    renderApp('/settings/data-export')

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Request export' })).not.toBeInTheDocument())
    expect(api.get).not.toHaveBeenCalledWith('tenant-exports')
  })
})
