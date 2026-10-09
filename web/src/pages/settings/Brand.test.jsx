import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const THEME_PERMISSIONS = tenantWide(['core.company.view', 'core.theme.view', 'core.theme.edit', 'core.theme.publish'])

const documentWith = (payload, problems = []) => ({
  data: {
    id: 'doc-1',
    kind: 'theme',
    key: 'default',
    scope: { type: 'tenant', id: null },
    published: null,
    draft: { id: 'v-1', version: 1, status: 'draft', source: 'draft', payload, revision: 1 },
    history: [],
  },
  meta: { problems },
})

function setup({ documents = [], document = null, permissions = THEME_PERMISSIONS } = {}) {
  mockRoutes(
    api,
    [
      ['config/theme?key=default&per_page=200', { data: documents }],
      ['config/theme/doc-1', () => document],
      [/^config\/theme\/resolved/, { data: { kind: 'theme', key: 'default', payload: { preset: 'light', asset_urls: {} }, source: null } }],
      ['branding/assets', { data: [] }],
      ['branches?per_page=200', { data: [] }],
    ],
    { permissions },
  )
}

describe('Brand page (BR-02, BR-03, BR-08)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    vi.useRealTimers()
  })

  it('shows derived values and a failing contrast meter with a plain explanation, and saves the draft', async () => {
    setup()
    api.post.mockResolvedValue(documentWith({ preset: 'light', colors: { primary: '#ffcc00' } }))
    renderApp('/settings/brand')

    const primary = await screen.findByTestId('colour-primary')
    fireEvent.change(within(primary).getByRole('textbox'), { target: { value: '#ffcc00' } })

    // The meters are computed in the browser with the same maths as the API.
    expect(await within(primary).findByRole('alert')).toHaveTextContent(/choose a darker or lighter/i)
    expect(within(primary).getAllByText('Fails').length).toBeGreaterThan(0)
    expect(within(primary).getByText(/1\.46:1/)).toBeInTheDocument()
    expect(screen.getByText(/can't be published yet/i)).toBeInTheDocument()
    expect(within(primary).getByText('Hover')).toBeInTheDocument()

    // Saved as a draft a moment later, with only the base colour (hover and tint are derived).
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('config/theme', expect.objectContaining({ scope_type: 'tenant', payload: { preset: 'light', colors: { primary: '#ffcc00' } } })), { timeout: 3000 })
  })

  it('previews the draft on real components without changing the app', async () => {
    setup()
    api.post.mockResolvedValue(documentWith({ preset: 'light', colors: { primary: '#7c2d12' } }))
    renderApp('/settings/brand')

    const primary = await screen.findByTestId('colour-primary')
    fireEvent.change(within(primary).getByRole('textbox'), { target: { value: '#7c2d12' } })

    const preview = screen.getByTestId('brand-preview')
    await waitFor(() => expect(preview.style.getPropertyValue('--primary')).toBe('#7c2d12'))
    expect(within(preview).getByRole('button', { name: /charge/i })).toBeInTheDocument()
    expect(document.documentElement.style.getPropertyValue('--primary')).toBe('')

    fireEvent.click(screen.getByRole('button', { name: 'Dark' }))
    expect(preview.dataset.mode).toBe('dark')
    expect(preview.style.getPropertyValue('--primary')).not.toBe('#7c2d12')
  })

  it('is read-only without core.theme.edit', async () => {
    setup({ permissions: tenantWide(['core.company.view', 'core.theme.view']) })
    renderApp('/settings/brand')
    expect(await screen.findByText(/view this theme but not change it/i)).toBeInTheDocument()
    expect(within(screen.getByTestId('colour-primary')).getByRole('textbox')).toBeDisabled()
  })
})
