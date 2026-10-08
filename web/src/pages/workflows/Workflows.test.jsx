import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { mockWorkflows, WORKFLOW } from '@/test/workflows'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const LIST = [/^workflows\?/, () => ({ data: [WORKFLOW, { ...WORKFLOW, id: 'w-2', company_id: null, company_name: null, draft: null }], meta: { total: 2, last_page: 1, from: 1, to: 2 } })]

describe('Workflows list (WF-02, spec 6.4)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists flows with their company, live version and draft, and opens one', async () => {
    mockWorkflows(api, { extra: [LIST] })
    const { router } = renderApp('/settings/workflows')
    const table = await screen.findByRole('table', { name: 'Workflows' })
    expect(await within(table).findByText('Amani Retail Ltd')).toBeInTheDocument()
    expect(within(table).getByText('All companies')).toBeInTheDocument()
    expect(within(table).getAllByText('v3')).toHaveLength(2)
    expect(within(table).getByText('Draft v4')).toBeInTheDocument()
    expect(within(table).getByText('No draft')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Workflows' })).toBeInTheDocument()

    fireEvent.click(within(table).getByText('Amani Retail Ltd'))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/workflows/w-1'))
  })

  it('creates a flow for a document type in a company and opens the builder', async () => {
    mockWorkflows(api, { extra: [LIST] })
    api.post.mockResolvedValue({ data: { ...WORKFLOW, id: 'w-9' } })
    const { router } = renderApp('/settings/workflows')
    const create = await screen.findByRole('button', { name: 'Create workflow' })
    await waitFor(() => expect(create).toBeEnabled())
    fireEvent.click(create)
    const dialog = await screen.findByRole('dialog', { name: 'Create a workflow' })
    chooseOption(within(dialog).getByLabelText(/^Company/), 'Kin Market')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Create and open' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('workflows', { document_type: 'procurement.requisition', company_id: 'c-2' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/workflows/w-9'))
  })

  it('filters by document type and hides Create without the edit right', async () => {
    mockWorkflows(api, { extra: [LIST], permissions: tenantWide(['core.workflow.view']) })
    renderApp('/settings/workflows')
    await screen.findByRole('table', { name: 'Workflows' })
    expect(screen.queryByRole('button', { name: 'Create workflow' })).not.toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Document type')).toBeEnabled())
    chooseOption('Document type', 'Purchase requisition')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^workflows\?type=procurement\.requisition/)))
  })
})
