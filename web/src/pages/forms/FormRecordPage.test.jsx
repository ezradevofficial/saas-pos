import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { COMPANY, FORM_PERMISSIONS, formRoutes, PETTY_CASH } from './fixtures'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const RECORD = {
  id: 'r-1',
  type: { id: PETTY_CASH.id, key: 'petty_cash', name: 'Petty cash request' },
  number: 'PETTY_CASH-2026-00001',
  status: 'draft',
  company: { id: 'c-1', name: 'Acme' },
  branch: null,
  location: null,
  custom: { reason: 'Site visit' },
  totals: { quantity: '3' },
  amount: null,
  created_by: { id: 'u-1', name: 'Amina Otieno' },
  created_at: '2026-10-01T08:00:00Z',
  archived_at: null,
  lines: [
    { id: 'l-1', position: 1, custom: { description: 'Fuel', quantity: '1' } },
    { id: 'l-2', position: 2, custom: { description: 'Water', quantity: '2' } },
  ],
  attachments: [{ id: 'a-1', name: 'receipt.pdf', mime: 'application/pdf', size: 2048, url: null }],
  approval_id: null,
  can: { edit: true, cancel: true, archive: true },
}

function setUp(record = RECORD) {
  mockRoutes(api, formRoutes([['custom-form-records/r-1', { data: record, meta: { workflow: null } }]]), {
    permissions: tenantWide(FORM_PERMISSIONS),
    companies: [COMPANY],
  })
}

const lineList = () => screen.findByRole('list', { name: 'Lines' })

describe('A custom form record (CF-04, CF-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('creates a record with its place, header values and lines', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...RECORD, id: 'r-9' } })
    renderApp('/forms/petty_cash/new')

    fireEvent.change(await screen.findByLabelText(/^Reason/), { target: { value: 'Site visit' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Add line' }))
    const lines = await lineList()
    fireEvent.change(within(lines).getByLabelText(/^Description, line 1/), { target: { value: 'Fuel' } })
    fireEvent.change(within(lines).getByLabelText(/^Quantity, line 1/), { target: { value: '2.5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [path, body] = api.post.mock.calls[0]
    expect(path).toBe(`custom-form-types/${PETTY_CASH.id}/records`)
    expect(body).toMatchObject({ submit: false, company_id: 'c-1', custom: { reason: 'Site visit' }, lines: [{ custom: { description: 'Fuel', quantity: '2.5' } }], attachments: [] })
    expect(body.lines[0]).not.toHaveProperty('id')
  })

  it('sends saved lines by id, with a cleared value as null, and removed attachments left out', async () => {
    setUp()
    api.patch.mockResolvedValue({ data: RECORD })
    renderApp('/forms/petty_cash/r-1')

    const lines = await lineList()
    await within(lines).findByDisplayValue('Water')
    fireEvent.change(within(lines).getByLabelText(/^Quantity, line 1/), { target: { value: '' } })
    fireEvent.click(within(lines).getByRole('button', { name: 'Remove line 2' }))
    fireEvent.click(screen.getByRole('button', { name: 'Remove receipt.pdf' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    const [path, body] = api.patch.mock.calls[0]
    expect(path).toBe('custom-form-records/r-1')
    expect(body.lines).toEqual([{ id: 'l-1', custom: { description: 'Fuel', quantity: null } }])
    expect(body.attachments).toEqual([])
  })

  it('sends for approval and shows the API’s line errors on their fields', async () => {
    setUp()
    api.patch.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { 'lines.1.custom.description': ['Enter Description.'] }))
    renderApp('/forms/petty_cash/r-1')
    await within(await lineList()).findByDisplayValue('Water')

    fireEvent.click(screen.getByRole('button', { name: 'Send for approval' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1].submit).toBe(true)
    expect(await screen.findByText('Enter Description.')).toBeInTheDocument()
  })

  it('never drops a line value that isn’t a number: send waits and the field says why', async () => {
    setUp()
    renderApp('/forms/petty_cash/r-1')
    const lines = await lineList()
    await within(lines).findByDisplayValue('Water')

    const quantity = within(lines).getByLabelText(/^Quantity, line 2/)
    fireEvent.change(quantity, { target: { value: 'two' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send for approval' }))

    expect(await within(lines).findByText('This value isn’t a number. Correct it or clear it before saving.')).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    expect(api.patch).not.toHaveBeenCalled()
  })

  it('keeps each saved line’s controls across renders', async () => {
    setUp()
    renderApp('/forms/petty_cash/r-1')
    const lines = await lineList()
    const input = await within(lines).findByDisplayValue('Water')
    // A render that doesn't touch the lines (typing in the header) keeps the same input elements.
    fireEvent.change(screen.getByLabelText(/^Reason/), { target: { value: 'Another reason' } })
    expect(within(lines).getByDisplayValue('Water')).toBe(input)
  })

  it('shows a closed record read only', async () => {
    setUp({ ...RECORD, status: 'approved', can: { edit: false, cancel: false, archive: true } })
    renderApp('/forms/petty_cash/r-1')
    expect(await screen.findByText('Approved')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Send for approval' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add line' })).not.toBeInTheDocument()
  })
})
