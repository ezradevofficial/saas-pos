import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const EDITOR = tenantWide(['core.company.view', 'core.currency.view', 'core.currency.edit'])
const CURRENCIES = [
  { id: 'tc-1', code: 'CDF', name: 'Congolese franc', decimals: 0, decimals_locked: true, cash_rounding_minor: 50, active: true },
  { id: 'tc-2', code: 'KES', name: 'Kenyan shilling', decimals: 2, decimals_locked: false, cash_rounding_minor: 100, active: true },
  { id: 'tc-3', code: 'USD', name: 'US dollar', decimals: 2, decimals_locked: false, cash_rounding_minor: 1, active: true },
]

function currencies({ permissions = EDITOR, locked = false } = {}) {
  mockRoutes(
    api,
    [
      ['tenant/currencies', { data: CURRENCIES }],
      // The table pages; the pickers read every currency (no page params).
      [/^tenant\/currencies\?/, { data: CURRENCIES, meta: { last_page: 1, total: 3, from: 1, to: 3 } }],
      ['companies/c-1/currencies', { data: { base_currency: 'CDF', reporting_currencies: ['USD'], base_currency_locked: locked, base_currency_locked_at: null } }],
      ['currencies', { data: [{ code: 'EUR', name: 'Euro', default_decimals: 2, active_in_iso: true }] }],
    ],
    { permissions, companies: [CD_COMPANY] },
  )
}

describe('Currencies', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the tenant currencies with decimals and cash rounding in their currency', async () => {
    currencies()
    renderApp('/settings/currencies')
    const cdf = (await screen.findByText('Congolese franc')).closest('tr')
    expect(within(cdf).getByText('CDF 50')).toBeInTheDocument()
    expect(within((await screen.findByText('Kenyan shilling')).closest('tr')).getByText('KES 1.00')).toBeInTheDocument()
    expect(screen.getByText('Showing 1–3 of 3')).toBeInTheDocument()
  })

  it('searches and sorts the table on the server while pickers keep the whole list (EXP-01)', async () => {
    currencies()
    const tableCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('tenant/currencies?'))
    renderApp('/settings/currencies')
    const table = await screen.findByRole('table', { name: 'Currencies in use' })
    await within(table).findByText('Congolese franc')
    expect(tableCalls()[0]).toBe('tenant/currencies?per_page=25&page=1')
    expect(api.get).toHaveBeenCalledWith('tenant/currencies')

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'franc' } })
    await waitFor(() => expect(tableCalls().at(-1)).toBe('tenant/currencies?search=franc&per_page=25&page=1'))
    fireEvent.click(within(table).getByRole('button', { name: 'Decimals' }))
    await waitFor(() => expect(tableCalls().at(-1)).toBe('tenant/currencies?search=franc&sort=decimals&per_page=25&page=1'))
    fireEvent.click(within(table).getByRole('button', { name: 'Decimals' }))
    await waitFor(() => expect(tableCalls().at(-1)).toBe('tenant/currencies?search=franc&sort=-decimals&per_page=25&page=1'))
  })

  it('explains why a currency in use cannot be switched off', async () => {
    currencies()
    api.patch.mockRejectedValue(apiError(422, 'currency_in_use', 'In use.'))
    renderApp('/settings/currencies')
    fireEvent.click(await screen.findByRole('switch', { name: 'Use KES' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('tenant/currencies/tc-2', { active: false }))
    expect(await screen.findByText('A company uses this currency as its base or reporting currency. Change that company first.')).toBeInTheDocument()
  })

  it('edits cash rounding in minor units and keeps locked decimals locked', async () => {
    currencies()
    api.patch.mockResolvedValue({ data: CURRENCIES[0] })
    renderApp('/settings/currencies')
    fireEvent.click(await screen.findByRole('button', { name: 'Edit CDF' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit CDF' })
    expect(within(dialog).getByLabelText('Decimals')).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/Cash rounding/), { target: { value: '100' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    // Minor units as a string (ADR 003).
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('tenant/currencies/tc-1', { cash_rounding_minor: '100' }))
  })

  it('clears the cash rounding when the decimals change instead of reinterpreting it', async () => {
    currencies()
    api.patch.mockResolvedValue({ data: CURRENCIES[1] })
    renderApp('/settings/currencies')
    fireEvent.click(await screen.findByRole('button', { name: 'Edit KES' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit KES' })
    expect(within(dialog).getByLabelText(/Cash rounding/)).toHaveValue('1.00')
    chooseOption(within(dialog).getByLabelText('Decimals'), '0')
    expect(within(dialog).getByLabelText(/Cash rounding/)).toHaveValue('')
    expect(within(dialog).getByText(/The decimals changed, so the cash rounding was cleared/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    expect(await within(dialog).findByText('Enter the cash rounding amount.')).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
    fireEvent.change(within(dialog).getByLabelText(/Cash rounding/), { target: { value: '5' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('tenant/currencies/tc-2', { cash_rounding_minor: '5', decimals: 0 }))
  })

  it('shows why an invalid cash rounding cannot be added when the form is submitted', async () => {
    currencies()
    renderApp('/settings/currencies')
    fireEvent.click(await screen.findByRole('button', { name: 'Add currency' }))
    const dialog = await screen.findByRole('dialog')
    const select = within(dialog).getByLabelText(/^Currency/)
    await waitForOption(select, 'EUR · Euro')
    chooseOption(select, 'EUR · Euro')
    // Typed but never left: the reason shows once the form is submitted.
    fireEvent.change(within(dialog).getByLabelText(/Cash rounding/), { target: { value: '0.555' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add currency' }))
    expect(await within(dialog).findByText('Use at most 2 decimal places.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('saves the company base and reporting currencies', async () => {
    currencies()
    api.put.mockResolvedValue({ data: { base_currency: 'CDF', reporting_currencies: ['USD', 'KES'], base_currency_locked: false } })
    renderApp('/settings/currencies')
    const reporting2 = await screen.findByLabelText('Reporting currency 2')
    expect(screen.getByLabelText('Reporting currency 1')).toHaveValue('USD')
    chooseOption(reporting2, 'KES')
    fireEvent.click(screen.getByRole('button', { name: 'Save currencies' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('companies/c-1/currencies', { base_currency: 'CDF', reporting_currencies: ['USD', 'KES'] }))
    expect(await screen.findByText('Currencies saved.')).toBeInTheDocument()
  })

  it('locks the base currency after the first posting', async () => {
    currencies({ locked: true })
    renderApp('/settings/currencies')
    expect(await screen.findByLabelText('Base currency')).toBeDisabled()
    expect(screen.getByText('Base currency locked')).toBeInTheDocument()
  })

  it('shows the Finance and Master data navigation only with the permissions', async () => {
    currencies({ permissions: tenantWide(['core.company.view', 'core.currency.view', 'core.tax.view']) })
    renderApp('/settings/currencies')
    const nav = (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
    expect(await within(nav).findByRole('link', { name: 'Currencies' })).toBeInTheDocument()
    expect(await within(nav).findByRole('link', { name: 'Taxes' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Exchange rates' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Payment methods' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Sharing' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add currency' })).not.toBeInTheDocument()
  })
})
