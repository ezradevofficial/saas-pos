import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { approvalDetail, approvalItem, page } from '@/test/approvals'
import { chooseOption } from '@/test/combobox'
import { ALL_CORE, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const doc = (number, title) => ({
  type: 'procurement.requisition',
  type_label: 'Purchase requisition',
  id: `doc-${number}`,
  number,
  title,
  amount: { amount_minor: '11845000', currency: 'KES' },
})

const WAITING = approvalItem('a', { document: doc('PR-NBO-00231', 'Cooking oil restock, 40 cartons') })
const DELEGATED = approvalItem('b', {
  document: { ...doc('LV-0098', 'Annual leave, 5 days'), type: 'hr.leave', type_label: 'Leave request', amount: null },
  my_assignment: { id: 'as-b', status: 'pending', delegated_from: { id: 'u-6', name: 'Jean Kabila' }, decided_at: null, on_behalf_of: null },
})
const OVERDUE = approvalItem('c', { document: doc('ADJ-KLM-0042', 'Write-off: 12 broken bottles'), overdue: true })
const BLOCKED = approvalItem('d', {
  document: doc('PAY-CD-0317', 'Bralima invoice F-88214'),
  blocked_reason: 'no_approver',
  blocked_label: 'Nobody can approve this step.',
  can: { approve: false, reject: false, return: false, request_info: false, bulk_approve: false, comment: true, attach: false, reassign: false },
})
const DECIDED = approvalItem('e', { status: 'approved', decided_at: '2026-10-07T09:00:00Z', can: { comment: false }, document: doc('PR-NBO-00199', 'Sugar restock') })

const USERS = [
  { id: 'u-1', name: 'Amina Otieno', email: 'amina@example.com' },
  { id: 'u-5', name: 'Brian Kiprop', email: 'brian@example.com' },
  { id: 'u-9', name: 'Grace Wanjiru', email: 'grace@example.com' },
]

const COMPANIES = [
  { id: 'c-1', name: 'Amani Retail Ltd', archived_at: null },
  { id: 'c-2', name: 'Kin Market', archived_at: null },
]

function setup({ rows = [WAITING, DELEGATED, OVERDUE, BLOCKED], detail = {}, permissions = ALL_CORE, routes = [] } = {}) {
  const details = Object.fromEntries(rows.map((row) => [row.id, approvalDetail(row, detail[row.id] ?? {})]))
  mockRoutes(
    api,
    [
      ...routes,
      ['approvals?status=waiting&per_page=1&page=1', page([], 4)],
      [/^approvals\?status=waiting&/, page(rows)],
      [/^approvals\?status=decided&/, page([DECIDED])],
      [/^approvals\?view=all&status=all&/, page([...rows, DECIDED])],
      [/^approvals\/[a-z]$/, (path) => ({ data: details[path.split('/')[1]] ?? approvalDetail(DECIDED) })],
      ['me/delegations', { data: [] }],
      ['users?status=active&per_page=200', { data: USERS, meta: { total: USERS.length } }],
    ],
    { permissions, companies: COMPANIES },
  )
  return details
}

const items = () => screen.getByRole('list', { name: 'Requests' })
const itemOf = (text) => within(items()).getByText(text).closest('li')
const detailPane = () => screen.getByRole('article', { name: 'Request detail' })

describe('approvals inbox (APR-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists what waits for the user with status words, amounts and time limits', async () => {
    setup()
    renderApp('/approvals')
    await screen.findByText('Cooking oil restock, 40 cartons')
    expect(screen.getByRole('heading', { level: 1, name: 'Approvals' })).toBeInTheDocument()
    expect(screen.getByText('Everything waiting for you, across all modules and companies')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Waiting for you/ })).toHaveTextContent('4')
    expect(screen.queryByRole('tab', { name: 'All in the organisation' })).not.toBeInTheDocument()

    const waiting = itemOf('Cooking oil restock, 40 cartons')
    expect(waiting).toHaveTextContent('Purchase requisition · PR-NBO-00231')
    expect(within(waiting).getByText('Waiting for you')).toBeInTheDocument()
    expect(waiting).toHaveTextContent('Total before VATKES 118,450.00')
    expect(waiting).toHaveTextContent(/Escalates to the area manager if not acted on by/)
    expect(waiting).toHaveTextContent(/Waiting since 7 Oct 2026/)

    expect(within(itemOf('Annual leave, 5 days')).getByText('Delegated from Jean Kabila', { selector: '[data-slot="badge"]' })).toBeInTheDocument()
    expect(within(itemOf('Write-off: 12 broken bottles')).getByText('Overdue')).toBeInTheDocument()
    expect(within(itemOf('Bralima invoice F-88214')).getByText('Blocked: no approver')).toBeInTheDocument()
    // Only items the user may approve in bulk can be chosen.
    expect(within(itemOf('Bralima invoice F-88214')).queryByRole('checkbox')).not.toBeInTheDocument()

    // The sidebar counts what waits.
    expect(await screen.findByTestId('approvals-waiting')).toHaveTextContent('4')
  })

  it('sends the tab, filters, search and sort to the API', async () => {
    setup({ permissions: [...ALL_CORE, ...tenantWide(['core.approval.view_all'])] })
    const { router } = renderApp('/approvals')
    await screen.findByText('Cooking oil restock, 40 cartons')
    expect(api.get).toHaveBeenCalledWith('approvals?status=waiting&sort=due&per_page=25&page=1')

    const listSection = screen.getByRole('region', { name: 'Approvals' })
    chooseOption(within(listSection).getByLabelText('Document type'), 'Purchase requisition')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^approvals\?status=waiting&type=procurement\.requisition&sort=due&/)))
    chooseOption(within(listSection).getByLabelText('Company'), 'Kin Market')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/&company=c-2&/)))
    chooseOption(within(listSection).getByLabelText('Due'), 'Overdue only')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/&overdue=1&/)))
    chooseOption(within(listSection).getByLabelText('Sort by'), 'Newest first')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/&sort=-received&/)))
    fireEvent.change(within(listSection).getByLabelText('Search'), { target: { value: 'oil' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/&search=oil&/)))

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Decided' }), { button: 0 })
    await waitFor(() => expect(router.state.location.search).toBe('?tab=decided'))
    expect(await screen.findByText('Sugar restock')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('approvals?status=decided&sort=due&per_page=25&page=1')
    expect(within(itemOf('Sugar restock')).getByText('Approved')).toBeInTheDocument()

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'All in the organisation' }), { button: 0 })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('approvals?view=all&status=all&sort=due&per_page=25&page=1'))
  })

  it('approves the chosen items in bulk and says which failed and why', async () => {
    setup()
    api.post.mockResolvedValue({
      data: { approved: ['a'], failed: [{ id: 'c', code: 'bulk_not_allowed', message: 'This approval needs a reason, so it cannot be approved in bulk.' }] },
    })
    renderApp('/approvals')
    await screen.findByText('Cooking oil restock, 40 cartons')
    const approveAll = screen.getByRole('button', { name: 'Approve all' })
    expect(approveAll).toBeDisabled()

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Purchase requisition PR-NBO-00231' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select Purchase requisition ADJ-KLM-0042' }))
    expect(screen.getByText('2 selected')).toBeInTheDocument()
    // While items are chosen, Approve all is the screen's one decisive action.
    expect(approveAll).toHaveAttribute('data-ds-variant', 'pay')
    expect(within(detailPane()).getByRole('button', { name: 'Approve' })).toHaveAttribute('data-ds-variant', 'primary')

    fireEvent.click(approveAll)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/bulk-approve', { ids: ['a', 'c'] }))
    const failure = await screen.findByText('1 request was not approved')
    expect(failure.closest('[role="alert"]')).toHaveTextContent('Purchase requisition ADJ-KLM-0042: This approval needs a reason, so it cannot be approved in bulk.')
    expect(await screen.findByText('Approved 1 request')).toBeInTheDocument()
  })
})

describe('approval detail (APR-03, APR-04, APR-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the summary, why this route, the time limit, approvers, attachments and history', async () => {
    setup()
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    await within(pane).findByText('Under KES 250,000, so the CFO step is skipped.')
    expect(within(pane).getByRole('heading', { name: 'Cooking oil restock, 40 cartons' })).toBeInTheDocument()
    expect(pane).toHaveTextContent('Total before VATKES 118,450.00')
    expect(pane).toHaveTextContent('Requested byGrace Wanjiru')
    expect(pane).toHaveTextContent('Step2 of 3 · Branch manager approves')
    expect(within(pane).getByText(/^Escalates to the area manager if not acted on by/)).toBeInTheDocument()
    expect(within(pane).getByText(/Approved · Brian Kiprop on behalf of Jean Kabila/)).toBeInTheDocument()
    expect(within(pane).getByText('Stock is low')).toBeInTheDocument()
    expect(within(pane).getByRole('link', { name: 'quote.pdf' })).toHaveAttribute('href', 'https://files.example/quote.pdf?signature=x')
    expect(within(pane).getByText('Amina Otieno')).toBeInTheDocument()
  })

  it('lists the checks as met or not met when the user may not see the values', async () => {
    setup({ detail: { a: { route: [{ node_id: 'big', node_name: 'Total over KES 250,000?', kind: 'condition', branch: 'no', checks: [{ field: 'total', label: 'Total', op: 'gt', passed: false }], explanations: null }] } } })
    renderApp('/approvals/a')
    expect(await screen.findByText('Total over KES 250,000?: Total did not meet the condition')).toBeInTheDocument()
  })

  it('approves with the accent button and tells the user', async () => {
    setup()
    api.post.mockImplementation(async (path) => ({ data: approvalDetail({ ...WAITING, status: 'approved', can: {} }) }))
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    const approve = await within(pane).findByRole('button', { name: 'Approve' })
    expect(approve).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(approve)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/approve', {}))
    expect(await screen.findByText('Approved Purchase requisition PR-NBO-00231')).toBeInTheDocument()
  })

  it('rejects only with a reason', async () => {
    setup()
    api.post.mockResolvedValue({ data: approvalDetail(WAITING) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.click(await within(pane).findByRole('button', { name: 'Reject' }))
    const form = screen.getByRole('form', { name: 'Reject Purchase requisition PR-NBO-00231' })
    fireEvent.click(within(form).getByRole('button', { name: 'Reject' }))
    expect(await within(form).findByText('Give a reason for rejecting.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(within(form).getByLabelText(/Reason/), { target: { value: 'Too expensive' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Reject' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/reject', { comment: 'Too expensive' }))
    expect(await screen.findByText('Rejected Purchase requisition PR-NBO-00231')).toBeInTheDocument()
  })

  it('asks for a reason before approving when the step requires one', async () => {
    const strict = { ...WAITING, require_reason: true }
    setup({ rows: [strict] })
    api.post.mockResolvedValue({ data: approvalDetail(strict) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.click(await within(pane).findByRole('button', { name: 'Approve' }))
    expect(api.post).not.toHaveBeenCalled()
    const form = screen.getByRole('form', { name: 'Approve Purchase requisition PR-NBO-00231' })
    fireEvent.change(within(form).getByLabelText(/Reason/), { target: { value: 'Budgeted' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/approve', { comment: 'Budgeted' }))
  })

  it('returns for changes to a chosen stage with a reason', async () => {
    setup({ detail: { a: { return_targets: [{ node_id: 'start', name: 'Requisition submitted' }, { node_id: 'budget', name: 'Check budget' }] } } })
    api.post.mockResolvedValue({ data: approvalDetail(WAITING) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.click(await within(pane).findByRole('button', { name: 'Return for changes' }))
    const form = screen.getByRole('form', { name: 'Return Purchase requisition PR-NBO-00231 for changes' })
    chooseOption(within(form).getByLabelText(/Return to/), 'Check budget')
    fireEvent.change(within(form).getByLabelText(/What needs to change/), { target: { value: 'Split by supplier' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Return for changes' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/return', { node: 'budget', reason: 'Split by supplier' }))
  })

  it('asks the requester for more information', async () => {
    setup()
    api.post.mockResolvedValue({ data: approvalDetail(WAITING) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.click(await within(pane).findByRole('button', { name: 'Request more information' }))
    const form = screen.getByRole('form', { name: 'Request more information' })
    fireEvent.change(within(form).getByLabelText(/Your question/), { target: { value: 'Which supplier?' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Send question' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/request-info', { comment: 'Which supplier?' }))
    expect(await screen.findByText('Question sent to the requester')).toBeInTheDocument()
  })

  it('lets an administrator reassign to an active user other than the requester', async () => {
    const admin = { ...WAITING, can: { ...WAITING.can, reassign: true } }
    setup({ rows: [admin] })
    api.post.mockResolvedValue({ data: approvalDetail(admin) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.click(await within(pane).findByRole('button', { name: 'Reassign' }))
    const form = screen.getByRole('form', { name: 'Reassign Purchase requisition PR-NBO-00231' })
    expect(within(form).getByText('Takes the place of Amina Otieno.')).toBeInTheDocument()
    const picker = within(form).getByLabelText(/New approver/)
    await waitFor(() => expect(picker).toBeEnabled())
    const list = (await import('@/test/combobox')).openCombobox(picker)
    await within(list).findByRole('option', { name: /Brian Kiprop/ })
    // Neither the requester nor the approver being replaced is offered.
    expect(within(list).queryByRole('option', { name: /Grace Wanjiru/ })).not.toBeInTheDocument()
    expect(within(list).queryByRole('option', { name: /Amina Otieno/ })).not.toBeInTheDocument()
    fireEvent.click(within(list).getByRole('option', { name: /Brian Kiprop/ }))
    fireEvent.click(within(form).getByRole('button', { name: 'Reassign' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/reassign', { from_user_id: 'u-1', to_user_id: 'u-5' }))
  })

  it('offers only the actions the API allows', async () => {
    setup()
    renderApp('/approvals/d')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    await within(pane).findByText('Nobody can approve this step.')
    for (const name of ['Approve', 'Reject', 'Return for changes', 'Request more information', 'Reassign']) {
      expect(within(pane).queryByRole('button', { name })).not.toBeInTheDocument()
    }
    expect(within(pane).queryByRole('button', { name: 'Attach a file' })).not.toBeInTheDocument()
    // Commenting stays open on a pending request.
    expect(within(pane).getByRole('button', { name: 'Post comment' })).toBeInTheDocument()
  })

  it('posts a comment', async () => {
    setup()
    api.post.mockResolvedValue({ data: approvalDetail(WAITING) })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    fireEvent.change(await within(pane).findByRole('textbox', { name: 'Add a comment' }), { target: { value: 'Checked the quote' } })
    fireEvent.click(within(pane).getByRole('button', { name: 'Post comment' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('approvals/a/comment', { comment: 'Checked the quote' }))
  })

  it('uploads an attachment with progress', async () => {
    setup()
    api.upload.mockImplementation(async (path, form, { onProgress }) => {
      onProgress(0.5)
      return { data: approvalDetail(WAITING) }
    })
    renderApp('/approvals/a')
    const pane = await screen.findByRole('article', { name: 'Request detail' })
    const file = new File(['%PDF'], 'invoice.pdf', { type: 'application/pdf' })
    fireEvent.change(await within(pane).findByLabelText('Choose a file to attach'), { target: { files: [file] } })
    await waitFor(() => expect(api.upload).toHaveBeenCalledWith('approvals/a/attachments', expect.any(FormData), expect.objectContaining({ onProgress: expect.any(Function) })))
    expect(api.upload.mock.calls[0][1].get('file')).toBe(file)
    expect(await screen.findByText('Attached invoice.pdf')).toBeInTheDocument()
  })
})
