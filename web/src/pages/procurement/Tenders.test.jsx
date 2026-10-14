// PRC-06 to PRC-12: tenders (form, sealed bids, committee opening, scores, ranking, award, letters).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox, openCombobox, waitForOption } from '@/test/combobox'
import { BRANCH, mockProcurement, money, named } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const PCS = { id: 'u-pcs', code: 'pcs', name: 'Pieces' }
const FINGERPRINT = '9f2c4e0a7b1d3f5e8a6c2b4d0e1f3a5c7e9b1d3f5a7c9e0b2d4f6a8c0e2b4d6f'

const BASE = {
  id: 't-1',
  number: 'TND-WL-2026-00001',
  kind: 'open',
  title: 'Bakery ovens',
  description: null,
  currency: 'KES',
  deadline_at: '2026-10-12T09:00:00Z',
  opening_at: '2026-10-12T10:00:00Z',
  technical_weight: 70,
  financial_weight: 30,
  pass_mark: '60',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  created_by: named('u-1', 'Amina Otieno'),
  bids_count: 2,
  opened_at: null,
  created_at: '2026-10-01T06:00:00Z',
  notice_url: 'http://localhost:3008/supplier-portal/notices/abc',
  lines: [{ id: 'tl-1', line_no: 1, item: null, description: 'Deck oven, 3 decks', uom: PCS, qty: '2' }],
  criteria: [
    { id: 'cr-1', name: 'Experience', weight: 60, max_score: 10 },
    { id: 'cr-2', name: 'After-sales service', weight: 40, max_score: 10 },
  ],
  openers: [named('u-1', 'Amina Otieno'), named('u-2', 'Joseph Mwangi')],
  evaluators: [named('u-1', 'Amina Otieno')],
  openings: [{ user: named('u-2', 'Joseph Mwangi'), confirmed_at: '2026-10-12T10:05:00Z' }],
  bidders: [{ id: 'bd-1', name: 'Oven Masters Ltd', email: 'bids@ovenmasters.example', tax_id: 'P051234567X', source: 'registered', party: null, link_sent_at: '2026-10-02T08:00:00Z', last_seen_at: null, removed_at: null }],
}

const SEALED = {
  ...BASE,
  status: 'closed',
  bids: [
    { id: 'b-1', bidder: named('bd-1', 'Oven Masters Ltd'), status: 'submitted', version: 2, submitted_at: '2026-10-11T15:00:00Z', withdrawn_at: null, fingerprint: FINGERPRINT, sealed: true },
    { id: 'b-2', bidder: named('bd-2', 'Coast Kitchens'), status: 'submitted', version: 1, submitted_at: '2026-10-11T16:00:00Z', withdrawn_at: null, fingerprint: 'aa11', sealed: true },
  ],
  can: { edit: false, publish: false, invite: false, open: true, score: false, award: false, cancel: true },
}

const opened = (id, name, rank, technical, financial, combined, total) => ({
  id,
  bidder: named(`bd-${id}`, name),
  status: 'opened',
  version: 1,
  submitted_at: '2026-10-11T15:00:00Z',
  withdrawn_at: null,
  fingerprint: `fp-${id}`,
  sealed: false,
  opened_at: '2026-10-12T10:06:00Z',
  currency: 'KES',
  total: money(total),
  base_total: money(total),
  complete: true,
  lines: [{ tender_line_id: 'tl-1', unit_price: money(String(Number(total) / 2)), amount: money(total) }],
  technical_response: 'Ten years of installing deck ovens.',
  files: [{ id: `f-${id}`, name: 'brochure.pdf', mime: 'application/pdf', size: 20480 }],
  technical_score_bp: technical,
  financial_score_bp: financial,
  combined_score_bp: combined,
  rank,
  outcome: null,
  scores: [],
})

const OPENED = {
  ...BASE,
  status: 'opened',
  opened_at: '2026-10-12T10:06:00Z',
  openings: [
    { user: named('u-2', 'Joseph Mwangi'), confirmed_at: '2026-10-12T10:05:00Z' },
    { user: named('u-1', 'Amina Otieno'), confirmed_at: '2026-10-12T10:06:00Z' },
  ],
  bids: [opened('b-1', 'Oven Masters Ltd', 1, 8765, 10000, 9136, '90000000'), opened('b-2', 'Coast Kitchens', 2, 8000, 9000, 8300, '100000000')],
  can: { edit: false, publish: false, invite: false, open: false, score: true, award: true, cancel: true },
}

describe('Tenders (PRC-06 to PRC-12)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('saves a tender with its criteria, committee and times converted from the company time zone', async () => {
    mockProcurement(api)
    api.post.mockResolvedValue({ data: { ...SEALED, id: 't-2', status: 'draft' } })
    renderApp('/procurement/tenders/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    fireEvent.change(screen.getByLabelText(/^Title/), { target: { value: 'Bakery ovens' } })
    chooseOption(screen.getByLabelText('Kind'), 'Open')
    fireEvent.change(screen.getByLabelText(/^Response deadline/), { target: { value: '2026-11-01T10:00' } })
    fireEvent.change(screen.getByLabelText(/^Opening time/), { target: { value: '2026-11-01T11:00' } })
    fireEvent.change(screen.getByLabelText('Description', { selector: 'textarea' }), { target: { value: 'Two deck ovens' } })
    fireEvent.change(screen.getByLabelText('Description', { selector: 'input' }), { target: { value: 'Deck oven' } })
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('Criterion'), { target: { value: 'Experience' } })
    fireEvent.change(screen.getByLabelText('Weight'), { target: { value: '100' } })
    const openers = openCombobox(screen.getByLabelText('Openers'))
    await within(openers).findByRole('option', { name: 'Amina Otieno' })
    fireEvent.click(within(openers).getByRole('option', { name: 'Amina Otieno' }))
    fireEvent.click(within(openers).getByRole('option', { name: 'Joseph Mwangi' }))
    closeCombobox()
    fireEvent.click(screen.getByRole('button', { name: 'Save tender' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/tenders', {
        branch_id: 'br-1',
        title: 'Bakery ovens',
        description: 'Two deck ovens',
        kind: 'open',
        currency: 'KES',
        deadline_at: '2026-11-01T07:00:00.000Z',
        opening_at: '2026-11-01T08:00:00.000Z',
        technical_weight: 70,
        pass_mark: '60',
        lines: [{ description: 'Deck oven', qty: '2' }],
        criteria: [{ name: 'Experience', weight: 100, max_score: 10 }],
        openers: ['u-1', 'u-2'],
        evaluators: [],
      }),
    )
  })

  it('refuses criteria weights that do not add up to 100', async () => {
    mockProcurement(api)
    renderApp('/procurement/tenders/new')
    fireEvent.change(await screen.findByLabelText('Criterion'), { target: { value: 'Experience' } })
    fireEvent.change(screen.getByLabelText('Weight'), { target: { value: '60' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save tender' }))
    expect(await screen.findByText('The criteria weights add up to 60. Make them add up to 100.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows sealed bids without their content, and the committee opening with each opener’s confirmation', async () => {
    let current = SEALED
    mockProcurement(api, [['procurement/tenders/t-1', () => ({ data: current })]])
    api.post.mockImplementation(async () => {
      current = OPENED
      return { data: OPENED }
    })
    renderApp('/procurement/tenders/t-1')
    expect(await screen.findByRole('heading', { name: 'TND-WL-2026-00001' })).toBeInTheDocument()
    expect(screen.getByText('Bids are sealed')).toBeInTheDocument()
    const sealed = screen.getByRole('table', { name: 'Sealed bids' })
    const row = within(sealed).getByText('Oven Masters Ltd').closest('tr')
    expect(within(row).getByText(FINGERPRINT)).toBeInTheDocument()
    expect(within(row).getByText('2')).toBeInTheDocument()
    // PRC-09: nothing of the content: no prices, no technical response, no files.
    expect(within(sealed).queryByText(/KES/)).not.toBeInTheDocument()
    expect(screen.queryByText('Technical response')).not.toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Ranking' })).not.toBeInTheDocument()

    const openers = screen.getByRole('list', { name: 'Openers' })
    expect(within(within(openers).getByText('Joseph Mwangi').closest('li')).getByText('Confirmed')).toBeInTheDocument()
    expect(within(within(openers).getByText('Amina Otieno').closest('li')).getByText('Not confirmed yet')).toBeInTheDocument()
    expect(screen.getByText(/Two different openers must each confirm/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirm opening' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/tenders/t-1/open', {}))
    expect(await screen.findByRole('heading', { name: 'Opened bids' })).toBeInTheDocument()
    expect(screen.getAllByText('Ten years of installing deck ovens.')).toHaveLength(2)
  })

  it('ranks opened bids with scores as percentages and awards a lower-ranked bid only with a reason', async () => {
    mockProcurement(api, [['procurement/tenders/t-1', { data: OPENED }]])
    api.post.mockResolvedValue({ data: { ...OPENED, status: 'awarded', can: { ...OPENED.can, award: false } } })
    renderApp('/procurement/tenders/t-1')
    const ranking = await screen.findByRole('table', { name: 'Ranking' })
    const top = within(ranking).getByText('Oven Masters Ltd').closest('tr')
    expect(within(top).getByText('87.65 %')).toBeInTheDocument()
    expect(within(top).getByText('100.00 %')).toBeInTheDocument()
    expect(within(top).getByText('91.36 %')).toBeInTheDocument()

    fireEvent.click(within(ranking).getByRole('button', { name: 'Award to Coast Kitchens' }))
    const dialog = await screen.findByRole('dialog', { name: 'Award to Coast Kitchens' })
    const confirm = within(dialog).getByRole('button', { name: 'Award tender' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Local after-sales service' } })
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/bids/b-2/award', { reason: 'Local after-sales service' }))
    expect(await screen.findByText('Tender awarded. A draft purchase order was made.')).toBeInTheDocument()
  })

  it('lets an evaluator score each criterion of a bid with a comment', async () => {
    // The signed-in user (OWNER, u-1) is the tender’s evaluator.
    mockProcurement(api, [['procurement/tenders/t-1', { data: OPENED }]])
    api.put.mockResolvedValue({ data: OPENED })
    renderApp('/procurement/tenders/t-1')
    const sheet = (await screen.findAllByRole('group', { name: 'Oven Masters Ltd' }))[0]
    fireEvent.change(within(sheet).getByLabelText('Experience (0 to 10)'), { target: { value: '8' } })
    fireEvent.change(within(sheet).getByLabelText('Comment on Experience'), { target: { value: 'Strong references' } })
    fireEvent.click(within(sheet).getByRole('button', { name: 'Save scores for Oven Masters Ltd' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('procurement/bids/b-1/scores', { scores: [{ criterion_id: 'cr-1', score: '8', comment: 'Strong references' }] }))
  })

  it('offers a bidder’s link again only when the API allows it, and links the award’s purchase order and letters', async () => {
    const draft = { ...BASE, status: 'draft', bids: [], order: null, bidders: [{ ...BASE.bidders[0], can: { resend: false, remove: true } }], can: { edit: true, publish: true, invite: true, cancel: true } }
    mockProcurement(api, [['procurement/tenders/t-1', { data: draft }]])
    const { unmount } = renderApp('/procurement/tenders/t-1')
    const bidders = await screen.findByRole('table', { name: 'Bidders' })
    expect(within(bidders).queryByRole('button', { name: 'Resend link to Oven Masters Ltd' })).not.toBeInTheDocument()
    expect(within(bidders).getByRole('button', { name: 'Remove Oven Masters Ltd' })).toBeInTheDocument()
    unmount()

    const awarded = { ...OPENED, status: 'awarded', bids: OPENED.bids.map((bid, index) => ({ ...bid, outcome: index === 0 ? 'awarded' : 'regretted' })), order: { id: 'po-9', number: null, status: 'draft' },
      can: { ...OPENED.can, award: false, letter: true, email_letter: false } }
    mockProcurement(api, [['procurement/tenders/t-1', { data: awarded }]])
    renderApp('/procurement/tenders/t-1')
    expect(await screen.findByRole('link', { name: 'Draft' })).toHaveAttribute('href', '/procurement/orders/po-9')
    const ranking = screen.getByRole('table', { name: 'Ranking' })
    expect(within(ranking).getAllByRole('button', { name: /^Download the letter/ })).toHaveLength(2)
    expect(within(ranking).queryByRole('button', { name: /^Email the letter/ })).not.toBeInTheDocument()
  })

  it('picks the committee from the branch’s eligible people', async () => {
    mockProcurement(api)
    renderApp('/procurement/tenders/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('procurement/branches/br-1/committee-candidates'))
    expect(api.get.mock.calls.some(([path]) => path.startsWith('users?'))).toBe(false)
  })

  it('downloads an opened bid’s attachment with the user’s token', async () => {
    mockProcurement(api, [['procurement/tenders/t-1', { data: OPENED }]])
    api.download.mockResolvedValue({ blob: new Blob(['%PDF']), filename: 'brochure.pdf' })
    window.URL.createObjectURL = vi.fn(() => 'blob:x')
    window.URL.revokeObjectURL = vi.fn()
    renderApp('/procurement/tenders/t-1')
    const files = await screen.findByRole('list', { name: 'Files of Oven Masters Ltd' })
    fireEvent.click(within(files).getByRole('button', { name: 'Download brochure.pdf' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('procurement/bids/b-1/files/f-b-1'))
  })
})
