import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { COMPANY, FORM_PERMISSIONS, formRoutes, PETTY_CASH } from './fixtures'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

function setUp() {
  mockRoutes(api, formRoutes([
    ['roles?per_page=200', { data: [{ id: 'role-1', name: 'Accountant', archived_at: null }] }],
  ]), { permissions: tenantWide(FORM_PERMISSIONS), companies: [COMPANY] })
}

describe('Settings, Forms (CF-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the form types with what they have', async () => {
    setUp()
    renderApp('/settings/forms')
    expect(await screen.findByText('Petty cash request')).toBeInTheDocument()
    expect(screen.getByText('With approval · Lines · Attachments')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Line fields' })).toHaveAttribute('href', `/settings/custom-fields?entity=${encodeURIComponent(PETTY_CASH.line_entity)}`)
  })

  it('creates a form type with a key made from its name', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...PETTY_CASH, id: 'ft-2', key: 'vehicle_request', name: 'Vehicle request' } })
    renderApp('/settings/forms')
    fireEvent.click(await screen.findByRole('button', { name: 'New form' }))
    const dialog = await screen.findByRole('dialog', { name: 'New form' })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Vehicle request' } })
    expect(within(dialog).getByLabelText(/^Key/)).toHaveValue('vehicle_request')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create form' }))

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0]).toEqual(['custom-form-types', expect.objectContaining({ key: 'vehicle_request', name: 'Vehicle request', workflow: true, has_lines: false })])
  })

  it('shows the API’s refusal of a key on the key field', async () => {
    setUp()
    api.post.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { key: ['Numbers starting with “PO-” are already used by another kind of document. Choose another key.'] }))
    renderApp('/settings/forms')
    fireEvent.click(await screen.findByRole('button', { name: 'New form' }))
    const dialog = await screen.findByRole('dialog', { name: 'New form' })
    fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'PO' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create form' }))
    expect(await within(dialog).findByText(/already used by another kind of document/)).toBeInTheDocument()
  })

  it('archives a form type', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...PETTY_CASH, archived_at: '2026-10-02T00:00:00Z' } })
    renderApp('/settings/forms')
    fireEvent.click(await screen.findByRole('button', { name: 'Archive' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(`custom-form-types/${PETTY_CASH.id}/archive`))
  })
})
