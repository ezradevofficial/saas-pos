import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const EDITOR = tenantWide(['core.company.view', 'core.payment_method.view', 'core.payment_method.edit', 'core.payment_method.configure'])
const method = (overrides) => ({
  company_id: 'c-1',
  provider: null,
  currency: null,
  settings: {},
  secrets_set: {},
  setting_keys: [],
  secret_keys: [],
  configured: true,
  missing: [],
  active: true,
  archived_at: null,
  ...overrides,
})
const USD = method({ id: 'pm-1', type: 'cash', name: 'Cash USD', currency: 'USD', position: 1 })
const CDF = method({ id: 'pm-2', type: 'cash', name: 'Cash CDF', currency: 'CDF', position: 2 })
const MPESA = method({
  id: 'pm-3',
  type: 'mobile_money',
  provider: 'vodacom_mpesa_cd',
  name: 'M-Pesa',
  active: false,
  configured: false,
  missing: ['merchant_id', 'api_key'],
  setting_keys: ['merchant_id'],
  secret_keys: ['api_key'],
  position: 3,
})

function methods(list = [USD, CDF, MPESA], permissions = EDITOR) {
  mockRoutes(api, [['companies/c-1/payment-methods?per_page=200', () => ({ data: list })]], { permissions, companies: [CD_COMPANY] })
}

describe('PaymentMethods', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('exports the company’s payment methods (EXP-01)', async () => {
    methods()
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: null })
    URL.createObjectURL = vi.fn(() => 'blob:list')
    URL.revokeObjectURL = vi.fn()
    renderApp('/settings/payment-methods')
    await screen.findByText('Cash USD')
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Export' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalled())
    expect(api.download).toHaveBeenCalledWith('companies/c-1/payment-methods?format=csv')
  })

  it('groups methods by type in till order and marks providers that need setting up', async () => {
    methods()
    renderApp('/settings/payment-methods')

    const cash = await screen.findByRole('list', { name: 'Cash' })
    const names = within(cash).getAllByText(/^Cash /).map((node) => node.textContent)
    expect(names).toEqual(['Cash USD', 'Cash CDF'])
    const mobile = screen.getByRole('list', { name: 'Mobile money' })
    expect(within(mobile).getByText('Setup needed')).toBeInTheDocument()
    expect(within(mobile).getByText('M-Pesa (Vodacom Congo)')).toBeInTheDocument()
  })

  it('explains, naming the missing settings, why a provider cannot be switched on', async () => {
    methods()
    renderApp('/settings/payment-methods')

    fireEvent.click(await screen.findByRole('switch', { name: 'Use M-Pesa at the till' }))
    expect(await screen.findByText('M-Pesa can’t be switched on yet. Enter the Merchant ID and API key in its settings first.')).toBeInTheDocument()
    // Known to be refused, so the API is not asked; codes and raw keys are never shown.
    expect(api.patch).not.toHaveBeenCalled()
    expect(screen.queryByText(/merchant_id/)).not.toBeInTheDocument()
  })

  it('shows the API refusal in words when the provider changed meanwhile', async () => {
    methods([{ ...MPESA, missing: [], configured: true }])
    api.patch.mockRejectedValue(apiError(422, 'provider_not_configured', 'The provider isn’t set up yet. Enter api_key, then switch the payment method on.'))
    renderApp('/settings/payment-methods')

    fireEvent.click(await screen.findByRole('switch', { name: 'Use M-Pesa at the till' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('payment-methods/pm-3', { active: true }))
    expect(await screen.findByText(/^M-Pesa can’t be switched on yet/)).toBeInTheDocument()
    expect(screen.queryByText(/api_key/)).not.toBeInTheDocument()
  })

  it('moves a method with keyboard-reachable buttons and saves the whole order', async () => {
    methods()
    api.put.mockResolvedValue({ data: [] })
    renderApp('/settings/payment-methods')

    expect(await screen.findByRole('button', { name: 'Move Cash USD up' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Move Cash USD down' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('companies/c-1/payment-methods/order', { ids: ['pm-2', 'pm-1', 'pm-3'] }))
  })

  it('keeps focus on the moved method and announces its new place', async () => {
    methods()
    api.put.mockResolvedValue({ data: [] })
    renderApp('/settings/payment-methods')

    const down = await screen.findByRole('button', { name: 'Move Cash USD down' })
    down.focus()
    fireEvent.click(down)
    // Now last of its group: "down" is disabled, so focus moves to its "up".
    await waitFor(() => expect(screen.getByRole('button', { name: 'Move Cash USD down' })).toBeDisabled())
    expect(screen.getByRole('button', { name: 'Move Cash USD up' })).toHaveFocus()
    expect(screen.getByText('Cash USD moved to position 2 of 2.')).toBeInTheDocument()
  })

  it('never shows saved secrets: "Saved", then Replace or Remove', async () => {
    methods([USD, { ...MPESA, settings: { merchant_id: 'M-77' }, secrets_set: { api_key: true }, missing: [], configured: true }])
    api.patch.mockResolvedValue({ data: MPESA })
    renderApp('/settings/payment-methods')

    fireEvent.click(await screen.findByRole('button', { name: 'Settings for M-Pesa' }))
    const dialog = await screen.findByRole('dialog', { name: 'M-Pesa settings' })
    expect(within(dialog).getByLabelText('Merchant ID')).toHaveValue('M-77')
    expect(within(dialog).getByText('Saved')).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('API key')).not.toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Replace API key' }))
    const secret = within(dialog).getByLabelText('API key')
    expect(secret).toHaveAttribute('type', 'password')
    expect(secret).toHaveValue('')
    fireEvent.change(secret, { target: { value: 'new-key' } })
    fireEvent.change(within(dialog).getByLabelText('Merchant ID'), { target: { value: 'M-78' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save settings' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('payment-methods/pm-3', { settings: { merchant_id: 'M-78' }, secrets: { api_key: 'new-key' } }))
  })

  it('removes a saved secret by sending null', async () => {
    methods([{ ...MPESA, secrets_set: { api_key: true } }])
    api.patch.mockResolvedValue({ data: MPESA })
    renderApp('/settings/payment-methods')

    fireEvent.click(await screen.findByRole('button', { name: 'Settings for M-Pesa' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove API key' }))
    expect(within(dialog).getByText('Removed when you save')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save settings' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('payment-methods/pm-3', { secrets: { api_key: null } }))
  })

  it('shows a read-only list to a user who may only view', async () => {
    methods(undefined, tenantWide(['core.company.view', 'core.payment_method.view']))
    renderApp('/settings/payment-methods')
    expect(await screen.findByRole('switch', { name: 'Use Cash USD at the till' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: /Move/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Settings for/ })).not.toBeInTheDocument()
  })

  it('keeps provider settings and switching mobile money or card to users who may configure them', async () => {
    methods(undefined, tenantWide(['core.company.view', 'core.payment_method.view', 'core.payment_method.edit']))
    renderApp('/settings/payment-methods')
    expect(await screen.findByRole('switch', { name: 'Use Cash USD at the till' })).toBeEnabled()
    expect(screen.getByRole('switch', { name: 'Use M-Pesa at the till' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: /Settings for/ })).not.toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /Move/ }).length).toBeGreaterThan(0)
  })
})
