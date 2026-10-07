import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const fill = (label, value) => fireEvent.change(screen.getByLabelText(label, { exact: false }), { target: { value } })

describe('Forgot and reset password', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
  })

  it('asks for a code, sets a new password and returns to sign in', async () => {
    api.post.mockResolvedValue({ message: 'ok' })
    const { router } = renderApp('/sign-in')

    fill('Email or phone number', 'amina@example.com')
    fireEvent.click(screen.getByRole('link', { name: 'Forgot your password?' }))
    expect(await screen.findByRole('heading', { level: 1, name: 'Reset your password' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email or phone number', { exact: false })).toHaveValue('amina@example.com')
    fireEvent.click(screen.getByRole('button', { name: 'Send code' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/reset-password'))
    expect(api.post).toHaveBeenCalledWith('auth/password/forgot', { login: 'amina@example.com' })
    expect(await screen.findByText('Check your email or messages')).toBeInTheDocument()

    fill('6-digit code', '654321')
    fill('New password', 'a brand new pass')
    fireEvent.click(screen.getByRole('button', { name: 'Save new password' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(api.post).toHaveBeenLastCalledWith('auth/password/reset', {
      login: 'amina@example.com',
      code: '654321',
      password: 'a brand new pass',
    })
    expect(await screen.findByText('Your password has changed. Sign in with the new one.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email or phone number', { exact: false })).toHaveValue('amina@example.com')
  })

  it('shows a wrong code under the code field', async () => {
    api.post.mockRejectedValue(apiError(422, 'invalid_code', 'This code is wrong.', { code: ['This code is wrong.'] }))
    renderApp('/reset-password')
    fill('Email or phone number', 'amina@example.com')
    fill('6-digit code', '111111')
    fill('New password', 'a brand new pass')
    fireEvent.click(screen.getByRole('button', { name: 'Save new password' }))
    const code = screen.getByLabelText('6-digit code', { exact: false })
    await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'))
  })

  it('completes a two-factor sign-in', async () => {
    api.post.mockResolvedValue({ token: 't-2fa', user: OWNER, two_factor_enrollment_required: false })
    const { router } = renderApp('/sign-in')
    router.navigate('/two-factor', { state: { challengeId: 'ch-9' } })
    fireEvent.change(await screen.findByLabelText('6-digit code', { exact: false }), { target: { value: '222333' } })
    fireEvent.click(screen.getByRole('button', { name: 'Verify and sign in' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(api.post).toHaveBeenCalledWith('auth/two-factor/challenge', expect.objectContaining({ challenge_id: 'ch-9', code: '222333' }))
  })
})
