import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { renderApp, resetSession, signedIn } from '@/test/renderApp'
import { GRAPH, mockWorkflows, VERSIONS, WORKFLOW } from '@/test/workflows'

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

  it('sets who a notify step writes to as role and user entries, with an optional message', async () => {
    const { container } = await openBuilder()
    fireEvent.click(screen.getByRole('button', { name: 'Add Notify' }))
    await waitFor(() => expect(node(container, 'action_2')).not.toBeNull())
    expect(within(node(container, 'action_2')).getByText('Choose who is notified')).toBeInTheDocument()
    const panel = screen.getByRole('complementary', { name: 'Step settings' })
    expect(within(panel).queryByText('Channels')).not.toBeInTheDocument()
    fireEvent.click(within(within(panel).getByRole('group', { name: 'Who is notified' })).getByLabelText('Administrator'))
    fireEvent.click(within(within(panel).getByRole('group', { name: 'Named people' })).getByLabelText('Baraka Mwangi'))
    fireEvent.change(within(panel).getByLabelText('Message'), { target: { value: 'Please check the order.' } })
    expect(within(node(container, 'action_2')).getByText('Notifies Administrator, Baraka Mwangi')).toBeInTheDocument()
    await waitFor(() => expect(api.put).toHaveBeenCalled(), { timeout: 3000 })
    expect(lastPut()[1].graph.nodes.find((one) => one.id === 'action_2')).toMatchObject({
      type: 'action',
      action: 'notify',
      config: { to: ['role:0192a1b2-0000-7000-8000-0000000000a1', 'user:0192a1b2-0000-7000-8000-0000000000b2'], message: 'Please check the order.' },
    })
  })

  it('reads notify recipients named by role template key', async () => {
    const graph = {
      ...GRAPH,
      nodes: GRAPH.nodes.map((one) => (one.id === 'po' ? { ...one, action: 'notify', config: { to: ['role:admin', 'role:template:admin'] } } : one)),
    }
    mockWorkflows(api, { workflow: { ...WORKFLOW, draft: { ...WORKFLOW.draft, graph } } })
    const { container } = renderApp('/settings/workflows/w-1')
    await waitFor(() => expect(node(container, 'po')).not.toBeNull())
    expect(await within(node(container, 'po')).findByText('Notifies Administrator, Administrator')).toBeInTheDocument()
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

  it('discards the draft after confirming and shows the live version again (WF-02)', async () => {
    // The live v3 is a shorter flow than draft v4, so the canvas visibly changes.
    const liveGraph = { nodes: GRAPH.nodes.filter((step) => ['start', 'budget', 'done'].includes(step.id)), edges: [{ from: 'start', to: 'budget' }, { from: 'budget', to: 'done' }] }
    const live = { ...WORKFLOW.published, graph: liveGraph }
    let current = { ...WORKFLOW, published: live }
    const versions = () => ({
      data: current.draft
        ? VERSIONS
        : [{ ...VERSIONS[0], status: 'archived', discarded_at: '2026-10-08T09:00:00Z' }, ...VERSIONS.slice(1)],
    })
    mockWorkflows(api, { extra: [['workflows/w-1', () => ({ data: current })], ['workflows/w-1/versions', versions]] })
    api.post.mockImplementation(async (path) => {
      if (path !== 'workflows/w-1/discard-draft') return {}
      current = { ...current, draft: null }
      return { data: current }
    })
    const { container } = renderApp('/settings/workflows/w-1')
    await waitFor(() => expect(node(container, 'manager')).not.toBeNull())

    fireEvent.pointerDown(screen.getByRole('button', { name: 'More' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Discard draft' }))
    const dialog = await screen.findByRole('dialog', { name: 'Discard draft v4?' })
    expect(within(dialog).getByText(/goes back to v3, the live version/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Discard draft' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('workflows/w-1/discard-draft'))
    expect(await screen.findByText('Live v3')).toBeInTheDocument()
    expect(screen.queryByText('Draft v4')).not.toBeInTheDocument()
    await waitFor(() => expect(node(container, 'manager')).toBeNull())
    expect(node(container, 'budget')).not.toBeNull()
    expect(api.put).not.toHaveBeenCalled()

    // Kept in the version list, never offered for roll back.
    fireEvent.pointerDown(screen.getByRole('button', { name: 'More' }), { button: 0, ctrlKey: false })
    expect(screen.queryByRole('menuitem', { name: 'Discard draft' })).not.toBeInTheDocument()
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Versions' }))
    const list = await screen.findByRole('dialog', { name: 'Versions' })
    expect(await within(list).findByText('Discarded')).toBeInTheDocument()
    expect(within(list).queryByRole('button', { name: 'Roll back to v4' })).not.toBeInTheDocument()
    expect(within(list).getByRole('button', { name: 'Roll back to v2' })).toBeInTheDocument()
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

  it('sets a sequential approval chain, reorders it and describes it on the card (APR-01)', async () => {
    const CFO = '0192a1b2-0000-7000-8000-0000000000c1'
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'manager'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })

    chooseOption(within(panel).getByLabelText(/^How approvers take turns/), 'One after another')
    const chain = within(panel).getByRole('list', { name: 'Approvers in order' })
    expect(within(chain).getAllByRole('listitem')).toHaveLength(1)
    expect(within(panel).getByRole('button', { name: 'Remove Approver 1' })).toBeDisabled()
    fireEvent.click(within(panel).getByRole('button', { name: 'Add an approver' }))
    const second = within(chain).getAllByRole('listitem')[1]
    chooseOption(within(second).getByLabelText(/^Who approves/), 'Anyone with a role')
    chooseOption(within(second).getByLabelText(/^Role/), 'CFO')
    expect(within(node(container, 'manager')).getByText('Manager of the requester’s branch, then Role: CFO · escalates after 8 business hours')).toBeInTheDocument()

    fireEvent.click(within(panel).getByRole('button', { name: 'Move Approver 2 up' }))
    expect(within(node(container, 'manager')).getByText('Role: CFO, then Manager of the requester’s branch · escalates after 8 business hours')).toBeInTheDocument()
    await waitFor(
      () =>
        expect(lastPut()?.[1].graph.nodes.find((one) => one.id === 'manager').approval).toEqual({
          mode: 'any',
          chain: [{ type: 'role', role: CFO }, { type: 'branch_manager' }],
        }),
      { timeout: 3000 },
    )

    // Back to one approver: the chain's first becomes the approver.
    chooseOption(within(panel).getByLabelText(/^How approvers take turns/), 'One approver')
    expect(within(node(container, 'manager')).getByText('Role: CFO · escalates after 8 business hours')).toBeInTheDocument()
  })

  it('sets up to five reminders on an approval (APR-05)', async () => {
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'manager'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })

    fireEvent.change(within(panel).getByLabelText(/^Remind after/), { target: { value: '4' } })
    for (const number of [2, 3, 4, 5]) {
      fireEvent.click(within(panel).getByRole('button', { name: 'Add a reminder' }))
      fireEvent.change(within(panel).getByLabelText(new RegExp(`^Reminder ${number} after`)), { target: { value: String(number * 4) } })
    }
    expect(within(panel).queryByRole('button', { name: 'Add a reminder' })).not.toBeInTheDocument()
    fireEvent.click(within(panel).getByRole('button', { name: 'Remove reminder 1' }))
    await waitFor(
      () =>
        expect(lastPut()?.[1].graph.nodes.find((one) => one.id === 'manager').reminders).toEqual(
          [8, 12, 16, 20].map((amount) => ({ amount, unit: 'business_hours' })),
        ),
      { timeout: 3000 },
    )
    expect(within(panel).getByRole('button', { name: 'Add a reminder' })).toBeInTheDocument()
  })

  it('hints on an approval card when its Rejected connection is missing', async () => {
    const graph = { ...GRAPH, edges: GRAPH.edges.filter((edge) => !(edge.from === 'cfo' && edge.branch === 'rejected')) }
    mockWorkflows(api, { workflow: { ...WORKFLOW, draft: { ...WORKFLOW.draft, graph } } })
    const { container } = renderApp('/settings/workflows/w-1')
    await waitFor(() => expect(node(container, 'cfo')).not.toBeNull())

    expect(within(node(container, 'cfo')).getByText('Connect Rejected to a next step')).toBeInTheDocument()
    expect(within(node(container, 'manager')).queryByText('Connect Rejected to a next step')).not.toBeInTheDocument()
  })

  it('sets a stage’s reminders and who it escalates to (WF-09)', async () => {
    const CFO = '0192a1b2-0000-7000-8000-0000000000c1'
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'budget'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })

    fireEvent.change(within(panel).getByLabelText(/^Remind after/), { target: { value: '2' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Add a reminder' }))
    fireEvent.change(within(panel).getByLabelText(/^Reminder 2 after/), { target: { value: '6' } })
    // Notify only: a role or a named person, no "next level" or automatic outcome.
    expect(within(panel).queryByLabelText(/^At the final/)).not.toBeInTheDocument()
    chooseOption(within(panel).getByLabelText(/^Tell someone when it waits too long/), 'Anyone with a role')
    chooseOption(within(panel).getByLabelText(/^Role/), 'CFO')
    fireEvent.change(within(panel).getByLabelText(/^Escalate after/), { target: { value: '8' } })

    const budget = () => lastPut()?.[1].graph.nodes.find((one) => one.id === 'budget')
    await waitFor(
      () => {
        expect(budget()?.reminders).toEqual([2, 6].map((amount) => ({ amount, unit: 'business_hours' })))
        expect(budget()?.escalation).toEqual({ to: { type: 'role', role: CFO }, after: { amount: 8, unit: 'business_hours' } })
      },
      { timeout: 3000 },
    )

    chooseOption(within(panel).getByLabelText(/^Tell someone when it waits too long/), 'Nobody')
    // Undefined is left out of the JSON sent.
    await waitFor(() => expect(budget()?.escalation).toBeUndefined(), { timeout: 3000 })
  })

  it('lists the API’s new flow problems as it words them', async () => {
    const { container } = await openBuilder()
    const problems = [
      { code: 'approval_without_rejected', message: '“CFO approves” needs a Rejected connection.', node: 'cfo' },
      { code: 'approval_in_parallel', message: 'Approvals cannot run in parallel branches.', node: 'manager' },
      { code: 'rejected_reaches_approved', message: 'A rejection of “Branch manager approves” can reach an approved end.', node: 'manager' },
      { code: 'stage_reminders', message: '“Check budget” can have at most 5 reminders.', node: 'budget' },
      { code: 'stage_escalation', message: '“Check budget” escalates to nobody.', node: 'budget' },
    ]
    api.post.mockImplementation(async (path) => (path === 'workflows/w-1/validate' ? { data: { valid: false, problems } } : {}))
    fireEvent.click(screen.getByRole('button', { name: 'Check for problems' }))
    const issues = await screen.findByRole('region', { name: 'Problems to fix before publishing' })
    for (const problem of problems) expect(within(issues).getByText(problem.message)).toBeInTheDocument()
    for (const id of ['cfo', 'manager', 'budget']) expect(card(container, id)).toHaveAttribute('data-problem', 'true')
  })

  it('shows a new approval step as not requiring a reason, as the API reads it', async () => {
    const { container } = await openBuilder()
    fireEvent.click(node(container, 'manager'))
    const panel = await screen.findByRole('complementary', { name: 'Step settings' })
    expect(within(panel).getByRole('checkbox', { name: /reason/i })).not.toBeChecked()
    expect(within(panel).getByRole('checkbox', { name: /delegation/i })).toBeChecked()
  })
})
