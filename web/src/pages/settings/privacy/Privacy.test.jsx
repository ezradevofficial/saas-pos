import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const META = { subject_types: ['customer', 'employee', 'user'], correctable: { customer: ['name', 'legal_name', 'tax_id', 'phones', 'emails'], employee: ['first_name'], user: ['name'] } }
const base = {
  subject_type: 'customer',
  subject_id: 'p-1',
  subject_name: 'Amina Otieno',
  note: null,
  corrections: null,
  received_at: '2026-10-20T09:00:00Z',
  due_at: '2026-10-27T09:00:00Z',
  overdue: false,
  requested_by: { id: 'u-2', name: 'Baraka' },
  approved_by: null,
  rejection_reason: null,
  result: null,
  own: false,
}
const RECEIVED = { ...base, id: 'r-1', kind: 'deletion', status: 'received' }
const OVERDUE = { ...base, id: 'r-2', kind: 'access', status: 'approved', overdue: true, subject_name: 'Juma Kariuki' }
const OWN = { ...base, id: 'r-3', kind: 'correction', status: 'received', own: true, subject_name: 'Neema Wanjiru' }

function routes(permissions, extra = []) {
  mockRoutes(
    api,
    [
      ...extra,
      [/^privacy\/requests/, { data: [OVERDUE, RECEIVED, OWN], meta: META }],
      [/^privacy\/subjects\?type=customer/, { data: [{ id: 'p-1', name: 'Amina Otieno' }] }],
      [/^privacy\/consents\?/, { data: { current: [{ id: 'c-1', channel: 'sms', granted: true, purpose: 'marketing', source: 'back_office', recorded_at: '2026-10-20T09:00:00Z', recorded_by: { id: 'u-1', name: 'Amina' } }], history: [{ id: 'c-1', channel: 'sms', granted: true, purpose: 'marketing', source: 'back_office', evidence: 'At the till', recorded_at: '2026-10-20T09:00:00Z', recorded_by: { id: 'u-1', name: 'Amina' } }] } }],
    ],
    { permissions: tenantWide(permissions) },
  )
}

const ALL = ['core.privacy_request.view', 'core.privacy_request.manage', 'core.privacy_request.approve', 'core.consent.view', 'core.consent.manage']

describe('Settings → Privacy (NFR-08)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
  })

  it('lists requests with their status, deadline and overdue ones in words', async () => {
    routes(ALL)
    renderApp('/settings/privacy')

    expect(await screen.findByRole('heading', { name: 'Privacy' })).toBeInTheDocument()
    const list = await screen.findByRole('list', { name: 'Data requests' })
    const items = within(list).getAllByRole('listitem')
    expect(within(items[0]).getByText('Juma Kariuki')).toBeInTheDocument()
    expect(within(items[0]).getByText('Overdue')).toBeInTheDocument()
    expect(within(items[0]).getByRole('button', { name: 'Prepare report' })).toBeInTheDocument()
    expect(within(items[1]).getByText('Received')).toBeInTheDocument()
    expect(within(items[1]).getByText(/Deletion · Customer · Received/)).toBeInTheDocument()
    // Someone else's request can be decided; one's own cannot.
    expect(within(items[1]).getByRole('button', { name: 'Approve' })).toBeInTheDocument()
    expect(within(items[2]).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    expect(within(items[2]).getByText('You logged this request, so someone else approves it.')).toBeInTheDocument()
  })

  it('approves a request', async () => {
    routes(ALL)
    api.post.mockResolvedValue({ data: { ...RECEIVED, status: 'approved' } })
    renderApp('/settings/privacy')

    const list = await screen.findByRole('list', { name: 'Data requests' })
    fireEvent.click(within(list).getByRole('button', { name: 'Approve' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('privacy/requests/r-1/approve'))
  })

  it('logs a correction request for a customer found by name', async () => {
    routes(ALL)
    api.post.mockResolvedValue({ data: { ...base, id: 'r-9', kind: 'correction', status: 'received' } })
    renderApp('/settings/privacy')

    fireEvent.click(await screen.findByRole('button', { name: 'Log a request' }))
    const dialog = await screen.findByRole('dialog')
    chooseOption(within(dialog).getByLabelText('Request'), 'Correction')
    await waitForOption(within(dialog).getByLabelText(/^Person/), 'Amina Otieno')
    chooseOption(within(dialog).getByLabelText(/^Person/), 'Amina Otieno')
    fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Amina Achieng Otieno' } })
    fireEvent.change(within(dialog).getByLabelText(/^Note/), { target: { value: 'By email, ID checked' } })
    // Lists such as phones are edited on the record, not here.
    expect(within(dialog).queryByLabelText('phones')).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Log request' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('privacy/requests', {
        subject_type: 'customer',
        subject_id: 'p-1',
        kind: 'correction',
        note: 'By email, ID checked',
        corrections: { name: 'Amina Achieng Otieno' },
      }),
    )
  })

  it('hides actions the user may not take', async () => {
    routes(['core.privacy_request.view'])
    renderApp('/settings/privacy')

    const list = await screen.findByRole('list', { name: 'Data requests' })
    expect(within(list).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    expect(within(list).queryByRole('button', { name: 'Prepare report' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Log a request' })).not.toBeInTheDocument()
    // Without consent permissions there is no consent tab.
    expect(screen.queryByRole('tab', { name: 'Marketing consent' })).not.toBeInTheDocument()
  })

  it('shows and records a customer’s marketing consent', async () => {
    routes(ALL)
    api.post.mockResolvedValue({ data: { id: 'c-2', channel: 'email', granted: false, source: 'back_office', recorded_at: '2026-10-20T10:00:00Z' } })
    renderApp('/settings/privacy?tab=consent')

    await waitForOption(await screen.findByLabelText(/^Person/), 'Amina Otieno')
    chooseOption(screen.getByLabelText(/^Person/), 'Amina Otieno')

    expect(await screen.findByText('Agreed to SMS')).toBeInTheDocument()
    expect(screen.getAllByText('Not asked').length).toBeGreaterThan(0)
    fireEvent.change(screen.getByLabelText(/What the customer said/), { target: { value: 'Asked by phone' } })
    fireEvent.click(screen.getByRole('button', { name: 'Record withdrawal' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('privacy/consents', {
        subject_type: 'customer',
        subject_id: 'p-1',
        purpose: 'marketing',
        channel: 'email',
        granted: false,
        evidence: 'Asked by phone',
      }),
    )
  })

  it('is refused without a privacy permission', async () => {
    routes([])
    renderApp('/settings/privacy')

    expect(await screen.findByText(/permission|access/i)).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Privacy' })).not.toBeInTheDocument()
  })
})
