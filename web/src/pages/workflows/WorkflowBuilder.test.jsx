import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'
import { GRAPH, mockWorkflows, WORKFLOW } from '@/test/workflows'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const node = (container, id) => container.querySelector(`.react-flow__node[data-id="${id}"]`)
const card = (container, id) => node(container, id)?.querySelector('[data-node-type]')
const lastPut = () => api.put.mock.calls.at(-1)

function phone(matches) {
  window.matchMedia = vi.fn((query) => ({ matches, media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
}

async function openBuilder() {
  mockWorkflows(api)
  api.put.mockImplementation(async (path, body) => ({ data: { ...WORKFLOW.draft, graph: body.graph }, meta: { problems: [] } }))
  const utils = renderApp('/settings/workflows/w-1')
  await screen.findByRole('heading', { name: 'Purchase requisition · Amani Retail Ltd' })
  await waitFor(() => expect(node(utils.container, 'manager')).not.toBeNull())
  return utils
}

describe('Workflow builder (spec 6.4, WF-03..WF-09, APR-09)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    phone(false)
  })

  afterEach(() => {
    delete window.matchMedia
  })

  it('loads the draft and shows each step with its kind and summary', async () => {
    const { container } = await openBuilder()
    expect(screen.getByText('Draft v4')).toBeInTheDocument()
    expect(screen.getByText('v3 is live · 6 documents in progress stay on v3')).toBeInTheDocument()
    expect(within(node(container, 'manager')).getByText('Branch manager approves')).toBeInTheDocument()
    expect(within(node(container, 'manager')).getByText('Approval')).toBeInTheDocument()
    expect(within(node(container, 'manager')).getByText('Manager of the requester’s branch · escalates after 8 business hours')).toBeInTheDocument()
    expect(within(node(container, 'budget')).getByText('Stage · entry rule')).toBeInTheDocument()
    expect(within(node(container, 'big')).getByText('Total is more than KES 250,000.00')).toBeInTheDocument()
    expect(within(node(container, 'po')).getByText('Creates a draft Purchase order')).toBeInTheDocument()
    expect(node(container, 'manager')).toHaveAttribute('aria-label', 'Approval: Branch manager approves')
    expect(node(container, 'manager')).toHaveAttribute('tabindex', '0')
    expect(screen.getByRole('button', { name: 'Publish v4' })).toHaveAttribute('data-ds-variant', 'pay')
    expect(api.put).not.toHaveBeenCalled()
  })

  it('edits a step in the panel and saves the draft with the change', async () => {
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'manager'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })
    expect(within(panel).getByText(/The requester can never approve their own document/)).toBeInTheDocument()
    fireEvent.change(within(panel).getByLabelText(/^Name/), { target: { value: 'Area manager approves' } })
    expect(within(node(container, 'manager')).getByText('Area manager approves')).toBeInTheDocument()
    await waitFor(() => expect(api.put).toHaveBeenCalled(), { timeout: 3000 })
    const [path, body] = lastPut()
    expect(path).toBe('workflows/w-1/draft')
    expect(body.graph.nodes.find((one) => one.id === 'manager').name).toBe('Area manager approves')
    expect(body.graph.edges).toEqual(GRAPH.edges)
    expect(await screen.findByText('Draft saved')).toBeInTheDocument()
  })

  it('edits a condition’s rule on the document type’s fields', async () => {
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'big'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })
    const rule = within(panel).getByRole('group', { name: 'Rule 1' })
    chooseOption(within(rule).getByLabelText('Comparison'), 'is at least')
    fireEvent.click(within(panel).getByRole('button', { name: 'Add a rule' }))
    const second = within(panel).getByRole('group', { name: 'Rule 2' })
    chooseOption(within(second).getByLabelText('Field'), 'Category')
    chooseOption(within(second).getByLabelText('Value'), 'services')
    chooseOption(within(panel).getByLabelText('Match'), 'Any of these rules (or)')
    await waitFor(() => expect(api.put).toHaveBeenCalled(), { timeout: 3000 })
    expect(lastPut()[1].graph.nodes.find((one) => one.id === 'big').condition).toEqual({
      any: [
        { field: 'total', op: 'gte', value: { amount_minor: '25000000', currency: 'KES' } },
        { field: 'category', op: 'eq', value: 'services' },
      ],
    })
    expect(within(node(container, 'big')).getByText('Total is at least KES 250,000.00 or Category is services')).toBeInTheDocument()
  })

  it('adds a step from the palette, then undoes and redoes it', async () => {
    const { container } = await openBuilder()
    fireEvent.click(screen.getByRole('button', { name: 'Add Stage' }))
    await waitFor(() => expect(node(container, 'stage_2')).not.toBeNull())
    expect(within(screen.getByRole('complementary', { name: 'Step settings' })).getByLabelText(/^Name/)).toHaveValue('New stage')

    fireEvent.click(screen.getByRole('button', { name: 'Undo' }))
    await waitFor(() => expect(node(container, 'stage_2')).toBeNull())
    fireEvent.click(screen.getByRole('button', { name: 'Redo' }))
    await waitFor(() => expect(node(container, 'stage_2')).not.toBeNull())
    // Keyboard: Ctrl+Z undoes, Ctrl+Shift+Z redoes.
    fireEvent.keyDown(window, { key: 'z', ctrlKey: true })
    await waitFor(() => expect(node(container, 'stage_2')).toBeNull())
    fireEvent.keyDown(window, { key: 'Z', ctrlKey: true, shiftKey: true })
    await waitFor(() => expect(node(container, 'stage_2')).not.toBeNull())

    await waitFor(() => expect(api.put).toHaveBeenCalled(), { timeout: 3000 })
    const stage = lastPut()[1].graph.nodes.find((one) => one.id === 'stage_2')
    expect(stage).toMatchObject({ type: 'stage', name: 'New stage', mandatory: true })
  })

  it('lists validation problems and marks the steps they name', async () => {
    const { container } = await openBuilder()
    api.post.mockImplementation(async (path) =>
      path === 'workflows/w-1/validate'
        ? { data: { valid: false, problems: [{ code: 'unreachable', message: '“CFO approves” can never be reached from the start.', node: 'cfo' }] } }
        : {},
    )
    fireEvent.click(screen.getByRole('button', { name: 'Check for problems' }))
    const issues = await screen.findByRole('region', { name: 'Problems to fix before publishing' })
    expect(within(issues).getByText('1 problem to fix before publishing')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('workflows/w-1/validate', { graph: expect.objectContaining({ nodes: expect.any(Array) }) })
    expect(card(container, 'cfo')).toHaveAttribute('data-problem', 'true')
    expect(card(container, 'manager')).not.toHaveAttribute('data-problem')
    fireEvent.click(within(issues).getByRole('button', { name: '“CFO approves” can never be reached from the start.' }))
    expect(within(screen.getByRole('complementary', { name: 'Step settings' })).getByLabelText(/^Name/)).toHaveValue('CFO approves')
  })

  it('publishes after confirming, saying documents in progress stay on the live version', async () => {
    await openBuilder()
    api.post.mockResolvedValue({ data: { ...WORKFLOW, draft: null, published: { ...WORKFLOW.draft, status: 'published' } } })
    fireEvent.click(screen.getByRole('button', { name: 'Publish v4' }))
    const dialog = await screen.findByRole('dialog', { name: 'Publish v4?' })
    expect(within(dialog).getByText('6 documents in progress stay on v3 until they finish.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Publish v4' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('workflows/w-1/publish'))
  })

  it('tests with a sample and highlights the path taken', async () => {
    const { container } = await openBuilder()
    api.post.mockImplementation(async (path, body) => {
      if (path !== 'workflows/w-1/test') return {}
      expect(body.graph.nodes).toHaveLength(GRAPH.nodes.length)
      expect(body.outcomes).toEqual({ manager: 'approved', cfo: 'rejected' })
      return {
        data: {
          valid: true,
          problems: [],
          outcome: 'approved',
          blocked: null,
          path: [
            { node_id: 'start', type: 'start', name: 'Requisition submitted', result: 'start', reasons: [] },
            { node_id: 'budget', type: 'stage', name: 'Check budget', result: 'completed', reasons: [] },
            { node_id: 'manager', type: 'approval', name: 'Branch manager approves', result: 'approved', reasons: [] },
            { node_id: 'big', type: 'condition', name: 'Total over KES 250,000?', result: 'no', reasons: ['Total must be more than KES 250,000.00; it is KES 1,000.00.'] },
            { node_id: 'po', type: 'action', name: 'Create draft purchase order', result: 'would_run', reasons: ['Would create a draft Purchase order.'] },
            { node_id: 'done', type: 'end', name: 'End', result: 'end', reasons: [] },
          ],
        },
      }
    })
    fireEvent.click(screen.getByRole('button', { name: 'Test with a sample' }))
    const dialog = await screen.findByRole('dialog', { name: 'Test with a sample' })
    expect(within(dialog).getByLabelText(/^Decision at Branch manager approves/)).toBeInTheDocument()
    chooseOption(within(dialog).getByLabelText(/^Decision at CFO approves/), 'Rejected')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Run the test' }))
    expect(await within(dialog).findByText('Ends: approved')).toBeInTheDocument()
    expect(within(dialog).getByText('Total must be more than KES 250,000.00; it is KES 1,000.00.')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Show on the canvas' }))
    await waitFor(() => expect(card(container, 'po')).toHaveAttribute('data-path', 'would_run'))
    expect(card(container, 'big')).toHaveAttribute('data-path', 'no')
    expect(card(container, 'cfo')).not.toHaveAttribute('data-path')
    expect(card(container, 'cfo').className).toContain('opacity-40')
    expect(screen.getByText('Showing a test path. It ends approved.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Clear test' }))
    await waitFor(() => expect(card(container, 'po')).not.toHaveAttribute('data-path'))
  })

  it('is read-only on a phone: no palette, panel or publishing', async () => {
    phone(true)
    const { container } = await openBuilder()
    expect(screen.getByText('Workflows are read-only on a phone. Open this page on a tablet or computer to edit.')).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'Building blocks' })).not.toBeInTheDocument()
    expect(screen.queryByRole('complementary', { name: 'Step settings' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Publish v4' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Undo' })).not.toBeInTheDocument()
    expect(node(container, 'manager')).not.toHaveClass('draggable')
    await act(async () => {
      fireEvent.keyDown(node(container, 'manager'), { key: 'Delete' })
    })
    expect(node(container, 'manager')).not.toBeNull()
    expect(api.put).not.toHaveBeenCalled()
  })
})
