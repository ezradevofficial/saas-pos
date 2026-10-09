import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { closeFilters, openFilters } from '@/test/filters'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Retail', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const kes = (amount_minor) => ({ amount_minor, currency: 'KES' })

const INTENT = {
  id: 'pi-1',
  company_id: 'c-1',
  purpose: 'sale',
  mode: 'manual',
  status: 'pending',
  verification: 'unverified',
  amount: kes('112500'),
  phone: '2547•••••678',
  reference: 'R-L01-000001',
  receipt: 'SJK12AB34C',
  account_reference: 'R-L01-000001',
  message: null,
  created_at: '2026-10-08T09:00:00Z',
}
const PAID = { ...INTENT, id: 'pi-2', mode: 'stk', status: 'succeeded', verification: 'verified', reference: 'R-L01-000002' }
const RECEIPT = { id: 'pr-1', company_id: 'c-1', receipt: 'SJK99ZZ00X', amount: kes('112500'), account_reference: 'R-L01-000001', transacted_at: '2026-10-08T09:05:00Z', status: 'unmatched' }

describe('Payments received (concept note 7.1)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  function setup(permissions = tenantWide(['core.company.view', 'core.payment.view', 'core.payment.match'])) {
    mockRoutes(
      api,
      [
        [/^companies\/c-1\/payment-intents\?status=mismatch/, page([])],
        [/^companies\/c-1\/payment-intents\?/, page([INTENT, PAID])],
        [/^companies\/c-1\/payment-receipts\?/, page([RECEIPT])],
      ],
      { permissions, companies: [COMPANY] },
    )
  }

  it('lists payment requests with their check, and filters mismatches through the API', async () => {
    setup()
    const { router } = renderApp('/settings/payments')
    const row = (await screen.findByText('R-L01-000001')).closest('tr')
    expect(within(row).getByText('Code entered')).toBeInTheDocument()
    expect(within(row).getByText('Not verified').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(within(row).getByText('2547•••••678')).toBeInTheDocument()

    openFilters()
    chooseOption('Status', 'Mismatch')
    await waitFor(() => expect(router.state.location.search).toBe('?status=mismatch'))
    await closeFilters()
    expect(api.get).toHaveBeenCalledWith('companies/c-1/payment-intents?status=mismatch&sort=-created_at&per_page=25&page=1')
    expect(await screen.findByText('No payment requests match. Try other filters.')).toBeInTheDocument()
  })

  it('matches an unmatched receipt to a request still waiting', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...RECEIPT, status: 'matched' } })
    renderApp('/settings/payments?tab=receipts')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('companies/c-1/payment-receipts?status=unmatched&sort=-created_at&per_page=25&page=1'))

    fireEvent.click(await screen.findByRole('button', { name: 'Match SJK99ZZ00X to a payment' }))
    const dialog = await screen.findByRole('dialog', { name: 'Match SJK99ZZ00X to a payment' })
    await within(dialog).findByLabelText('Payment request')
    // Only the request still to verify is offered, not the one already paid.
    chooseOption(within(dialog).getByLabelText('Payment request'), /^KES 1,125\.00 · R-L01-000001/)
    expect(screen.queryByRole('option', { name: /R-L01-000002/ })).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Match payment' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('payment-receipts/pr-1/match', { payment_intent_id: 'pi-1' }))
  })

  it('offers no matching without core.payment.match', async () => {
    setup(tenantWide(['core.company.view', 'core.payment.view']))
    renderApp('/settings/payments?tab=receipts')
    await screen.findByText('SJK99ZZ00X')
    expect(screen.queryByRole('button', { name: /Match .* to a payment/ })).not.toBeInTheDocument()
  })
})

const SETTINGS = {
  id: 'fs-1',
  company_id: 'c-1',
  country: 'KE',
  driver: 'kra_etims_oscu',
  enabled: false,
  tin: 'P051111111A',
  branch_code: '00',
  device_serial: 'KRACU0100000001',
  settings: { default_item_class_code: '5020230500' },
  credentials_set: {},
  initialized_at: null,
  missing: ['credentials.cmc_key'],
  drivers: ['kra_etims_oscu'],
  updated_at: '2026-10-08T09:00:00Z',
}
const SUBMISSIONS = [
  { id: 'fq-1', document_type: 'sale', document_number: 'R-L01-000001', invoice_number: 41, status: 'accepted', attempts: 1, error: null, created_at: '2026-10-08T09:00:00Z' },
  { id: 'fq-2', document_type: 'refund', document_number: 'RF-L01-000001', invoice_number: 42, status: 'needs_attention', attempts: 3, error: 'Item SOAP has no eTIMS band.', created_at: '2026-10-08T10:00:00Z' },
]

describe('Tax authority (concept note 7.2)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  function setup(permissions, settings = SETTINGS) {
    mockRoutes(
      api,
      [
        ['companies/c-1/fiscal-settings', { data: settings }],
        [/^companies\/c-1\/fiscal-submissions\?/, page(SUBMISSIONS)],
      ],
      { permissions, companies: [COMPANY] },
    )
  }

  it('lets an accountant edit default codes only, and retry a document that needs attention', async () => {
    setup(tenantWide(['core.company.view', 'core.fiscal.view', 'core.fiscal.edit']))
    api.put.mockResolvedValue({ data: SETTINGS })
    api.post.mockResolvedValue({ data: { ...SUBMISSIONS[1], status: 'retrying' } })
    renderApp('/settings/fiscal')

    expect(await screen.findByLabelText(/Taxpayer PIN/)).toBeDisabled()
    expect(screen.getByText('Only an owner or administrator can change these.')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Send documents to the authority' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Initialise device' })).not.toBeInTheDocument()
    expect(screen.getByText('Still needed: Communication key (initialise the device).')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText(/Packaging unit code/), { target: { value: 'NT' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('companies/c-1/fiscal-settings', {
        settings: { default_item_class_code: '5020230500', default_packaging_unit_code: 'NT', default_quantity_unit_code: null },
      }),
    )

    const row = (await screen.findByText('RF-L01-000001')).closest('tr')
    expect(within(row).getByText('Needs attention').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(within(row).getByText('Item SOAP has no eTIMS band.')).toBeInTheDocument()
    expect(within(screen.getByText('R-L01-000001').closest('tr')).queryByRole('button', { name: /Retry/ })).not.toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Retry RF-L01-000001' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('fiscal-submissions/fq-2/retry', {}))
  })

  it('lets an owner change the registration, initialise the device and switch sending on', async () => {
    setup(tenantWide(['core.company.view', 'core.fiscal.view', 'core.fiscal.edit', 'core.fiscal.configure']))
    api.post.mockResolvedValue({ data: { ...SETTINGS, initialized_at: '2026-10-08T11:00:00Z', missing: [] } })
    api.put.mockRejectedValueOnce(apiError(422, 'fiscal_not_ready', 'Transmission can’t be switched on: credentials.cmc_key is missing.'))
    renderApp('/settings/fiscal')

    expect(await screen.findByLabelText(/Taxpayer PIN/)).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Initialise device' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/fiscal-settings/initialize', {}))
    expect(await screen.findByText('The authority initialised the device.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('switch', { name: 'Send documents to the authority' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('companies/c-1/fiscal-settings', { enabled: true }))
    expect(await screen.findByText(/Transmission can’t be switched on/)).toBeInTheDocument()
  })

  it('sends earlier sales only after the person confirms', async () => {
    setup(tenantWide(['core.company.view', 'core.fiscal.view', 'core.fiscal.edit']), { ...SETTINGS, enabled: true, missing: [] })
    api.post.mockResolvedValue({ data: { from: '2026-10-01', queued: true } })
    renderApp('/settings/fiscal')

    fireEvent.click(await screen.findByRole('button', { name: 'Send earlier sales' }))
    const dialog = await screen.findByRole('dialog', { name: 'Send earlier sales' })
    fireEvent.change(within(dialog).getByLabelText('Send documents from'), { target: { value: '2026-10-01' } })
    const send = within(dialog).getByRole('button', { name: 'Send to the authority' })
    expect(send).toBeDisabled()
    fireEvent.click(within(dialog).getByLabelText('I understand these documents go to the tax authority'))
    fireEvent.click(send)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/fiscal-submissions/send-earlier', { from: '2026-10-01', confirm: true }))
    expect(await within(dialog).findByText(/Documents from 2026-10-01 are being queued/)).toBeInTheDocument()
  })
})

describe('M-Pesa callback URLs (concept note 7.1)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  const MPESA = {
    id: 'pm-1',
    company_id: 'c-1',
    type: 'mobile_money',
    name: 'M-Pesa',
    currency: 'KES',
    provider: 'mpesa_ke',
    settings: { shortcode: '174379' },
    secrets_set: { consumer_key: true, consumer_secret: true, passkey: true },
    setting_keys: ['shortcode', 'transaction_type', 'till_number', 'b2c_shortcode', 'initiator_name'],
    secret_keys: ['consumer_key', 'consumer_secret', 'passkey', 'security_credential'],
    configured: true,
    missing: [],
    active: true,
    position: 1,
  }
  const urls = (token) => ({ data: { urls: { stk: `https://api.example.test/api/v1/payments/callbacks/${token}/stk`, 'c2b-confirm': `https://api.example.test/api/v1/payments/callbacks/${token}/c2b-confirm` } } })

  it('shows the URLs, rotates them after a confirmation and registers C2B', async () => {
    mockRoutes(
      api,
      [
        [/^companies\/c-1\/payment-methods\?/, { data: [MPESA] }],
        ['payment-methods/pm-1/callbacks', urls('a'.repeat(48))],
      ],
      { permissions: tenantWide(['core.company.view', 'core.payment_method.view', 'core.payment_method.edit', 'core.payment_method.configure']), companies: [COMPANY] },
    )
    api.post.mockImplementation(async (path) => (path.endsWith('/rotate') ? urls('b'.repeat(48)) : { data: { registered: true } }))
    renderApp('/settings/payment-methods')

    fireEvent.click(await screen.findByRole('button', { name: 'Callback URLs of M-Pesa' }))
    const dialog = await screen.findByRole('dialog', { name: 'Callback URLs of M-Pesa' })
    expect(await within(dialog).findByText(`https://api.example.test/api/v1/payments/callbacks/${'a'.repeat(48)}/stk`)).toBeInTheDocument()
    expect(within(dialog).getByText('Phone prompt result')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Get new URLs' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Replace the URLs' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('payment-methods/pm-1/callbacks/rotate', {}))
    expect(await within(dialog).findByText(`https://api.example.test/api/v1/payments/callbacks/${'b'.repeat(48)}/stk`)).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Register C2B URLs' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('payment-methods/pm-1/c2b/register', {}))
    expect(await within(dialog).findByText('Safaricom accepted the confirmation and validation URLs.')).toBeInTheDocument()
  })

  it('offers Paybill or Till as a choice in the provider settings', async () => {
    mockRoutes(api, [[/^companies\/c-1\/payment-methods\?/, { data: [MPESA] }]], {
      permissions: tenantWide(['core.company.view', 'core.payment_method.view', 'core.payment_method.edit', 'core.payment_method.configure']),
      companies: [COMPANY],
    })
    api.patch.mockResolvedValue({ data: MPESA })
    renderApp('/settings/payment-methods')
    fireEvent.click(await screen.findByRole('button', { name: 'Settings for M-Pesa' }))
    const dialog = await screen.findByRole('dialog', { name: /M-Pesa/ })
    chooseOption(within(dialog).getByLabelText('Paybill or Till'), 'Till (Buy Goods)')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save settings' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('payment-methods/pm-1', { settings: { transaction_type: 'till' } }))
  })
})
