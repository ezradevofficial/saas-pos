// PRC-02, PRC-21: requisitions (list, new, detail with its flow and the next documents).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { BRANCH, mockProcurement, money, named, page } from '@/test/procurement'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const REQUISITION = {
  id: 'rq-1',
  number: 'PR-WL-2026-00001',
  status: 'approved',
  purpose: 'Flour for the bakery',
  needed_by: '2026-10-30',
  company: named('c-1', 'Amani Retail'),
  branch: named('br-1', 'Westlands'),
  location: null,
  department: null,
  requested_by: named('u-2', 'Joseph Mwangi'),
  estimated_total: money('1250000'),
  currency: 'KES',
  created_at: '2026-10-10T06:00:00Z',
  lines: [{ id: 'rl-1', line_no: 1, item: { id: 'i-1', code: 'FLOUR', name: 'Maize flour 2 kg', type: 'stock' }, description: null, uom: { id: 'u-pcs', code: 'pcs', name: 'Pieces' }, qty: '50', estimated_unit_price: money('25000'), cost_centre: null, project: null }],
  can: { edit: false, submit: false, cancel: false, order: true },
}

describe('Requisitions (PRC-02)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists requisitions with status, branch, requester and estimated total', async () => {
    mockProcurement(api, [[/^procurement\/requisitions\?/, page([REQUISITION])]])
    renderApp('/procurement/requisitions')
    const row = (await screen.findByText('PR-WL-2026-00001')).closest('tr')
    expect(within(row).getByText('Approved').closest('[data-tone]')).toHaveAttribute('data-tone', 'info')
    expect(within(row).getByText('Westlands')).toBeInTheDocument()
    expect(within(row).getByText('Joseph Mwangi')).toBeInTheDocument()
    expect(within(row).getByText('12,500.00')).toBeInTheDocument()
  })

  it('saves a draft with its lines, the estimated price in minor units', async () => {
    mockProcurement(api)
    api.post.mockResolvedValue({ data: { ...REQUISITION, id: 'rq-2', status: 'draft' } })
    const { router } = renderApp('/procurement/requisitions/new')
    await waitForOption(await screen.findByLabelText(/^Branch/), BRANCH.name)
    chooseOption(screen.getByLabelText(/^Branch/), BRANCH.name)
    fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'Flour for the bakery' } })
    await waitForOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    chooseOption(screen.getByLabelText('Item'), 'Maize flour 2 kg · FLOUR')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '50' } })
    fireEvent.change(screen.getByLabelText(/^Estimated unit price/), { target: { value: '250' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save requisition' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('procurement/requisitions', {
        branch_id: 'br-1',
        location_id: null,
        department_id: null,
        purpose: 'Flour for the bakery',
        needed_by: null,
        currency: 'KES',
        lines: [{ item_id: 'i-1', uom_id: 'u-pcs', qty: '50', estimated_unit_price_minor: '25000' }],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/requisitions/rq-2'))
  })

  it('asks for the branch and what is needed before saving', async () => {
    mockProcurement(api)
    renderApp('/procurement/requisitions/new')
    fireEvent.click(await screen.findByRole('button', { name: 'Save requisition' }))
    expect(await screen.findByText('Choose the branch.')).toBeInTheDocument()
    expect(screen.getByText('Choose an item or describe what is needed.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('submits a draft for approval', async () => {
    const draft = { ...REQUISITION, number: null, status: 'draft', can: { edit: true, submit: true, cancel: true, order: false } }
    mockProcurement(api, [['procurement/requisitions/rq-1', { data: draft }]])
    api.post.mockResolvedValue({ data: { ...draft, number: 'PR-WL-2026-00001', status: 'pending', can: { cancel: true } } })
    renderApp('/procurement/requisitions/rq-1')
    const submit = await screen.findByRole('button', { name: 'Submit for approval' })
    expect(submit).toHaveAttribute('data-ds-variant', 'pay')
    // WF-10: no flow is asked for before it is submitted.
    expect(api.get).not.toHaveBeenCalledWith(expect.stringMatching(/^document-workflows\//))
    fireEvent.click(submit)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('procurement/requisitions/rq-1/submit', {}))
    expect(await screen.findByText('Requisition submitted for approval.')).toBeInTheDocument()
  })

  it('offers an RFQ, a tender or a PO from an approved requisition, and the PO form takes its lines', async () => {
    mockProcurement(api, [['procurement/requisitions/rq-1', { data: REQUISITION }]])
    const { router } = renderApp('/procurement/requisitions/rq-1')
    expect(await screen.findByRole('heading', { name: 'PR-WL-2026-00001' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Request quotes' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Start a tender' })).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('document-workflows/procurement.requisition/rq-1')
    fireEvent.click(screen.getByRole('button', { name: 'Create purchase order' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/procurement/orders/new'))
    expect(router.state.location.search).toBe('?requisition=rq-1')
    expect(await screen.findByText('From requisition PR-WL-2026-00001: its branch and lines are filled in.')).toBeInTheDocument()
    expect(screen.getByLabelText(/^Unit price/)).toHaveValue('250.00')
  })
})
