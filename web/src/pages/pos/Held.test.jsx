import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const named = (id, name) => ({ id, name })
const row = (kind, id, extra = {}) => ({
  kind,
  id,
  status: 'held',
  sale_id: 's-1',
  receipt_number: null,
  amount: null,
  reason: 'Wrong item',
  device_id: 'd-1',
  location_id: 'l-1',
  occurred_at: '2026-10-08T09:30:00Z',
  by: 'u-2',
  approved_by: 'u-3',
  override_verified: false,
  flags: [{ code: 'override_unverified' }],
  decided_by: null,
  decided_at: null,
  received_at: '2026-10-08T10:00:00Z',
  by_user: named('u-2', 'Joseph Mwangi'),
  approver: named('u-3', 'Grace Wanjiru'),
  decider: null,
  sale_receipt_number: 'R-L01-000001',
  device: named('d-1', 'Till 1'),
  location: named('l-1', 'Front till'),
  ...extra,
})

const VOID = row('void', 'v-1')
const REFUND = row('refund', 'rf-1', { receipt_number: 'RF-L01-000001', amount: { amount_minor: '56250', currency: 'KES' }, flags: [{ code: 'actor_unverified' }], reason: 'Damaged' })

function setup(permissions = tenantWide(['pos.sale.void', 'pos.sale.refund', 'pos.cash.move'])) {
  mockRoutes(
    api,
    [
      ['pos/held', { data: [REFUND, VOID] }],
      ['pos/held?kind=refund', { data: [REFUND] }],
    ],
    { permissions, modules: ['core', 'pos'] },
  )
}

describe('POS held for review (H2, AUTH-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists held voids and refunds with amounts, who, where and their flags', async () => {
    setup()
    renderApp('/pos/held')
    const table = await screen.findByRole('table', { name: 'Held for review' })
    const refund = (await within(table).findByText('Refund')).closest('tr')
    expect(within(refund).getByText('RF-L01-000001')).toBeInTheDocument()
    expect(within(refund).getByText('562.50')).toBeInTheDocument()
    expect(within(refund).getByText('Joseph Mwangi')).toBeInTheDocument()
    expect(within(refund).getByText('Actor unverified')).toBeInTheDocument()
    const voided = within(table).getByText('Void').closest('tr')
    expect(within(voided).getByText('Whole sale')).toBeInTheDocument()
    expect(within(voided).getByText('Approval unverified')).toBeInTheDocument()
  })

  it('approves a held void, saying the override is only the device’s claim', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...VOID, status: 'applied' } })
    renderApp('/pos/held')
    fireEvent.click((await screen.findByText('Void')).closest('tr'))

    const drawer = await screen.findByRole('dialog', { name: 'Void' })
    expect(within(drawer).getByText(/Offline override — device claim/)).toBeInTheDocument()
    expect(within(drawer).getByText('Grace Wanjiru')).toBeInTheDocument()
    const approve = within(drawer).getByRole('button', { name: 'Approve' })
    expect(approve).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(approve)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pos/voids/v-1/approve', {}))
  })

  it('rejects a held refund with a reason and shows the API’s refusal', async () => {
    setup()
    api.post.mockRejectedValueOnce(apiError(422, 'validation_failed', 'Some fields need attention.', { reason: ['Enter a reason.'] })).mockResolvedValueOnce({ data: { ...REFUND, status: 'rejected' } })
    renderApp('/pos/held')
    fireEvent.click((await screen.findByText('Refund')).closest('tr'))
    const drawer = await screen.findByRole('dialog', { name: 'Refund' })
    fireEvent.click(within(drawer).getByRole('button', { name: 'Reject' }))

    const dialog = await screen.findByRole('dialog', { name: 'Reject this record?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reject' }))
    expect(await within(dialog).findByText('Enter a reason.')).toBeInTheDocument()
    fireEvent.change(within(dialog).getByLabelText(/Reason/), { target: { value: 'No receipt shown' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reject' }))
    await waitFor(() => expect(api.post).toHaveBeenLastCalledWith('pos/refunds/rf-1/reject', { reason: 'No receipt shown' }))
  })

  it('offers no decision to someone without the permission, and filters by kind', async () => {
    setup(tenantWide(['pos.sale.void']))
    const { router } = renderApp('/pos/held')
    fireEvent.click((await screen.findByText('Refund')).closest('tr'))
    const drawer = await screen.findByRole('dialog', { name: 'Refund' })
    expect(within(drawer).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    fireEvent.keyDown(drawer, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    fireEvent.click(screen.getByLabelText('Record'))
    fireEvent.click(within(screen.getByRole('listbox')).getByRole('option', { name: 'Refund' }))
    await waitFor(() => expect(router.state.location.search).toBe('?kind=refund'))
    expect(api.get).toHaveBeenCalledWith('pos/held?kind=refund')
  })
})
