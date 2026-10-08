import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const TOKEN = 'a'.repeat(48)
const PATH = `approvals/email/${TOKEN}`

const SUMMARY = {
  id: 'apr-1',
  document_type_label: 'Purchase requisition',
  document_number: 'PR-NBO-00231',
  document_title: 'Cooking oil restock, 40 cartons',
  amount: { amount_minor: '11845000', currency: 'KES' },
  step: 'Branch manager approves',
  requester: 'Grace Wanjiru',
  require_reason: false,
}

const link = (action, overrides = {}) => ({
  data: { status: 'confirm', reason: null, message: null, action, approval_id: 'apr-1', approval: SUMMARY, ...overrides },
})

function setup(answer) {
  mockRoutes(api, [[PATH, answer]])
}

describe('approve by email (APR-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
  })

  it('shows the request signed out and decides only when the user confirms', async () => {
    setup(link('approve'))
    renderApp(`/approvals/email/${TOKEN}`)
    expect(await screen.findByRole('heading', { name: 'Approve this request' })).toBeInTheDocument()
    expect(screen.getByText('Cooking oil restock, 40 cartons')).toBeInTheDocument()
    expect(screen.getByText('Purchase requisition · PR-NBO-00231')).toBeInTheDocument()
    expect(screen.getByText('Requested by Grace Wanjiru · Branch manager approves')).toBeInTheDocument()
    expect(document.body).toHaveTextContent('KES 118,450.00')
    // Opening the page only reads the link.
    expect(api.get).toHaveBeenCalledWith(PATH)
    expect(api.post).not.toHaveBeenCalled()

    api.post.mockResolvedValue({ data: { status: 'done', approval_id: 'apr-1', approval_status: 'approved' } })
    const approve = screen.getByRole('button', { name: 'Approve' })
    expect(approve).toHaveAttribute('data-ds-variant', 'pay')
    fireEvent.click(approve)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(PATH, {}))
    expect(await screen.findByRole('heading', { name: 'Request approved' })).toBeInTheDocument()
    expect(screen.getByText('Your decision is recorded. You can close this page.')).toBeInTheDocument()
  })

  it('needs a reason to reject', async () => {
    setup(link('reject'))
    renderApp(`/approvals/email/${TOKEN}`)
    fireEvent.click(await screen.findByRole('button', { name: 'Reject' }))
    expect(await screen.findByText('Give a reason for this decision.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    api.post.mockResolvedValue({ data: { status: 'done', approval_id: 'apr-1', approval_status: 'rejected' } })
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Over budget' } })
    fireEvent.click(screen.getByRole('button', { name: 'Reject' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith(PATH, { comment: 'Over budget' }))
    expect(await screen.findByRole('heading', { name: 'Request rejected' })).toBeInTheDocument()
  })

  it('asks the user to sign in when the link can no longer be used, then opens the request', async () => {
    setup(link('approve', { status: 'sign_in_required', reason: 'expired', message: 'This link has expired. Sign in to decide.', approval: null }))
    const { router } = renderApp(`/approvals/email/${TOKEN}`)
    expect(await screen.findByText('This link has expired. Sign in to decide.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Sign in to decide' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    expect(router.state.location.search).toBe(`?next=${encodeURIComponent('/approvals/apr-1')}`)
    expect(api.post).not.toHaveBeenCalled()
  })

  it('switches to sign-in when confirming is refused', async () => {
    setup(link('approve'))
    const refusal = apiError(403, 'sign_in_required', 'This link was already used. Sign in to see the approval.')
    refusal.data = { reason: 'used', approval_id: 'apr-1' }
    api.post.mockRejectedValue(refusal)
    renderApp(`/approvals/email/${TOKEN}`)
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }))
    expect(await screen.findByText('This link was already used. Sign in to see the approval.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign in to decide' })).toBeInTheDocument()
  })

  it('opens the request directly for a signed-in user', async () => {
    signedIn()
    mockRoutes(api, [
      [PATH, link('approve', { status: 'sign_in_required', reason: 'two_factor', message: 'Your role requires two-step sign-in. Sign in to decide.', approval: null })],
      [/^approvals/, { data: [], meta: { total: 0 } }],
      ['me/delegations', { data: [] }],
    ])
    const { router } = renderApp(`/approvals/email/${TOKEN}`)
    fireEvent.click(await screen.findByRole('button', { name: 'Open the request' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/approvals/apr-1'))
  })
})
