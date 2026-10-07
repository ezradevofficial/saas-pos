import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import { apiError, mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

function fill(label, value) {
  fireEvent.change(screen.getByLabelText(label, { exact: false }), { target: { value } })
}

describe('Sign in', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
  })

  it('submits the login and password and signs the user in', async () => {
    api.post.mockResolvedValue({ token: 'new-token', user: OWNER, two_factor_enrollment_required: false })
    const { router } = renderApp('/sign-in?next=%2Fsettings%2Fsessions')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'correct horse')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/sessions'))
    expect(api.post).toHaveBeenCalledWith(
      'auth/sign-in',
      expect.objectContaining({ login: 'amina@example.com', password: 'correct horse' }),
    )
    expect(getToken()).toBe('new-token')
  })

  it('goes to the two-factor step when the account needs a second factor', async () => {
    api.post.mockResolvedValue({ status: 'two_factor_required', challenge_id: 'ch-2fa' })
    const { router } = renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'correct horse')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor'))
    expect(router.state.location.state).toEqual({ challengeId: 'ch-2fa' })
    expect(getToken()).toBeNull()
  })

  it('shows API field errors under the matching fields', async () => {
    api.post.mockRejectedValue(
      apiError(422, 'invalid_credentials', 'These details do not match.', { login: ['These details do not match.'] }),
    )
    renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'wrong')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    const login = screen.getByLabelText('Email or phone number', { exact: false })
    await waitFor(() => expect(login).toHaveAttribute('aria-invalid', 'true'))
    expect(document.getElementById(login.getAttribute('aria-describedby'))).toHaveTextContent('These details do not match.')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows other errors in an alert without the error code', async () => {
    api.post.mockRejectedValue(apiError(423, 'locked', 'Too many attempts. Try again in 15 minutes.'))
    renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'wrong')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts. Try again in 15 minutes.')
    expect(screen.queryByText(/locked/)).not.toBeInTheDocument()
  })

  it('sends an unverified user to the code step', async () => {
    const error = apiError(403, 'unverified', 'Verify your account first.')
    error.data = { challenge_id: 'ch-verify' }
    api.post.mockRejectedValue(error)
    const { router } = renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'correct horse')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/verify'))
    expect(router.state.location.state).toEqual({ challengeId: 'ch-verify' })
  })

  it('sends a token that may only enrol to the two-factor setup', async () => {
    api.post.mockResolvedValue({ token: 'enrol-token', user: OWNER, two_factor_enrollment_required: true })
    const { router } = renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fill('Password', 'correct horse')
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor/enrol'))
  })
})
