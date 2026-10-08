import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { page } from '@/test/approvals'
import { chooseOption, openCombobox } from '@/test/combobox'
import { ALL_CORE, mockRoutes, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { DOCUMENT_TYPE } from '@/test/workflows'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const CANDIDATES = [
  { id: 'u-5', name: 'Brian Kiprop' },
  { id: 'u-7', name: 'Chantal Ilunga' },
]

const GIVEN = {
  id: 'd-1',
  direction: 'given',
  from: { id: 'u-1', name: 'Amina Otieno' },
  to: { id: 'u-5', name: 'Brian Kiprop' },
  starts_on: '2026-10-08',
  ends_on: '2026-10-14',
  document_types: null,
  note: 'Annual leave',
  status: 'active',
}
const RECEIVED = {
  id: 'd-2',
  direction: 'received',
  from: { id: 'u-6', name: 'Jean Kabila' },
  to: { id: 'u-1', name: 'Amina Otieno' },
  starts_on: '2026-10-01',
  ends_on: '2026-10-14',
  document_types: ['procurement.requisition'],
  note: null,
  status: 'active',
}
const ENDED = { ...GIVEN, id: 'd-3', status: 'ended', starts_on: '2026-09-01', ends_on: '2026-09-05' }

function setup({ delegations = [GIVEN, RECEIVED, ENDED], permissions = ALL_CORE } = {}) {
  mockRoutes(
    api,
    [
      [/^approvals\?/, page([])],
      ['me/delegations', { data: delegations }],
      [
        /^me\/delegation-candidates/,
        (path) => {
          const term = new URLSearchParams(path.split('?')[1] ?? '').get('search') ?? ''
          return { data: CANDIDATES.filter((person) => person.name.toLowerCase().includes(term.toLowerCase())) }
        },
      ],
      ['approvals/document-types', { data: [{ key: DOCUMENT_TYPE.key, label: DOCUMENT_TYPE.label }, { key: 'hr.leave', label: 'Leave request' }] }],
    ],
    { permissions },
  )
}

const openDialog = async () => {
  fireEvent.click(await screen.findByRole('button', { name: 'Set up delegation' }))
  return screen.findByRole('dialog', { name: 'Delegation' })
}

describe('delegation (APR-06)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('says whose approvals the user also decides', async () => {
    setup()
    renderApp('/approvals')
    const notice = await screen.findByText('You are also approving for Jean Kabila until 14 Oct 2026')
    expect(notice.closest('[role="status"]')).toHaveTextContent('Their items show “Delegated from” and are logged as approved by you on their behalf.')
  })

  it('delegates every document type to a colleague for a period', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...GIVEN, id: 'd-9' } })
    renderApp('/approvals')
    const dialog = await openDialog()
    const picker = within(dialog).getByLabelText(/Delegate to/)
    await waitFor(() => expect(picker).toBeEnabled())
    chooseOption(picker, /Brian Kiprop/)
    fireEvent.change(within(dialog).getByLabelText(/From/), { target: { value: '2026-10-10' } })
    fireEvent.change(within(dialog).getByLabelText(/Until/), { target: { value: '2026-10-20' } })
    fireEvent.change(within(dialog).getByLabelText(/Note/), { target: { value: 'On leave' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delegate approvals' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('me/delegations', {
        to_user_id: 'u-5',
        starts_on: '2026-10-10',
        ends_on: '2026-10-20',
        document_types: null,
        note: 'On leave',
      }),
    )
    expect(await screen.findByText('Delegation saved')).toBeInTheDocument()
  })

  it('searches colleagues on the server as the user types', async () => {
    setup()
    renderApp('/approvals')
    const dialog = await openDialog()
    const picker = within(dialog).getByLabelText(/Delegate to/)
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('me/delegation-candidates'))
    const list = openCombobox(picker)
    fireEvent.change(document.querySelector('[cmdk-input]'), { target: { value: 'chan' } })
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('me/delegation-candidates?search=chan'))
    await waitFor(() => expect(within(list).getAllByRole('option').map((option) => option.textContent)).toEqual(['Chantal Ilunga']))
    fireEvent.click(within(list).getByRole('option', { name: 'Chantal Ilunga' }))
    // The chosen person stays shown once the search is cleared.
    expect(picker).toHaveTextContent('Chantal Ilunga')
  })

  it('delegates only the chosen document types and asks for at least one', async () => {
    setup()
    api.post.mockResolvedValue({ data: GIVEN })
    renderApp('/approvals')
    const dialog = await openDialog()
    const picker = within(dialog).getByLabelText(/Delegate to/)
    await waitFor(() => expect(picker).toBeEnabled())
    chooseOption(picker, /Brian Kiprop/)
    fireEvent.change(within(dialog).getByLabelText(/Until/), { target: { value: '2026-10-20' } })
    chooseOption(within(dialog).getByLabelText('Document types'), 'Only the types I choose')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delegate approvals' }))
    expect(await within(dialog).findByText('Choose at least one document type, or delegate all of them.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.click(await within(dialog).findByRole('checkbox', { name: 'Leave request' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delegate approvals' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('me/delegations', expect.objectContaining({ to_user_id: 'u-5', document_types: ['hr.leave'] })))
  })

  it('requires a person and an end date', async () => {
    setup()
    renderApp('/approvals')
    const dialog = await openDialog()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delegate approvals' }))
    expect(await within(dialog).findByText('Choose who decides for you.')).toBeInTheDocument()
    expect(within(dialog).getByText('Choose the last day.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('lists given and received delegations with their state and revokes a running one', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...GIVEN, status: 'revoked' } })
    renderApp('/approvals')
    const dialog = await openDialog()
    const list = within(dialog).getByRole('region', { name: 'Your delegations' })
    const given = (await within(list).findAllByText('You to Brian Kiprop'))[0].closest('li')
    expect(within(given).getByText('Active')).toBeInTheDocument()
    expect(given).toHaveTextContent('All document types')
    const received = within(list).getByText('Jean Kabila to you').closest('li')
    expect(received).toHaveTextContent('1 document type')
    // A received delegation and an ended one cannot be revoked here.
    expect(within(received).queryByRole('button', { name: /Revoke/ })).not.toBeInTheDocument()
    expect(within(list).getAllByRole('button', { name: 'Revoke the delegation to Brian Kiprop' })).toHaveLength(1)
    expect(within(list).getByText('Ended')).toBeInTheDocument()

    fireEvent.click(within(given).getByRole('button', { name: 'Revoke the delegation to Brian Kiprop' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('me/delegations/d-1/revoke'))
    expect(await screen.findByText('Delegation revoked')).toBeInTheDocument()
  })
})
