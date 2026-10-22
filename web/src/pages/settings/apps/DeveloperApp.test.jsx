import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const APP = {
  id: 'app-1', slug: 'stock-sync', status: 'active', name: 'Stock sync', client_id: 'c-1',
  versions: [{ id: 'v-1', version: 1, status: 'approved', scopes: ['core.item.read'], submitted_at: '2026-10-16T08:00:00Z', review_note: null }],
}

// MKT-01 (security review 6): a leaked webhook secret can be replaced with no overlap.
describe('DeveloperApp', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    mockRoutes(api, [
      ['developer/apps/app-1', { data: APP }],
      ['developer', { data: { id: 'd-1', name: 'Acme Labs', catalogue: { scopes: [], events: [], categories: [] } } }],
    ], { permissions: tenantWide(['core.developer.manage']) })
  })

  it('rotates the webhook secret with an overlap, or ends the old one at once', async () => {
    api.post.mockResolvedValue({ data: APP })
    renderApp('/settings/developer-apps/app-1')

    fireEvent.click(await screen.findByRole('button', { name: 'New webhook secret' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('developer/apps/app-1/webhook-secret'))

    fireEvent.click(await screen.findByRole('button', { name: 'New webhook secret, end the old one now' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('developer/apps/app-1/webhook-secret', { immediate: true }))
  })
})
