// NFR-08 (KE DPA): a marketing email's unsubscribe page works signed out, only
// reads the link when opened, withdraws on one click, and speaks the email's language.
import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { setLocale } from '@/i18n'
import { apiError, mockRoutes, renderApp, resetSession } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

describe('Unsubscribe page (NFR-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
  })
  afterEach(() => act(() => setLocale('en')))

  it('reads the link, then withdraws on one click', async () => {
    mockRoutes(api, [['opt-out/tok-1', { data: { status: 'active', channel: 'email', locale: 'en' } }]])
    api.post.mockResolvedValue({ data: { status: 'withdrawn', channel: 'email', locale: 'en' } })
    renderApp('/opt-out/tok-1?lang=en')

    const button = await screen.findByRole('button', { name: 'Stop marketing emails' })
    await waitFor(() => expect(button).toBeEnabled())
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(button)

    expect(await screen.findByRole('heading', { name: 'You are unsubscribed' })).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('opt-out/tok-1')
  })

  it('shows in the email’s language and says when it was already used', async () => {
    mockRoutes(api, [['opt-out/tok-2', { data: { status: 'done', channel: 'email', locale: 'fr' } }]])
    renderApp('/opt-out/tok-2?lang=fr')

    expect(await screen.findByRole('heading', { name: 'Vous êtes désinscrit' })).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('explains a link that does not work', async () => {
    mockRoutes(api, [['opt-out/bad', () => { throw apiError(404, 'opt_out_link_invalid', 'This unsubscribe link doesn’t work.') }]])
    renderApp('/opt-out/bad')

    expect(await screen.findByText('This unsubscribe link doesn’t work.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Stop marketing emails' })).not.toBeInTheDocument()
  })
})
