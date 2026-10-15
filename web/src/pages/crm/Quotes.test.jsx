// CRM-17..CRM-24: the quote editor's live totals and line problems, and the quote's page and actions.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { BRANCH, mockCrm, named, page } from '@/test/crm'
import { apiError, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const VAT = { id: 'tx-1', code: 'VAT16', name: 'VAT 16 %' }
const totals = (subtotal, discount, tax, total) => ({ subtotal_minor: subtotal, discount_minor: discount, tax_minor: tax, total_minor: total })

const previewLine = (extra = {}) => ({
  line_no: 1,
  item: { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg' },
  description: null,
  uom: { id: 'u-pcs', code: 'pcs' },
  qty: '10',
  list_price_minor: '12000',
  unit_price_minor: '12000',
  discount_percent: '5',
  gross_minor: '120000',
  discount_minor: '6000',
  net_minor: '114000',
  tax_minor: '18240',
  total_minor: '132240',
  tax_code: VAT,
  tax_rate: '16',
  rate_needed: false,
  problems: [],
  ...extra,
})

const QUOTE = {
  id: 'q-1',
  number: 'QT-WL-2026-00001',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  owner: named('u-1', 'Amina Otieno'),
  team: null,
  party: named('pt-1', 'Duka Moja'),
  opportunity: named('o-1', 'Duka Moja shelving'),
  status: 'accepted',
  currency: 'KES',
  price_list: named('pl-1', 'Retail KES'),
  tax_inclusive: false,
  quote_date: '2026-10-10',
  valid_until: '2026-11-09',
  expired: false,
  terms: 'Payment in 30 days',
  notes: null,
  totals: totals('120000', '6000', '18240', '132240'),
  base_totals: { currency: 'KES', total_minor: '132240' },
  max_discount_percent: '5',
  needs_approval: false,
  submitted_at: '2026-10-10T07:00:00Z',
  sent_at: '2026-10-10T08:00:00Z',
  accepted_at: '2026-10-11T08:00:00Z',
  declined_at: null,
  decline_reason: null,
  converted_to: null,
  converted_ref: null,
  cancelled_at: null,
  cancel_reason: null,
  created_at: '2026-10-10T06:00:00Z',
  lines: [{ id: 'ql-1', ...previewLine() }],
  can: { edit: false, submit: false, send: true, accept: false, convert: true, cancel: false },
}

describe('Quote editor (CRM-17..CRM-20)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows live totals and each line problem from the preview, keeps the list price unless one is typed, and saves a draft', async () => {
    mockCrm(api)
    const previews = []
    api.post.mockImplementation(async (path, body) => {
      if (path === 'crm/quotes/preview') {
        previews.push(body)
        const typed = body.lines[0].unit_price_minor
        const line = previewLine({
          qty: body.lines[0].qty,
          unit_price_minor: typed ?? '12000',
          problems: typed ? [{ code: 'price_override_not_allowed', message: 'You may not change the list price.' }] : [{ code: 'discount_over_limit', message: 'Your discount limit is 2 %.' }],
        })
        return { data: { currency: 'KES', price_list: named('pl-1', 'Retail KES'), tax_inclusive: false, lines: [line], totals: totals('120000', '6000', '18240', '132240') } }
      }
      return { data: { ...QUOTE, status: 'draft', number: null } }
    })
    renderApp('/crm/quotes/new')
    await waitForOption(await screen.findByLabelText(/^Customer/), 'Duka Moja')
    chooseOption(screen.getByLabelText(/^Customer/), 'Duka Moja')
    await waitForOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    await waitForOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    chooseOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '10' } })
    fireEvent.change(screen.getByLabelText('Discount'), { target: { value: '5' } })

    expect(await screen.findByText('Your discount limit is 2 %.')).toBeInTheDocument()
    expect(screen.getByTestId('quote-total')).toHaveTextContent('KES 1,322.40')
    expect(screen.getByLabelText('Totals')).toHaveTextContent('60.00')
    expect(screen.getByText('VAT 16 %')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Unit price')).toHaveValue('120.00'))
    expect(previews.at(-1)).toEqual({ branch_id: 'br-1', party_id: 'pt-1', terms: null, notes: null, lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '10', discount_percent: '5' }] })

    fireEvent.change(screen.getByLabelText('Unit price'), { target: { value: '110' } })
    expect(await screen.findByText('You may not change the list price.')).toBeInTheDocument()
    expect(previews.at(-1).lines[0]).toEqual({ item_id: 'i-1', uom_id: 'u-pcs', qty: '10', unit_price_minor: '11000', discount_percent: '5' })

    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('crm/quotes', { branch_id: 'br-1', party_id: 'pt-1', terms: null, notes: null, lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '10', unit_price_minor: '11000', discount_percent: '5' }] }),
    )
  })

  it('asks a tax code for a description line and starts from the opportunity named in the address', async () => {
    mockCrm(api, [['crm/opportunities/o-1', { data: { id: 'o-1', name: 'Duka Moja shelving', party: named('pt-1', 'Duka Moja'), branch: named('br-1', 'Westlands'), value: { amount_minor: '100', currency: 'KES' }, activities: [], quotes: [] } }]])
    api.post.mockImplementation(async (path) =>
      path === 'crm/quotes/preview'
        ? { data: { currency: 'KES', price_list: null, tax_inclusive: false, lines: [previewLine({ item: null, description: 'Installation', tax_code: VAT, qty: '1', unit_price_minor: '500000', total_minor: '580000', problems: [] })], totals: totals('500000', '0', '80000', '580000') } }
        : { data: { ...QUOTE, id: 'q-2', status: 'draft' } },
    )
    renderApp('/crm/quotes/new?opportunity=o-1')
    expect(await screen.findByText('For opportunity Duka Moja shelving')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Description/), { target: { value: 'Installation' } })
    fireEvent.change(screen.getByLabelText('Unit price'), { target: { value: '5000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    expect(await screen.findByText('Choose a tax code for this line.')).toBeInTheDocument()
    await waitForOption(screen.getByLabelText(/^Tax code/), 'VAT 16 %')
    chooseOption(screen.getByLabelText(/^Tax code/), 'VAT 16 %')
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('crm/quotes', expect.objectContaining({ branch_id: 'br-1', party_id: 'pt-1', opportunity_id: 'o-1', currency: 'KES', lines: [{ description: 'Installation', qty: '1', unit_price_minor: '500000', tax_code_id: 'tx-1' }] })),
    )
  })
})

describe('Quote page (CRM-21..CRM-24)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows totals with the currency code first and a draft with a "Rate needed" line cannot be submitted (CRM-19)', async () => {
    const draft = { ...QUOTE, number: null, status: 'draft', needs_approval: true, lines: [{ id: 'ql-1', ...previewLine({ tax_code: VAT, tax_rate: null, rate_needed: true, tax_minor: '0' }) }], can: { edit: true, submit: true, send: false, accept: false, convert: false, cancel: true } }
    mockCrm(api, [['crm/quotes/q-1', { data: draft }]])
    renderApp('/crm/quotes/q-1')
    expect(await screen.findByRole('heading', { name: 'Draft quote' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled()
    expect(screen.getByText('A tax rate is needed')).toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'Lines' })).getByText('Rate needed').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    expect(screen.getByText('This quote is above the approval threshold. Submitting sends it for approval.')).toBeInTheDocument()
    expect(screen.getByTestId('quote-total')).toHaveTextContent('KES 1,322.40')
  })

  it('shows why a conversion is refused when Accounting is off (CRM-24)', async () => {
    mockCrm(api, [['crm/quotes/q-1', { data: QUOTE }], ['crm/quotes/q-1/shares', { data: [] }]])
    api.post.mockRejectedValue(apiError(422, 'target_inactive', 'Accounting is not active. Turn it on to turn quotes into invoices.'))
    renderApp('/crm/quotes/q-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Convert to invoice' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/quotes/q-1/convert', { target: 'invoice' }))
    expect(await screen.findByText('Accounting is not active. Turn it on to turn quotes into invoices.')).toBeInTheDocument()
  })

  it('sends by email in a chosen language, shares on WhatsApp and revokes a share link (CRM-22)', async () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const share = { id: 'sh-1', status: 'active', created_at: '2026-10-12T08:00:00Z', expires_at: '2026-10-19T08:00:00Z', revoked_at: null, access_count: 3 }
    mockCrm(api, [['crm/quotes/q-1', { data: { ...QUOTE, status: 'approved', can: { ...QUOTE.can, convert: false, accept: true } } }], ['crm/quotes/q-1/shares', page([share])]])
    api.post.mockImplementation(async (path) => {
      if (path.endsWith('/share')) return { data: { id: 'sh-2', status: 'active', url: 'https://share.example/q', whatsapp_url: 'https://wa.me/?text=Quote', expires_at: '2026-10-22T08:00:00Z' } }
      return { data: { ...QUOTE, status: 'sent' } }
    })
    renderApp('/crm/quotes/q-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Send by email' }))
    const dialog = await screen.findByRole('dialog', { name: 'Send quote QT-WL-2026-00001' })
    fireEvent.change(within(dialog).getByLabelText('Email'), { target: { value: 'buyer@duka.example' } })
    chooseOption(within(dialog).getByLabelText('Language'), 'French')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send by email' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/quotes/q-1/send', { email: 'buyer@duka.example', locale: 'fr' }))

    fireEvent.click(screen.getByRole('button', { name: 'Share on WhatsApp' }))
    await waitFor(() => expect(open).toHaveBeenCalledWith('https://wa.me/?text=Quote', '_blank', 'noopener,noreferrer'))

    const shares = await screen.findByRole('table', { name: 'Share links' })
    expect(within(shares).getByText('3')).toBeInTheDocument()
    fireEvent.click(within(shares).getByRole('button', { name: 'Revoke' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/quotes/q-1/shares/sh-1/revoke'))
    open.mockRestore()
  })

  it('records acceptance and wins the opportunity in the same step (CRM-23)', async () => {
    mockCrm(api, [['crm/quotes/q-1', { data: { ...QUOTE, status: 'sent', can: { ...QUOTE.can, convert: false, accept: true, send: false } } }]])
    api.post.mockResolvedValue({ data: QUOTE })
    renderApp('/crm/quotes/q-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Record acceptance' }))
    const dialog = await screen.findByRole('dialog', { name: 'Customer accepted quote QT-WL-2026-00001' })
    expect(within(dialog).getByLabelText('Also mark Duka Moja shelving as won')).toBeChecked()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Record acceptance' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('crm/quotes/q-1/accept', { win_opportunity: true }))
  })
})
