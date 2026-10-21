import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api, getToken } from '@/api/client'
import i18n from '@/i18n'
import { apiError, mockApi, OWNER, renderApp, resetSession } from '@/test/renderApp'
import { contactPayload } from '@/lib/contact'

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
    const { router } = renderApp('/sign-up?country=KE&locale=en')

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

  it('creates a developer sandbox when asked from the developer portal (API-05)', async () => {
    api.post.mockResolvedValue({ challenge_id: 'ch-2', destination_masked: 'd***@example.com' })
    const { router } = renderApp('/sign-up?country=KE&locale=en&sandbox=1')

    expect(screen.getByRole('heading', { name: 'Create a developer sandbox' })).toBeInTheDocument()
    fill('Your name', 'Dev')
    fill('Email or phone number', 'dev@example.com')
    fill('New password', 'long enough pass')
    fill('Business name', 'Dev sandbox')
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/verify'))
    expect(api.post).toHaveBeenCalledWith('auth/sign-up', expect.objectContaining({ email: 'dev@example.com', sandbox: true }))
  })

  it('shows an email or phone error under the contact field', async () => {
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Check the form.', { email: ['This email is already taken.'] }))
    renderApp('/sign-up?country=KE&locale=en')
    fill('Email or phone number', 'amina@example.com')
    fireEvent.click(screen.getByRole('button', { name: 'Create account' }))
    const contact = screen.getByLabelText('Email or phone number', { exact: false })
    await waitFor(() => expect(contact).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('This email is already taken.')).toBeInTheDocument()
  })

  it('asks for the country and language first, then signs up with them (ONB-01)', async () => {
    api.post.mockResolvedValue({ challenge_id: 'ch-1', destination_masked: 'a***@example.com' })
    const { router } = renderApp('/sign-up')
    // Without a country and language, sign-up starts on /welcome.
    expect(await screen.findByRole('heading', { level: 1, name: 'Start your free trial' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /DR Congo/ }))
    fireEvent.click(within(screen.getAllByRole('group', { name: 'Language' }).find((group) => group.tagName === 'FIELDSET')).getByRole('button', { name: 'Français' }))
    expect(await screen.findByRole('heading', { level: 1, name: 'Commencez votre essai gratuit' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Continuer' }))

    await waitFor(() => expect(router.state.location.search).toBe('?country=CD&locale=fr'))
    expect(await screen.findByText(/Choisis : RD Congo · Français/)).toBeInTheDocument()
    fill('Votre nom', 'Amani Kabila')
    fill('E-mail ou numéro de téléphone', 'amani@example.com')
    fill('Nouveau mot de passe', 'long enough pass')
    fill("Nom de l'entreprise", 'Pharmacie Amani')
    fireEvent.click(screen.getByRole('button', { name: 'Créer le compte' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('auth/sign-up', expect.objectContaining({ country: 'CD', locale: 'fr' })))
  })

  it('sends a new code and uses the new challenge', async () => {
    api.post.mockImplementation(async (path) => {
      if (path === 'auth/verify/resend') return { challenge_id: 'ch-2', destination_masked: 'a***@example.com' }
      if (path === 'auth/verify') throw apiError(422, 'invalid_code', 'This code is wrong or has expired.', { code: ['This code is wrong or has expired.'] })
      throw new Error(path)
    })
    const { router } = renderApp('/sign-up?country=KE&locale=en')
    router.navigate('/verify', { state: { challengeId: 'ch-1' } })

    fireEvent.click(await screen.findByRole('button', { name: 'Send a new code' }))
    expect(await screen.findByText('A new code is on its way.')).toBeInTheDocument()

    fill('6-digit code', '000000')
    fireEvent.click(screen.getByRole('button', { name: 'Verify and continue' }))
    expect(await screen.findByText('This code is wrong or has expired.')).toBeInTheDocument()
    expect(api.post).toHaveBeenLastCalledWith('auth/verify', expect.objectContaining({ challenge_id: 'ch-2' }))
  })
})
