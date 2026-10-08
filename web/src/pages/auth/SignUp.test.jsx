import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import i18n from '@/i18n'
import { apiError, mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'
import { contactPayload } from '@/lib/contact'
import { chooseOption } from '@/test/combobox'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const fill = (label, value) => fireEvent.change(screen.getByLabelText(label, { exact: false }), { target: { value } })

describe('Sign up and verify', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('sends an email address or a phone number', () => {
    expect(contactPayload(' amina@example.com ')).toEqual({ email: 'amina@example.com' })
    expect(contactPayload('+254 712 345-678')).toEqual({ phone: '+254712345678' })
  })

  it('creates the account, then verifies the code and lands on home', async () => {
    api.post.mockImplementation(async (path) => {
      if (path === 'auth/sign-up') return { challenge_id: 'ch-1', destination_masked: 'a***@example.com' }
      if (path === 'auth/verify') return { token: 'fresh', user: OWNER, two_factor_enrollment_required: false }
      throw new Error(path)
    })
    const { router } = renderApp('/sign-up')

    fill('Your name', 'Amina Otieno')
    fill('Email or phone number', 'amina@example.com')
    fill('New password', 'long enough pass')
    fill('Business name', 'Amani Retail')
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/verify'))
    expect(api.post).toHaveBeenCalledWith('auth/sign-up', {
      name: 'Amina Otieno',
      email: 'amina@example.com',
      password: 'long enough pass',
      business_name: 'Amani Retail',
      country: 'KE',
      locale: 'en',
    })
    expect(await screen.findByText('Enter the 6-digit code sent to a***@example.com.')).toBeInTheDocument()

    fill('6-digit code', '123 456')
    fireEvent.click(screen.getByRole('button', { name: 'Verify and continue' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(api.post).toHaveBeenCalledWith('auth/verify', expect.objectContaining({ challenge_id: 'ch-1', code: '123456' }))
    expect(getToken()).toBe('fresh')
  })

  it('shows an email or phone error under the contact field', async () => {
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Check the form.', { email: ['This email is already taken.'] }))
    renderApp('/sign-up')
    fill('Email or phone number', 'amina@example.com')
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))
    const contact = screen.getByLabelText('Email or phone number', { exact: false })
    await waitFor(() => expect(contact).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('This email is already taken.')).toBeInTheDocument()
  })

  it('switches the page to French when French is chosen', async () => {
    renderApp('/sign-up')
    chooseOption(screen.getByRole('combobox', { name: 'Language' }), 'Français')
    expect(await screen.findByRole('heading', { level: 1, name: 'Créez votre compte' })).toBeInTheDocument()
  })

  it('sends a new code and uses the new challenge', async () => {
    api.post.mockImplementation(async (path) => {
      if (path === 'auth/verify/resend') return { challenge_id: 'ch-2', destination_masked: 'a***@example.com' }
      if (path === 'auth/verify') throw apiError(422, 'invalid_code', 'This code is wrong or has expired.', { code: ['This code is wrong or has expired.'] })
      throw new Error(path)
    })
    const { router } = renderApp('/sign-up')
    router.navigate('/verify', { state: { challengeId: 'ch-1' } })

    fireEvent.click(await screen.findByRole('button', { name: 'Send a new code' }))
    expect(await screen.findByText('A new code is on its way.')).toBeInTheDocument()

    fill('6-digit code', '000000')
    fireEvent.click(screen.getByRole('button', { name: 'Verify and continue' }))
    expect(await screen.findByText('This code is wrong or has expired.')).toBeInTheDocument()
    expect(api.post).toHaveBeenLastCalledWith('auth/verify', expect.objectContaining({ challenge_id: 'ch-2' }))
  })
})
