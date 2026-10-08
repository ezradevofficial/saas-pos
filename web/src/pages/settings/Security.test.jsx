import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, ALL_CORE, mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const SETTINGS = { data: { password_min_length: 8, session_timeout_minutes: 60, default_locale: 'en' } }
const EDITOR = [...ALL_CORE, { name: 'core.settings.edit', scopes: [{ type: 'tenant', id: 't-1' }] }]

function settings(permissions = EDITOR) {
  mockApi(api, { permissions, extra: { 'tenant/settings': SETTINGS } })
}

const mainNav = async () => (await screen.findAllByRole('navigation', { name: 'Main' }))[0]

// AUTH-02, AUTH-09, L10N-01: the tenant settings page.
describe('Security settings', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the settings, saves changes as numbers and confirms', async () => {
    settings()
    api.patch.mockResolvedValue({ data: { password_min_length: 12, session_timeout_minutes: 30, default_locale: 'fr' } })
    renderApp('/settings/security')

    const nav = await mainNav()
    expect(await within(nav).findByRole('link', { name: 'Security' })).toBeInTheDocument()
    const minimum = await screen.findByLabelText(/Minimum password length/)
    expect(minimum).toHaveValue(8)
    expect(screen.getByLabelText(/Session timeout/)).toHaveValue(60)
    expect(screen.getByLabelText(/Default language/)).toHaveValue('en')

    fireEvent.change(minimum, { target: { value: '12' } })
    fireEvent.change(screen.getByLabelText(/Session timeout/), { target: { value: '30' } })
    fireEvent.change(screen.getByLabelText(/Default language/), { target: { value: 'fr' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('tenant/settings', { password_min_length: 12, session_timeout_minutes: 30, default_locale: 'fr' }),
    )
    expect(await screen.findByText('Settings saved.')).toBeInTheDocument()
  })

  it('shows a refused value under its field', async () => {
    settings()
    api.patch.mockRejectedValue(
      apiError(422, 'validation_failed', 'Some fields need attention.', {
        session_timeout_minutes: ['The session timeout field must be between 15 and 480.'],
      }),
    )
    renderApp('/settings/security')

    const timeout = await screen.findByLabelText(/Session timeout/)
    fireEvent.change(timeout, { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('The session timeout field must be between 15 and 480.')).toBeInTheDocument()
    expect(timeout).toHaveAttribute('aria-invalid', 'true')
    expect(api.patch).toHaveBeenCalledWith('tenant/settings', expect.objectContaining({ session_timeout_minutes: 5 }))
  })

  it('is hidden and refused when the permission is held only below tenant scope', async () => {
    settings([...ALL_CORE, { name: 'core.settings.edit', scopes: [{ type: 'branch', id: 'b-1' }] }])
    renderApp('/settings/security')

    expect(await screen.findByRole('heading', { level: 1, name: 'You do not have access to this page' })).toBeInTheDocument()
    const nav = await mainNav()
    await within(nav).findByRole('link', { name: 'Users' })
    expect(within(nav).queryByRole('link', { name: 'Security' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('tenant/settings')
  })
})
