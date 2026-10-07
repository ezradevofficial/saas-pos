import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import { apiError, mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const TOKEN = 'a'.repeat(40)
const INVITATION = { tenant_name: 'Amani Retail', name: 'Peter Mwangi', email: 'peter@example.com', phone: null, expires_at: '2026-10-14T00:00:00Z' }

describe('Accept invitation', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api, { extra: { [`auth/invitations/${TOKEN}`]: INVITATION } })
  })

  it('accepts the invitation and signs the new user in', async () => {
    api.post.mockResolvedValue({ token: 'peter-token', user: { ...OWNER, id: 'u-2', name: 'Peter Mwangi' } })
    const { router } = renderApp(`/invitations/${TOKEN}`)

    expect(await screen.findByText('Amani Retail invited you. Choose a password to finish setting up your account.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('New password', { exact: false }), { target: { value: 'a long password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Accept and sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(api.post).toHaveBeenCalledWith(`auth/invitations/${TOKEN}/accept`, { name: 'Peter Mwangi', password: 'a long password' })
    expect(getToken()).toBe('peter-token')
  })

  it('asks a signed-in user to sign out first, then shows the invitation', async () => {
    signedIn()
    api.post.mockResolvedValue(null)
    const { router } = renderApp(`/invitations/${TOKEN}`)

    expect(await screen.findByText('You are signed in as Amina Otieno')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sign out and accept' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('auth/sign-out'))
    expect(await screen.findByRole('button', { name: 'Accept and sign in' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe(`/invitations/${TOKEN}`)
    expect(getToken()).toBeNull()
  })

  it('explains a stale invitation in the web’s own sentence', async () => {
    api.post.mockRejectedValue(apiError(422, 'invitation_stale', 'stale (API text)'))
    renderApp(`/invitations/${TOKEN}`)
    fireEvent.change(await screen.findByLabelText('New password', { exact: false }), { target: { value: 'a long password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Accept and sign in' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This invitation no longer matches your organisation’s setup. Ask your administrator to send a new one.',
    )
  })
})
