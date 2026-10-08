import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, optionTexts, waitForOption } from '@/test/combobox'
import { AUTOMATION_PERMISSIONS, CATALOGUE, CREDIT_LIMIT_CHANGE, mockAutomation, ROLE_ID, RULE, USER_ID } from '@/test/automation'
import { apiError, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const type = (label, value, scope) => fireEvent.change((scope ? within(scope) : screen).getByLabelText(label), { target: { value } })
const trigger = () => screen.getByText('When', { selector: 'h3' }).closest('[data-slot="card"]') ?? document.body
const actionItem = (number) => screen.getByRole('listitem', { name: new RegExp(`^Action ${number}:`) })

async function openNew() {
  const view = renderApp('/settings/automation-rules/new')
  await screen.findByRole('heading', { name: 'New automation rule' })
  type(/^Name/, 'My rule')
  return view
}

function addAction(name) {
  chooseOption('Action to add', name)
  fireEvent.click(screen.getByRole('button', { name: 'Add action' }))
}

function savedBody() {
  const call = api.post.mock.calls.find(([path]) => path === 'automation-rules')
  return call?.[1]
}

describe('Automation rule editor (AUTO-01..AUTO-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    api.post.mockImplementation(async (path, body) => ({ data: { ...RULE, ...body, id: 'r-2', status: 'disabled', has_webhook_secret: false } }))
    api.patch.mockImplementation(async (path, body) => ({ data: { ...RULE, ...body, id: 'r-2', status: 'disabled', has_webhook_secret: false } }))
  })

  it('builds a weekly schedule in plain words and a notification to roles and people', async () => {
    mockAutomation(api)
    await openNew()
    chooseOption('Trigger', 'Schedule')
    chooseOption('Repeats', 'Every week')
    fireEvent.click(screen.getByLabelText('Thursday'))
    type('Time', '09:30')
    expect(screen.getByTestId('rule-summary')).toHaveTextContent('Every week on Monday and Thursday at 09:30')
    // A schedule has no document: actions that need one are not offered.
    chooseOption('Action to add', 'Send a notification')
    fireEvent.click(screen.getByRole('button', { name: 'Add action' }))
    const action = actionItem(1)
    fireEvent.click(await within(action).findByLabelText('Finance'))
    fireEvent.click(within(action).getByLabelText('Baraka Mwangi'))
    type('Subject', 'Weekly check', action)
    type('Message', 'Rule {rule_name} ran', action)
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))

    await waitFor(() => expect(savedBody()).toBeTruthy())
    expect(savedBody()).toEqual({
      name: 'My rule',
      document_type: 'procurement.requisition',
      company_id: null,
      trigger: { type: 'schedule', every: 'week', time: '09:30', days: ['mon', 'thu'] },
      conditions: null,
      actions: [{ type: 'notify', to: [`role:${ROLE_ID}`, `user:${USER_ID}`], subject: 'Weekly check', message: 'Rule {rule_name} ran' }],
    })
  })

  it('builds a date trigger, a money field update as string minor units and an assignment', async () => {
    mockAutomation(api)
    await openNew()
    chooseOption('Trigger', 'Date arrives')
    type('Days', '30')
    expect(screen.getByTestId('rule-summary')).toHaveTextContent('30 days before Needed by')

    addAction('Update a field')
    chooseOption(within(actionItem(1)).getByLabelText('Field to update'), 'Total')
    const amount = within(actionItem(1)).getByLabelText('New value')
    fireEvent.change(amount, { target: { value: '1,250.50' } })
    addAction('Assign a person')
    chooseOption(within(actionItem(2)).getByLabelText('Person'), 'Baraka Mwangi')
    addAction('Set credit hold')
    type('Reason', 'Overdue', actionItem(3))
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))

    await waitFor(() => expect(savedBody()).toBeTruthy())
    const body = savedBody()
    expect(body.trigger).toEqual({ type: 'date', field: 'needed_by', days: 30, when: 'before' })
    expect(body.actions).toEqual([
      { type: 'update_field', field: 'total', value: { amount_minor: '125050', currency: 'KES' } },
      { type: 'assign_user', field: 'owner', user: USER_ID },
      { type: 'set_credit_hold', hold: true, reason: 'Overdue' },
    ])
    expect(typeof body.actions[0].value.amount_minor).toBe('string')
  })

  it('builds threshold triggers on money and numbers without floats', async () => {
    mockAutomation(api)
    await openNew()
    chooseOption('Trigger', 'Threshold crossed')
    chooseOption(within(trigger()).getByLabelText('Field'), 'Total')
    type('Level', '500')
    chooseOption('Runs when the value', 'Rises above')
    expect(screen.getByTestId('rule-summary')).toHaveTextContent('When Total rises above the level')
    addAction('Call a webhook')
    type('Webhook address', 'https://hooks.example.com/in')
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))
    await waitFor(() => expect(savedBody()).toBeTruthy())
    expect(savedBody().trigger).toEqual({ type: 'threshold', field: 'total', value: { amount_minor: '50000', currency: 'KES' }, direction: 'up' })
    expect(savedBody().actions).toEqual([{ type: 'webhook', url: 'https://hooks.example.com/in' }])

    chooseOption(within(trigger()).getByLabelText('Field'), 'Quantity')
    type('Level', '10')
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled()
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1].trigger).toEqual({ type: 'threshold', field: 'quantity', value: '10', direction: 'up' })
  })

  it('builds field, record and stage triggers', async () => {
    mockAutomation(api)
    await openNew()
    const scope = () => within(trigger())

    chooseOption('Trigger', 'Field changed')
    chooseOption(scope().getByLabelText('Field'), 'Category')
    fireEvent.click(screen.getByLabelText('Only when it changes to'))
    chooseOption(screen.getAllByLabelText('Only when it changes to').find((one) => one.getAttribute('role') === 'combobox'), 'services')
    addAction('Change stage')
    type('Stage to complete', 'review')
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))
    await waitFor(() => expect(savedBody()).toBeTruthy())
    expect(savedBody().trigger).toEqual({ type: 'field_changed', field: 'category', to: 'services' })
    expect(savedBody().actions).toEqual([{ type: 'change_stage', mode: 'move', stage: 'review' }])

    const saveChanges = async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
      await waitFor(() => expect(api.patch).toHaveBeenCalled())
      const body = api.patch.mock.calls.at(-1)[1]
      api.patch.mockClear()
      return body
    }

    chooseOption('Trigger', 'Record changed')
    fireEvent.click(scope().getByLabelText('Quantity'))
    expect((await saveChanges()).trigger).toEqual({ type: 'record_updated', fields: ['quantity'] })

    chooseOption('Trigger', 'Stage left')
    type('Stage', 'approval', trigger())
    chooseOption('How it leaves', 'Sent back')
    expect((await saveChanges()).trigger).toEqual({ type: 'stage_left', stage: 'approval', how: 'returned' })

    chooseOption('Trigger', 'Record archived')
    expect((await saveChanges()).trigger).toEqual({ type: 'record_archived' })
  })

  it('offers only the triggers and actions the type supports (AUTO-01, AUTO-03)', async () => {
    mockAutomation(api, { extra: [['automation/catalogue', { ...CATALOGUE, data: [...CATALOGUE.data, CREDIT_LIMIT_CHANGE] }]] })
    await openNew()
    await waitForOption('Document type', 'Credit limit change')
    chooseOption('Document type', 'Credit limit change')
    expect(optionTexts('Trigger')).toEqual(['Stage entered', 'Stage left', 'Schedule'])
    const actions = optionTexts('Action to add')
    expect(actions).toContain('Send a notification')
    expect(actions).not.toContain('Change stage')
    expect(actions).not.toContain('Update a field')
  })

  it('offers the stages of the type’s workflow', async () => {
    mockAutomation(api, {
      extra: [[/^workflows\?type=/, { data: [{ id: 'w-1', published: { graph: { nodes: [{ id: 'start', type: 'start' }, { id: 'budget', type: 'stage', name: 'Check budget' }, { id: 'cfo', type: 'approval', name: 'CFO approves' }] } }, draft: null }] }]],
    })
    await openNew()
    chooseOption('Trigger', 'Stage entered')
    await waitFor(() => expect(within(trigger()).getByLabelText('Stage').getAttribute('role')).toBe('combobox'))
    chooseOption(within(trigger()).getByLabelText('Stage'), 'CFO approves')
    expect(screen.getByTestId('rule-summary')).toHaveTextContent('When a Purchase requisition enters “CFO approves”')
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))
    await waitFor(() => expect(savedBody()).toBeTruthy())
    expect(savedBody().trigger).toEqual({ type: 'stage_entered', stage: 'cfo' })
  })

  it('reorders and removes actions', async () => {
    mockAutomation(api)
    await openNew()
    addAction('Send a notification')
    addAction('Call a webhook')
    fireEvent.click(screen.getByRole('button', { name: 'Move action 2 up' }))
    expect(actionItem(1)).toHaveAccessibleName('Action 1: Call a webhook')
    fireEvent.click(screen.getByRole('button', { name: 'Remove action 1' }))
    expect(actionItem(1)).toHaveAccessibleName('Action 1: Send a notification')
    expect(screen.queryByRole('listitem', { name: /^Action 2:/ })).not.toBeInTheDocument()
  })

  it('enables a saved rule and shows the API’s problems at their paths', async () => {
    mockAutomation(api)
    api.post.mockRejectedValue(apiError(422, 'rule_invalid', 'The rule has problems.', { 'actions.0': ['Nobody to notify.'], trigger: ['The trigger is not valid.'] }))
    renderApp('/settings/automation-rules/r-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Enable' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('automation-rules/r-1/enable'))
    expect(await within(actionItem(1)).findByText('Nobody to notify.')).toBeInTheDocument()
    expect(screen.getByText('The trigger is not valid.')).toBeInTheDocument()
    expect(screen.getByText('The rule can’t be saved yet. Fix the problems marked below.')).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
  })

  it('disables an enabled rule and archives after confirming in the page', async () => {
    mockAutomation(api, { rule: { ...RULE, enabled: true, status: 'enabled' } })
    api.post.mockImplementation(async (path) => ({ data: { ...RULE, status: path.endsWith('archive') ? 'archived' : 'disabled' } }))
    renderApp('/settings/automation-rules/r-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Disable' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('automation-rules/r-1/disable'))
    fireEvent.click(await screen.findByRole('button', { name: 'Enable' }).then(() => screen.getByRole('button', { name: 'Archive rule' })))
    const confirm = screen.getByRole('group', { name: 'Confirm archiving' })
    fireEvent.click(within(confirm).getByRole('button', { name: 'Archive rule now' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('automation-rules/r-1/archive'))
    expect(await screen.findByText('This rule is archived. It no longer runs and can’t be changed.')).toBeInTheDocument()
  })

  it('shows a new webhook secret once, hides it after a reload, and rotates it', async () => {
    const saved = { ...RULE, id: 'r-2', actions: [{ type: 'webhook', url: 'https://hooks.example.com/in' }], has_webhook_secret: true }
    mockAutomation(api, { rule: saved })
    api.post.mockImplementation(async (path) => {
      if (path === 'automation-rules') return { data: { ...saved, webhook_secret: 'whsec_first_0123456789' } }
      if (path === 'automation-rules/r-2/webhook-secret/rotate') return { data: { ...saved, webhook_secret: 'whsec_second_0123456789' } }
      throw new Error(path)
    })
    const first = await openNew()
    addAction('Call a webhook')
    type('Webhook address', 'https://hooks.example.com/in')
    expect(screen.getByText('A secret is created when you first save the rule.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))
    expect(await screen.findByTestId('webhook-secret')).toHaveTextContent('whsec_first_0123456789')
    expect(screen.getByText('Copy this secret now')).toBeInTheDocument()
    await waitFor(() => expect(first.router.state.location.pathname).toBe('/settings/automation-rules/r-2'))
    expect(screen.getByTestId('webhook-secret')).toHaveTextContent('whsec_first_0123456789')
    first.unmount()

    renderApp('/settings/automation-rules/r-2')
    expect(await screen.findByText('A secret is set. It was shown once, when it was created.')).toBeInTheDocument()
    expect(screen.queryByTestId('webhook-secret')).not.toBeInTheDocument()
    expect(screen.queryByText(/whsec_first/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Rotate secret' }))
    fireEvent.click(within(screen.getByRole('group', { name: 'Rotate the signing secret' })).getByRole('button', { name: 'Rotate secret now' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('automation-rules/r-2/webhook-secret/rotate'))
    expect(await screen.findByTestId('webhook-secret')).toHaveTextContent('whsec_second_0123456789')
  })

  it('tests the rule and says nothing was changed', async () => {
    mockAutomation(api)
    api.post.mockResolvedValue({
      data: {
        trigger: { type: 'record_created', description: 'When a Purchase requisition is created', matches: true, details: null, next_run_at: null },
        conditions: {
          passed: false,
          checks: [{ field: 'total', op: 'gt', expected: { amount_minor: '25000000', currency: 'KES' }, actual: { amount_minor: '100', currency: 'KES' }, passed: false, other: null, problem: null }],
          failures: [],
          reasons: ['Total is KES 1.00, not more than KES 250,000.00'],
        },
        would_run: false,
        actions: [{ type: 'notify', description: 'Would notify 2 people (Finance): “Big requisition”.' }],
      },
    })
    renderApp('/settings/automation-rules/r-1?tab=test')
    await screen.findByRole('heading', { name: 'Test the rule' })
    const total = screen.getAllByLabelText('Total').find((one) => one.tagName === 'INPUT')
    fireEvent.change(total, { target: { value: '1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Run test' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('automation-rules/r-1/test', { values: { total: { amount_minor: '100', currency: 'KES' } } }))
    expect(await screen.findByText('Nothing was changed. This is only what the rule would do.')).toBeInTheDocument()
    expect(screen.getByText('Matches')).toBeInTheDocument()
    expect(screen.getByText('Failed')).toBeInTheDocument()
    expect(screen.getByText('Total is KES 1.00, not more than KES 250,000.00')).toBeInTheDocument()
    expect(screen.getByText('Would not run')).toBeInTheDocument()
    expect(screen.getByText('Would notify 2 people (Finance): “Big requisition”.')).toBeInTheDocument()
  })

  it('tests unsaved changes, with the values before a change', async () => {
    mockAutomation(api)
    api.post.mockResolvedValue({ data: { trigger: { type: 'field_changed', description: 'x', matches: null, details: null, next_run_at: null }, conditions: null, would_run: true, actions: [] } })
    renderApp('/settings/automation-rules/r-1')
    await screen.findByRole('button', { name: 'Enable' })
    chooseOption('Trigger', 'Field changed')
    chooseOption(within(trigger()).getByLabelText('Field'), 'Note')
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Test' }), { button: 0, ctrlKey: false })
    const [now, before] = screen.getAllByLabelText('Note')
    fireEvent.change(now, { target: { value: 'new' } })
    fireEvent.change(before, { target: { value: 'old' } })
    fireEvent.click(screen.getByRole('button', { name: 'Run test' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [path, body] = api.post.mock.calls[0]
    expect(path).toBe('automation-rules/test')
    expect(body).toMatchObject({ rule_id: 'r-1', document_type: 'procurement.requisition', trigger: { type: 'field_changed', field: 'note' }, values: { note: 'new' }, old_values: { note: 'old' } })
    expect(await screen.findByText('Can’t tell from a sample')).toBeInTheDocument()
  })

  it('keeps a saved webhook’s stored address unless a new one is typed', async () => {
    const hook = { type: 'webhook', id: 'a-1', url_display: 'hooks.example.com/in', has_url: true }
    mockAutomation(api, { rule: { ...RULE, actions: [hook], has_webhook_secret: true } })
    renderApp('/settings/automation-rules/r-1')
    expect(await screen.findByTestId('webhook-url')).toHaveTextContent('hooks.example.com/in')
    expect(screen.queryByLabelText('Webhook address')).not.toBeInTheDocument()

    type(/^Name/, 'Renamed')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1].actions).toEqual([{ type: 'webhook', id: 'a-1' }])
    api.patch.mockClear()

    fireEvent.click(await screen.findByRole('button', { name: 'Change URL' }))
    type('New webhook address', 'https://new.example.com/hook')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1].actions).toEqual([{ type: 'webhook', id: 'a-1', url: 'https://new.example.com/hook' }])
  })

  it('tests an edited rule with a saved webhook by naming the rule, without the address', async () => {
    const hook = { type: 'webhook', id: 'a-1', url_display: 'hooks.example.com/in', has_url: true }
    mockAutomation(api, { rule: { ...RULE, actions: [hook], has_webhook_secret: true } })
    api.post.mockResolvedValue({ data: { trigger: { type: 'record_created', description: 'x', matches: true, details: null, next_run_at: null }, conditions: null, would_run: true, actions: [] } })
    renderApp('/settings/automation-rules/r-1')
    await screen.findByTestId('webhook-url')
    type(/^Name/, 'Renamed')
    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Test' }), { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('button', { name: 'Run test' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [path, body] = api.post.mock.calls[0]
    expect(path).toBe('automation-rules/test')
    expect(body.rule_id).toBe('r-1')
    expect(body.name).toBe('Renamed')
    expect(body.actions).toEqual([{ type: 'webhook', id: 'a-1' }])
  })

  it('starts money in the rule’s company’s base currency', async () => {
    mockAutomation(api, { extra: [['tenant/currencies', { data: [{ code: 'CDF', active: true }, { code: 'KES', active: true }, { code: 'USD', active: true }] }]] })
    await openNew()
    chooseOption(within(screen.getByText('Details', { selector: 'h3' }).closest('[data-slot="card"]')).getByLabelText('Company'), 'Kin Market')
    chooseOption('Trigger', 'Threshold crossed')
    chooseOption(within(trigger()).getByLabelText('Field'), 'Total')
    type('Level', '5000')
    addAction('Update a field')
    chooseOption(within(actionItem(1)).getByLabelText('Field to update'), 'Total')
    fireEvent.change(within(actionItem(1)).getByLabelText('New value'), { target: { value: '2500' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save switched off' }))
    await waitFor(() => expect(savedBody()).toBeTruthy())
    expect(savedBody().company_id).toBe('c-2')
    expect(savedBody().trigger.value).toEqual({ amount_minor: '5000', currency: 'CDF' })
    expect(savedBody().actions[0].value).toEqual({ amount_minor: '2500', currency: 'CDF' })
  })

  it('is read-only without the edit right', async () => {
    mockAutomation(api, { permissions: tenantWide(['core.automation.view', 'core.company.view']) })
    renderApp('/settings/automation-rules/r-1')
    expect(await screen.findByText('You can view this rule but not change it.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Enable' })).not.toBeInTheDocument()
    expect(AUTOMATION_PERMISSIONS).toBeTruthy()
  })
})
