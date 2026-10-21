import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import { saveBinding } from '@/auth/sso'
import { apiError, mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

// AUTH-11, ADR 023: Continue with SSO on the sign-in page, and the page the
// identity provider's callback lands on.
describe('Single sign-on', () => {
  const assign = vi.fn()

  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
    window.sessionStorage.clear()
    vi.stubGlobal('location', { ...window.location, assign, origin: 'http://localhost:3000', host: 'localhost:3000', hash: '', pathname: '/sign-in' })
  })

  afterEach(() => vi.unstubAllGlobals())

  it('starts by work email and leaves for the identity provider, keeping the binding in this tab', async () => {
    api.post.mockResolvedValue({ data: { redirect_url: 'https://login.microsoftonline.com/authorize?state=s', binding: 'b'.repeat(43) } })
    renderApp('/sign-in')

    fireEvent.change(screen.getByLabelText('Email or phone number', { exact: false }), { target: { value: 'amina@acme.co.ke' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Continue with SSO' }))
    // The email typed for the password carries over.
    expect(screen.getByLabelText('Work email', { exact: false })).toHaveValue('amina@acme.co.ke')
    fireEvent.click(screen.getByRole('button', { name: 'Continue to sign-in' }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://login.microsoftonline.com/authorize?state=s'))
    expect(api.post).toHaveBeenCalledWith('auth/sso/start', { email: 'amina@acme.co.ke', origin: 'http://localhost:3000' })
    expect(window.sessionStorage.getItem('app.sso.binding')).toBe('b'.repeat(43))
  })

  it('lets the person choose when the organisation has several connections', async () => {
    api.post
      .mockResolvedValueOnce({ data: { connections: [{ id: 'c-1', name: 'Google', provider: 'google' }, { id: 'c-2', name: 'Microsoft', provider: 'entra' }] } })
      .mockResolvedValueOnce({ data: { redirect_url: 'https://accounts.google.com/auth', binding: 'x'.repeat(43) } })
    renderApp('/sign-in')

    fireEvent.click(await screen.findByRole('button', { name: 'Continue with SSO' }))
    fireEvent.change(screen.getByLabelText('Work email', { exact: false }), { target: { value: 'amina@acme.co.ke' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue to sign-in' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Sign in with Microsoft' }))

    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://accounts.google.com/auth'))
    expect(api.post).toHaveBeenLastCalledWith('auth/sso/start', expect.objectContaining({ email: 'amina@acme.co.ke', connection_id: 'c-2' }))
  })

  it('shows the API message when no organisation uses single sign-on for the email', async () => {
    api.post.mockRejectedValue(apiError(404, 'sso_not_found', 'Single sign-on is not set up for this email address.', { email: ['Single sign-on is not set up for this email address.'] }))
    renderApp('/sign-in')

    fireEvent.click(await screen.findByRole('button', { name: 'Continue with SSO' }))
    fireEvent.change(screen.getByLabelText('Work email', { exact: false }), { target: { value: 'someone@elsewhere.example' } })
    fireEvent.click(screen.getByRole('button', { name: 'Continue to sign-in' }))

    expect(await screen.findByText('Single sign-on is not set up for this email address.')).toBeInTheDocument()
    expect(assign).not.toHaveBeenCalled()
  })

  it('points a password sign-in that is refused by enforcement to single sign-on', async () => {
    api.post.mockRejectedValue(apiError(403, 'sso_required', 'Your organisation signs in with single sign-on.'))
    renderApp('/sign-in')

    fireEvent.change(screen.getByLabelText('Email or phone number', { exact: false }), { target: { value: 'amina@acme.co.ke' } })
    fireEvent.change(screen.getByLabelText('Password', { exact: false }), { target: { value: 'secret' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Your organisation uses single sign-on')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Continue with SSO' })).toBeInTheDocument()
  })

  it('completes with the ticket from the fragment and this tab’s binding', async () => {
    saveBinding('b'.repeat(43))
    vi.stubGlobal('location', { ...window.location, assign, hash: `#ticket=${'t'.repeat(48)}`, pathname: '/sso/complete' })
    api.post.mockResolvedValue({ token: 'sso-token', user: OWNER, two_factor_enrollment_required: false })
    const { router } = renderApp('/sso/complete')

    await waitFor(() => expect(getToken()).toBe('sso-token'))
    expect(api.post).toHaveBeenCalledWith('auth/sso/complete', expect.objectContaining({ ticket: 't'.repeat(48), binding: 'b'.repeat(43) }))
    expect(window.sessionStorage.getItem('app.sso.binding')).toBeNull()
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })

  it('goes to the two-factor step when the identity provider asserted no MFA', async () => {
    saveBinding('b'.repeat(43))
    vi.stubGlobal('location', { ...window.location, assign, hash: `#ticket=${'t'.repeat(48)}`, pathname: '/sso/complete' })
    api.post.mockResolvedValue({ status: 'two_factor_required', challenge_id: 'ch-1' })
    const { router } = renderApp('/sso/complete')

    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor'))
    expect(router.state.location.state).toEqual({ challengeId: 'ch-1' })
  })

  it('shows why the sign-in failed on the sign-in page', async () => {
    vi.stubGlobal('location', { ...window.location, assign, hash: '#error=no_account', pathname: '/sso/complete' })
    const { router } = renderApp('/sso/complete')

    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(await screen.findByText('No account in this organisation uses this email. Ask your administrator for an invitation.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('refuses a ticket without this tab’s binding without calling the API', async () => {
    vi.stubGlobal('location', { ...window.location, assign, hash: `#ticket=${'t'.repeat(48)}`, pathname: '/sso/complete' })
    renderApp('/sso/complete')

    expect(await screen.findByText('This sign-in has expired or was already used. Start again.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })
})
