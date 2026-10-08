import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const EDITOR = tenantWide(['core.company.view', 'core.currency.view', 'core.tax.view', 'core.tax.edit', 'core.price_list.view', 'core.price_list.edit'])
const code = (overrides) => ({ company_id: 'c-1', pack_code: null, fiscal_code: null, archived_at: null, rates: [], ...overrides })
const CODES = [
  code({
    id: 't-1',
    code: 'VAT_STD',
    name: 'VAT, standard rate',
    kind: 'vat',
    rate_needed: true,
    current_rate: { id: 'r-1', rate: null, effective_from: '2026-01-01', effective_to: null, needs_confirmation: true, source: 'pack' },
    rates: [{ id: 'r-1', rate: null, effective_from: '2026-01-01', effective_to: null, needs_confirmation: true, source: 'pack' }],
  }),
  code({
    id: 't-2',
    code: 'VAT_ZERO',
    name: 'VAT, zero-rated',
    kind: 'zero_rated',
    rate_needed: false,
    current_rate: { id: 'r-2', rate: '0.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false, source: 'pack' },
    rates: [{ id: 'r-2', rate: '0.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false, source: 'pack' }],
  }),
  code({ id: 't-3', code: 'VAT_EXEMPT', name: 'VAT exempt', kind: 'exempt', rate_needed: false, current_rate: null }),
]

function taxes({ permissions = EDITOR } = {}) {
  mockRoutes(
    api,
    [
      ['companies/c-1/tax-codes?per_page=200', { data: CODES }],
      ['tax-categories?per_page=200', { data: [{ id: 'cat-1', company_id: null, shared: true, name: 'Standard goods', codes: [{ company_id: 'c-1', tax_code_id: 't-1', code: 'VAT_STD' }] }] }],
      ['master-data/settings', { data: [{ data_type: 'items', mode: 'shared', changed_at: null }] }],
      ['companies/c-1/price-lists?per_page=200', { data: [{ id: 'pl-1', name: 'Retail CDF', currency: 'CDF', tax_inclusive: true, is_default: true }] }],
      ['tenant/currencies', { data: [{ id: 'tc-1', code: 'CDF', active: true, decimals: 0 }] }],
    ],
    { permissions, companies: [CD_COMPANY] },
  )
}

describe('Taxes', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows "Rate needed" for a code without a confirmed rate, never a figure', async () => {
    taxes()
    renderApp('/settings/taxes')

    const std = (await screen.findByText('VAT_STD')).closest('tr')
    expect(within(std).getByText('Rate needed')).toBeInTheDocument()
    expect(within(std.querySelector('[data-tone="warning"]')).getByText('Rate needed')).toBeInTheDocument()
    expect(within(screen.getByText('VAT_ZERO').closest('tr')).getByText('0%')).toBeInTheDocument()
    expect(within(screen.getByText('VAT_EXEMPT').closest('tr')).getAllByText('Exempt')).toHaveLength(2)
    expect(screen.getByText('1 tax code has no confirmed rate. Add the rate from an official source before using it.')).toBeInTheDocument()
  })

  it('adds a rate from a date with a percent typed in the UI language', async () => {
    taxes()
    api.post.mockResolvedValue({ data: CODES[0] })
    renderApp('/settings/taxes')

    fireEvent.click(await screen.findByRole('button', { name: 'Add a rate to VAT_STD' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a rate to VAT_STD' })
    fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '12.5' } })
    fireEvent.change(within(dialog).getByLabelText(/Effective from/), { target: { value: '2026-11-01' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add rate' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('tax-codes/t-1/rates', { rate: '12.5', effective_from: '2026-11-01' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('shows why a typed rate is invalid when the form is submitted', async () => {
    taxes()
    renderApp('/settings/taxes')
    fireEvent.click(await screen.findByRole('button', { name: 'Add a rate to VAT_STD' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a rate to VAT_STD' })
    fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '120' } })
    fireEvent.change(within(dialog).getByLabelText(/Effective from/), { target: { value: '2026-11-01' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add rate' }))
    expect(await within(dialog).findByText('Enter a number up to 100.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows a refused rate under the field', async () => {
    taxes()
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { rate: ['A zero-rated code’s rate is 0.'] }))
    renderApp('/settings/taxes')

    fireEvent.click(await screen.findByRole('button', { name: 'Add a rate to VAT_ZERO' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/^Rate/), { target: { value: '5' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add rate' }))
    expect(await within(dialog).findByText('A zero-rated code’s rate is 0.')).toBeInTheDocument()
  })

  it('lists the rate history', async () => {
    taxes()
    renderApp('/settings/taxes')
    fireEvent.click(await screen.findByRole('button', { name: 'Rates of VAT_STD' }))
    const dialog = await screen.findByRole('dialog', { name: 'Rates of VAT_STD' })
    expect(within(dialog).getByText('1 Jan 2026')).toBeInTheDocument()
    expect(within(dialog).getByText('No end date')).toBeInTheDocument()
    expect(within(dialog).getByText('Country pack')).toBeInTheDocument()
  })

  it('explains that applying the country pack only adds missing codes, then reports what it added', async () => {
    taxes()
    api.post.mockResolvedValue({ data: { pack: 'CD', version: 1, added: ['VAT_STD'], skipped: [] } })
    renderApp('/settings/taxes')

    fireEvent.click(await screen.findByRole('button', { name: 'Apply country pack' }))
    const dialog = await screen.findByRole('dialog', { name: 'Apply the DR Congo country pack?' })
    expect(within(dialog).getByText(/Codes you already have, and their rates, are never changed/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add missing codes' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('companies/c-1/tax-codes/apply-pack'))
    expect(await screen.findByText('1 tax code added: VAT_STD.')).toBeInTheDocument()
  })

  it('shows categories and price lists in their tabs', async () => {
    taxes()
    renderApp('/settings/taxes')
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Categories' }))
    const category = (await screen.findByText('Standard goods')).closest('tr')
    expect(within(category).getByText('All companies')).toBeInTheDocument()
    expect(within(category).getByText('VAT_STD')).toBeInTheDocument()
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Price lists' }))
    const row = (await screen.findByText('Retail CDF')).closest('tr')
    expect(within(row).getByText('Include tax')).toBeInTheDocument()
    expect(within(row).getByText('Default for CDF')).toBeInTheDocument()
  })

  it('offers no changes to a user who may only view taxes', async () => {
    taxes({ permissions: tenantWide(['core.company.view', 'core.tax.view']) })
    renderApp('/settings/taxes')
    expect(await screen.findByText('VAT_STD')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Apply country pack' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Add a rate/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Price lists' })).not.toBeInTheDocument()
  })
})
