// AUTH-01..AUTH-05 (owner decision 2026-10-22, launch email-only): when the
// API reports no SMS (GET public/branding `meta.sms_available: false`), the
// sign-up, sign-in, password reset and two-factor screens ask for an email
// only and drop the text-message option, with a short explanation. While
// SMS is available (or the answer is unknown) they stay as they are.
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import i18n from '@/i18n'
import { mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const meta = (available) => ({ 'public/branding': { data: null, meta: { sms_available: available } } })
const PHONE_USER = { ...OWNER, phone: '+254712345678', phone_verified_at: '2026-10-01T08:00:00Z' }

describe('Email-only mode', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('asks for an email address at sign-up and explains why', async () => {
    mockApi(api, { extra: meta(false) })
    api.post.mockResolvedValue({ challenge_id: 'ch-1', destination_masked: 'a***@example.com' })
    renderApp('/sign-up?country=KE&locale=en')

    const field = await screen.findByLabelText('Email address', { exact: false })
    expect(field).toHaveAttribute('type', 'email')
    expect(screen.queryByLabelText('Email or phone number', { exact: false })).not.toBeInTheDocument()
    expect(screen.getByText('Text messages aren’t available yet, so use your email address.')).toBeInTheDocument()
    expect(screen.queryByText(/start with the country code/)).not.toBeInTheDocument()
  })

  it('keeps the phone option at sign-up while text messages are available', async () => {
    mockApi(api, { extra: meta(true) })
    renderApp('/sign-up?country=KE&locale=en')

    await waitFor(() => expect(api.get).toHaveBeenCalledWith('public/branding'))
    expect(await screen.findByLabelText('Email or phone number', { exact: false })).toBeInTheDocument()
    expect(screen.queryByText('Text messages aren’t available yet, so use your email address.')).not.toBeInTheDocument()
  })

  it('names the email at sign-in and in French', async () => {
    mockApi(api, { extra: meta(false) })
    renderApp('/sign-in')
    expect(await screen.findByLabelText('Email address', { exact: false })).toBeInTheDocument()
    expect(screen.queryByLabelText('Email or phone number', { exact: false })).not.toBeInTheDocument()

    await i18n.changeLanguage('fr')
    expect(await screen.findByLabelText('Adresse e-mail', { exact: false })).toBeInTheDocument()
  })

  it('sends reset codes to an email address only', async () => {
    mockApi(api, { extra: meta(false) })
    renderApp('/forgot-password')

    expect(await screen.findByText('Enter your email address and we will send you a code.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email address', { exact: false })).toHaveAttribute('type', 'email')
  })

  it('offers no text-message codes when setting up two-step sign-in', async () => {
    mockApi(api, { user: PHONE_USER, extra: meta(false) })
    api.post.mockResolvedValue({ token: 'enrol-token', user: PHONE_USER, two_factor_enrollment_required: true })
    const { router } = renderApp('/sign-in')

    fireEvent.change(await screen.findByLabelText('Email address', { exact: false }), { target: { value: 'amina@example.com' } })
    fireEvent.change(screen.getByLabelText('Password', { exact: false }), { target: { value: 'correct horse' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor/enrol'))
    expect(await screen.findByRole('button', { name: 'Use an authenticator app' })).toBeInTheDocument()
    // Signing in starts a fresh query cache: the option goes once the answer is back.
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Use text messages' })).not.toBeInTheDocument())
    expect(screen.getByText('Codes by text message aren’t available yet, so use an authenticator app.')).toBeInTheDocument()
  })

  it('offers text-message codes to a verified phone while text messages are available', async () => {
    mockApi(api, { user: PHONE_USER, extra: meta(true) })
    api.post.mockResolvedValue({ token: 'enrol-token', user: PHONE_USER, two_factor_enrollment_required: true })
    const { router } = renderApp('/sign-in')

    fireEvent.change(await screen.findByLabelText('Email or phone number', { exact: false }), { target: { value: 'amina@example.com' } })
    fireEvent.change(screen.getByLabelText('Password', { exact: false }), { target: { value: 'correct horse' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/two-factor/enrol'))
    expect(await screen.findByRole('button', { name: 'Use text messages' })).toBeInTheDocument()
  })
})
