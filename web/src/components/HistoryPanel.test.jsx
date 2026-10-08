import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { api } from '@/api/client'
import i18n from '@/i18n'
import { changedFields } from '@/lib/history'
import { HistoryPanel } from './HistoryPanel'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

function renderPanel(props) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <HistoryPanel type="party" recordId="p-1" {...props} />
    </QueryClientProvider>,
  )
}

const ENTRIES = [
  {
    id: 'a-3',
    action: 'core.party.update',
    actor: { id: 'u-1', name: 'Amina Otieno' },
    before: { name: 'Kin Traders', credit_limit_minor: 500000, credit_limit_currency: 'CDF', updated_at: '2026-10-07T10:00:00Z' },
    after: { name: 'Kin Traders SARL', credit_limit_minor: 750000, credit_limit_currency: 'CDF', updated_at: '2026-10-08T09:05:00Z' },
    occurred_at: '2026-10-08T09:05:00Z',
  },
  { id: 'a-2', action: 'core.party.archive', actor: null, before: { archived_at: null }, after: { archived_at: '2026-10-07T11:00:00Z' }, occurred_at: '2026-10-07T11:00:00Z' },
  { id: 'a-1', action: 'core.party.reconcile_magic', actor: { id: 'u-1', name: 'Amina Otieno' }, before: { active: true }, after: { active: false, opened_on: '2026-10-01', mystery: 'blue' }, occurred_at: '2026-10-01T08:00:00Z' },
]

describe('HistoryPanel', () => {
  beforeEach(() => vi.resetAllMocks())
  afterEach(async () => {
    await act(() => i18n.changeLanguage('en'))
  })

  it('shows who, what, when (company time zone) and the changed fields formatted', async () => {
    api.get.mockResolvedValue({ data: ENTRIES, meta: { current_page: 1, last_page: 1 } })
    renderPanel({ timeZone: 'Africa/Kinshasa' })

    const list = await screen.findByRole('list', { name: 'History' })
    const [update, archive, unknown] = within(list).getAllByRole('listitem')
    expect(api.get).toHaveBeenCalledWith('history/party/p-1?per_page=20&page=1')

    expect(within(update).getByText('Updated')).toBeInTheDocument()
    expect(within(update).getByText('Amina Otieno')).toBeInTheDocument()
    // 09:05 UTC is 10:05 in Kinshasa.
    expect(within(update).getByText('8 Oct 2026, 10:05')).toBeInTheDocument()
    expect(within(update).getByText('Name')).toBeInTheDocument()
    expect(within(update).getByText('Kin Traders SARL')).toBeInTheDocument()
    // Money in minor units with its currency first; the currency is not a separate row; bookkeeping is hidden.
    expect(within(update).getByText('CDF 500,000')).toBeInTheDocument()
    expect(within(update).getByText('CDF 750,000')).toBeInTheDocument()
    expect(within(update).queryByText('Credit limit currency')).not.toBeInTheDocument()
    expect(within(update).queryByText(/Updated at/i)).not.toBeInTheDocument()

    expect(within(archive).getByText('Archived')).toBeInTheDocument()
    expect(within(archive).getByText('System')).toBeInTheDocument()

    // An action the web does not know reads "Changed"; unknown fields read as text.
    expect(within(unknown).getByText('Changed')).toBeInTheDocument()
    expect(within(unknown).getByText('Yes')).toBeInTheDocument()
    expect(within(unknown).getByText('No')).toBeInTheDocument()
    expect(within(unknown).getByText('1 Oct 2026')).toBeInTheDocument()
    expect(within(unknown).getByText('Mystery')).toBeInTheDocument()
    expect(within(unknown).getByText('blue')).toBeInTheDocument()
  })

  it('reads in French and loads older changes a page at a time', async () => {
    await act(() => i18n.changeLanguage('fr'))
    api.get
      .mockResolvedValueOnce({ data: [ENTRIES[0]], meta: { current_page: 1, last_page: 2 } })
      .mockResolvedValueOnce({ data: [ENTRIES[1]], meta: { current_page: 2, last_page: 2 } })
    renderPanel({ timeZone: 'Africa/Kinshasa' })

    expect(await screen.findByText('Modifié')).toBeInTheDocument()
    expect(screen.getByText('Plafond de crédit')).toBeInTheDocument()
    expect(screen.getByText(/^CDF 750\s000$/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Voir les modifications plus anciennes' }))
    expect(await screen.findByText('Archivé')).toBeInTheDocument()
    expect(screen.getByText('Système')).toBeInTheDocument()
    expect(api.get).toHaveBeenLastCalledWith('history/party/p-1?per_page=20&page=2')
    expect(screen.queryByRole('button', { name: 'Voir les modifications plus anciennes' })).not.toBeInTheDocument()
  })

  it('uses a page’s own labels and formats, and says when nothing is recorded', async () => {
    api.get.mockResolvedValueOnce({
      data: [{ id: 'a-9', action: 'core.item.units_update', actor: null, before: { uoms: [] }, after: { uoms: [{ uom_id: 'u-box', factor: '12' }] }, occurred_at: '2026-10-08T09:00:00Z' }],
      meta: { current_page: 1, last_page: 1 },
    })
    renderPanel({ fields: { uoms: { label: 'Units', format: (list) => list.map((uom) => `BOX × ${uom.factor}`).join(', ') || 'None' } } })
    expect(await screen.findByText('Units changed')).toBeInTheDocument()
    expect(screen.getByText('BOX × 12')).toBeInTheDocument()
  })

  it('lists only fields that changed', () => {
    expect(changedFields({ a: 1, b: 2, updated_at: 'x' }, { a: 1, b: 3, updated_at: 'y' })).toEqual(['b'])
    // A new record lists only the values it was given.
    expect(changedFields(null, { name: 'New', id: 'x', company_id: null, custom: {}, tags: [] })).toEqual(['name'])
  })
})
