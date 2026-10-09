import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, OWNER, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const status = (extra = {}) => ({ data: { pin_set: false, card_set: false, must_change: false, set_at: null, six_digits: false, ...extra } })
const JOSEPH = { id: 'u-2', name: 'Joseph Mwangi', email: 'joseph@example.com', phone: null, locale: 'en', status: 'active', roles: [] }

describe('My POS PIN (AUTH-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('sets one’s own PIN with the password, asking for 6 digits when the user approves overrides', async () => {
    mockRoutes(api, [['me/pos-pin', status({ six_digits: true })]], { modules: ['core', 'pos'] })
    api.put.mockRejectedValueOnce(apiError(422, 'validation_failed', 'Some fields need attention.', { pin: ['This PIN is too easy to guess.'] })).mockResolvedValueOnce({ message: 'Your POS PIN is saved.', data: {} })
    renderApp('/settings/pos-pin')

    expect(await screen.findByText('No PIN')).toBeInTheDocument()
    expect(screen.getByText(/so your PIN needs 6 digits/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Set PIN' }))
    const dialog = await screen.findByRole('dialog', { name: 'Set your POS PIN' })
    fireEvent.change(within(dialog).getByLabelText(/Your account password/), { target: { value: 'correct horse' } })
    fireEvent.change(within(dialog).getByLabelText(/New PIN/), { target: { value: '4826' } })
    fireEvent.change(within(dialog).getByLabelText(/Repeat the PIN/), { target: { value: '4826' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save PIN' }))
    // Four digits are refused before anything is sent.
    expect(await within(dialog).findByText('Enter a PIN of 6 digits.')).toBeInTheDocument()
    expect(api.put).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText(/New PIN/), { target: { value: '123456' } })
    fireEvent.change(within(dialog).getByLabelText(/Repeat the PIN/), { target: { value: '123456' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save PIN' }))
    expect(await within(dialog).findByText('This PIN is too easy to guess.')).toBeInTheDocument()

    fireEvent.change(within(dialog).getByLabelText(/New PIN/), { target: { value: '482619' } })
    fireEvent.change(within(dialog).getByLabelText(/Repeat the PIN/), { target: { value: '482619' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save PIN' }))
    await waitFor(() => expect(api.put).toHaveBeenLastCalledWith('me/pos-pin', { password: 'correct horse', pin: '482619' }))
    expect(await screen.findByText('Your POS PIN is saved.')).toBeInTheDocument()
  })

  it('never shows the PIN and removes it with the password', async () => {
    mockRoutes(api, [['me/pos-pin', status({ pin_set: true, set_at: '2026-10-08T09:00:00Z' })]], { modules: ['core', 'pos'] })
    api.delete.mockResolvedValue({ data: {} })
    renderApp('/settings/pos-pin')

    expect(await screen.findByText('PIN set')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Remove PIN' }))
    const dialog = await screen.findByRole('dialog', { name: 'Remove your POS PIN?' })
    fireEvent.change(within(dialog).getByLabelText(/Your account password/), { target: { value: 'correct horse' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove PIN' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('me/pos-pin', { password: 'correct horse' }))
  })
})

describe('A user’s POS PIN for administrators (AUTH-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  function detail(user = JOSEPH, pin = status({ pin_set: true, must_change: true, set_at: '2026-10-08T09:00:00Z' }), modules = ['core', 'pos']) {
    mockRoutes(
      api,
      [
        [`users/${user.id}`, { data: user }],
        [`users/${user.id}/pos-pin`, pin],
        [/^users\/.*\/assignments/, { data: [], meta: { total: 0, last_page: 1 } }],
        [/^(roles|branches|locations)\?/, { data: [] }],
      ],
      { permissions: tenantWide(['core.user.view', 'core.user.edit']), modules },
    )
  }

  it('shows whether a PIN is set and must be changed, and resets it with the admin’s own password', async () => {
    detail()
    api.put.mockResolvedValue({ message: 'The POS PIN is reset. Give the new PIN to the person in private.', data: {} })
    renderApp('/settings/users/u-2')

    const card = (await screen.findByRole('heading', { name: 'POS PIN' })).closest('[data-slot="card"]')
    expect(await within(card).findByText('Must change at the till')).toBeInTheDocument()
    fireEvent.click(within(card).getByRole('button', { name: 'Reset PIN' }))
    const dialog = await screen.findByRole('dialog', { name: 'New POS PIN for Joseph Mwangi' })
    fireEvent.change(within(dialog).getByLabelText(/New PIN/), { target: { value: '5937' } })
    fireEvent.change(within(dialog).getByLabelText(/Repeat the PIN/), { target: { value: '5937' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reset PIN' }))
    // Owner ruling: the administrator's own password, every time.
    expect(await within(dialog).findByText('Enter your account password.')).toBeInTheDocument()
    expect(api.put).not.toHaveBeenCalled()
    fireEvent.change(within(dialog).getByLabelText(/Your account password/), { target: { value: 'admin pw' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reset PIN' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('users/u-2/pos-pin', { password: 'admin pw', pin: '5937' }))
    expect(await screen.findByText('The POS PIN is reset. Give the new PIN to the person in private.')).toBeInTheDocument()
  })

  it('clears a PIN with the administrator’s password, for themselves too', async () => {
    const me = { ...OWNER, roles: [] }
    detail(me, status({ pin_set: true, six_digits: true }))
    api.delete.mockResolvedValue({ data: {} })
    renderApp(`/settings/users/${me.id}`)

    const card = (await screen.findByRole('heading', { name: 'POS PIN' })).closest('[data-slot="card"]')
    expect(await within(card).findByText(/their PIN needs 6 digits/)).toBeInTheDocument()
    fireEvent.click(within(card).getByRole('button', { name: 'Remove PIN' }))
    const dialog = await screen.findByRole('dialog', { name: `Remove ${me.name}’s POS PIN?` })
    fireEvent.change(within(dialog).getByLabelText(/Your account password/), { target: { value: 'pw' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove PIN' }))
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith(`users/${me.id}/pos-pin`, { password: 'pw' }))
  })

  it('has no POS PIN card without the POS module (RBAC-08)', async () => {
    detail(JOSEPH, status(), ['core'])
    renderApp('/settings/users/u-2')
    await screen.findByRole('heading', { name: 'Joseph Mwangi' })
    expect(screen.queryByRole('heading', { name: 'POS PIN' })).not.toBeInTheDocument()
    expect(api.get).not.toHaveBeenCalledWith('users/u-2/pos-pin')
  })
})
