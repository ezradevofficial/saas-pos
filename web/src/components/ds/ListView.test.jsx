import { QueryClient } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/App'
import { api } from '@/api/client'
import { setLocale } from '@/i18n'
import { actionsColumn } from '@/lib/listColumns'
import { columnsStorageKey, useServerList } from '@/lib/useServerList'
import { chooseOption } from '@/test/combobox'
import { closeFilters, filtersButton, openFilters } from '@/test/filters'
import { apiError, OWNER, resetSession, signedIn } from '@/test/renderApp'
import { ListView } from './ListView'
import { Select } from './Select'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COLUMNS = [
  { key: 'name', label: 'Name', sortKey: 'name', hideable: false },
  { key: 'code', label: 'Code', sortKey: 'code' },
  { key: 'phone', label: 'Phone', exportKey: 'phones' },
  { key: 'notes', label: 'Notes', exportKey: null },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'archived', label: 'Archived' },
]

const FIELDS = [
  { name: 'status', label: 'Status', options: STATUS_OPTIONS },
  {
    name: 'type',
    label: 'Type',
    options: [
      { value: '', label: 'All types' },
      { value: 'service', label: 'Service' },
      { value: 'stock', label: 'Stock' },
    ],
  },
]

function Things({ columns = COLUMNS, searchable = true, legacy = false }) {
  const list = useServerList({ id: 'things', endpoint: 'things', filters: { status: 'active', type: '' }, columns })
  return (
    <ListView
      list={list}
      title="Things"
      searchable={searchable}
      searchPlaceholder="Name or code"
      filterFields={legacy ? undefined : FIELDS}
      filters={
        legacy ? (
          <Select label="Status" options={STATUS_OPTIONS} value={list.filters.status} onChange={(event) => list.setFilter('status', event.target.value)} />
        ) : undefined
      }
    />
  )
}

/** A Laravel page of `total` rows for the asked page and size. */
function answer(path, total) {
  const params = new URLSearchParams(path.split('?')[1])
  const perPage = Number(params.get('per_page'))
  const page = Number(params.get('page'))
  const from = total ? (page - 1) * perPage + 1 : null
  const to = total ? Math.min(total, page * perPage) : null
  const data = total ? Array.from({ length: to - from + 1 }, (_, index) => ({ id: `t-${from + index}`, name: `Thing ${from + index}`, code: `T${from + index}` })) : []
  return { data, meta: { total, from, to, current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)) } }
}

function mockList(total = 312) {
  api.get.mockImplementation(async (path) => {
    if (path === 'me') return { data: OWNER }
    if (path === 'me/permissions') return { permissions: [], modules: ['core'] }
    if (path.startsWith('things?')) return answer(path, total)
    throw apiError(404, 'not_found', 'Not found.')
  })
}

function renderList(path = '/things', props = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const router = createMemoryRouter([{ path: '/things', element: <Things {...props} /> }], { initialEntries: [path] })
  const utils = render(
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>,
  )
  return { ...utils, router }
}

const listCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => path.startsWith('things?'))
const header = (name) => screen.getByRole('columnheader', { name: new RegExp(`^${name}`) })
const openMenu = (name) => fireEvent.pointerDown(screen.getByRole('button', { name }), { button: 0, ctrlKey: false })

describe('ListView and useServerList', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })
  afterEach(() => setLocale('en'))

  it('keeps search, filters and page in the URL and goes back to page 1 when they change', async () => {
    mockList()
    const { router } = renderList('/things?page=3')
    await screen.findByText('Thing 51')
    expect(listCalls()[0]).toBe('things?status=active&per_page=25&page=3')

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'ab' } })
    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'abc ' } })
    await waitFor(() => expect(router.state.location.search).toBe('?search=abc'))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&search=abc&per_page=25&page=1'))
    expect(listCalls().some((path) => path.includes('search=ab&'))).toBe(false)
    // Typing replaces the history entry instead of adding one.
    expect(router.state.historyAction).toBe('REPLACE')
    expect(screen.getByLabelText('Search')).toHaveValue('abc ')

    openFilters()
    chooseOption('Status', 'Archived')
    await waitFor(() => expect(router.state.location.search).toBe('?search=abc&status=archived'))
    expect(router.state.historyAction).toBe('PUSH')
    await closeFilters()

    // A shared link or Back puts the box in step with the URL.
    await act(() => router.navigate('/things?search=zed'))
    await waitFor(() => expect(screen.getByLabelText('Search')).toHaveValue('zed'))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&search=zed&per_page=25&page=1'))
  })

  it('sorts from a header: ascending, descending, then the default order, with aria-sort', async () => {
    mockList()
    const { router } = renderList()
    await screen.findByText('Thing 1')
    expect(header('Name')).toHaveAttribute('aria-sort', 'none')
    expect(header('Phone')).not.toHaveAttribute('aria-sort')
    expect(within(header('Phone')).queryByRole('button')).not.toBeInTheDocument()

    fireEvent.click(within(header('Name')).getByRole('button', { name: 'Name' }))
    await waitFor(() => expect(header('Name')).toHaveAttribute('aria-sort', 'ascending'))
    expect(listCalls().at(-1)).toBe('things?status=active&sort=name&per_page=25&page=1')

    fireEvent.click(within(header('Name')).getByRole('button', { name: 'Name' }))
    await waitFor(() => expect(header('Name')).toHaveAttribute('aria-sort', 'descending'))
    expect(router.state.location.search).toBe('?sort=-name')

    fireEvent.click(within(header('Name')).getByRole('button', { name: 'Name' }))
    await waitFor(() => expect(header('Name')).toHaveAttribute('aria-sort', 'none'))
    expect(router.state.location.search).toBe('')
  })

  it('falls back to the default order and says why when the API refuses a sort (RBAC-05)', async () => {
    const reason = 'You can’t sort by a field you can’t see. Choose another column.'
    api.get.mockImplementation(async (path) => {
      if (path === 'me') return { data: OWNER }
      if (path === 'me/permissions') return { permissions: [], modules: ['core'] }
      if (path.includes('sort=code')) throw apiError(422, 'validation_failed', reason, { sort: [reason] })
      if (path.startsWith('things?')) return answer(path, 3)
      throw apiError(404, 'not_found', 'Not found.')
    })
    const { router } = renderList('/things?sort=code')
    await waitFor(() => expect(router.state.location.search).toBe(''))
    // Said once, however many renders ran before the URL changed.
    await waitFor(() => expect(screen.getAllByText(reason)).toHaveLength(1))
    expect(await screen.findByText('Thing 1')).toBeInTheDocument()
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&per_page=25&page=1'))
    expect(listCalls().filter((path) => path.includes('sort='))).toEqual(['things?status=active&sort=code&per_page=25&page=1'])
  })

  it('asks for the chosen rows per page from page 1', async () => {
    mockList()
    const { router } = renderList('/things?page=3')
    await screen.findByText('Thing 51')
    chooseOption('Rows per page', '50')
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&per_page=50&page=1'))
    expect(router.state.location.search).toBe('?per_page=50')
    expect(await screen.findByText('Showing 1–50 of 312')).toBeInTheDocument()
  })

  it('pages with first, previous, next and last, disabled at the ends', async () => {
    mockList()
    renderList()
    await screen.findByText('Thing 1')
    const pages = screen.getByRole('navigation', { name: 'Pages of results' })
    expect(within(pages).getByRole('button', { name: 'First page' })).toBeDisabled()
    expect(within(pages).getByRole('button', { name: 'Previous page' })).toBeDisabled()
    expect(within(pages).getByText('Page 1 of 13')).toBeInTheDocument()

    fireEvent.click(within(pages).getByRole('button', { name: 'Next page' }))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&per_page=25&page=2'))
    fireEvent.click(await within(pages).findByRole('button', { name: 'Last page' }))
    await waitFor(() => expect(within(pages).getByText('Page 13 of 13')).toBeInTheDocument())
    expect(listCalls().at(-1)).toBe('things?status=active&per_page=25&page=13')
    expect(within(pages).getByRole('button', { name: 'Next page' })).toBeDisabled()
    expect(within(pages).getByRole('button', { name: 'Last page' })).toBeDisabled()
    expect(await screen.findByText('Showing 301–312 of 312')).toBeInTheDocument()

    fireEvent.click(within(pages).getByRole('button', { name: 'Previous page' }))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&per_page=25&page=12'))
    fireEvent.click(within(pages).getByRole('button', { name: 'First page' }))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&per_page=25&page=1'))
  })

  it('counts records in English and French, and says when there are none', async () => {
    mockList(1312)
    renderList()
    expect(await screen.findByText('Showing 1–25 of 1,312')).toBeInTheDocument()
    await act(async () => {
      await setLocale('fr')
    })
    expect(await screen.findByText(/^Affichage de 1 à 25 sur 1\s312$/)).toBeInTheDocument()
    expect(screen.getByText(/^Page 1 sur 53$/)).toBeInTheDocument()
  })

  it('says "No records" for an empty list', async () => {
    mockList(0)
    renderList()
    expect(await screen.findByText('No records')).toBeInTheDocument()
    expect(screen.getByText('Nothing here yet.')).toBeInTheDocument()
  })

  it('hides a column, remembers it per user and list, and never hides a fixed column (LAY-04)', async () => {
    mockList()
    const first = renderList()
    await screen.findByText('Thing 1')
    openMenu('Columns')
    expect(await screen.findByRole('menuitemcheckbox', { name: 'Name' })).toHaveAttribute('data-disabled')
    fireEvent.click(screen.getByRole('menuitemcheckbox', { name: 'Code' }))
    // The open menu hides the page from the accessibility tree; headers are still checked.
    await waitFor(() => expect(screen.queryByRole('columnheader', { name: /^Code/, hidden: true })).not.toBeInTheDocument())
    // The menu stays open to switch several columns.
    expect(screen.getByRole('menuitemcheckbox', { name: 'Code' })).toHaveAttribute('aria-checked', 'false')
    expect(JSON.parse(window.localStorage.getItem(columnsStorageKey(OWNER.id, 'things')))).toEqual(['code'])
    first.unmount()

    renderList()
    await screen.findByText('Thing 1')
    expect(screen.queryByRole('columnheader', { name: /^Code/ })).not.toBeInTheDocument()
    expect(header('Phone')).toBeInTheDocument()
  })

  it('keeps the last visible column', async () => {
    mockList()
    renderList('/things', { columns: COLUMNS.slice(1, 3) })
    await screen.findByText('T1')
    openMenu('Columns')
    fireEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Code' }))
    // The open menu hides the page from the accessibility tree; headers are still checked.
    await waitFor(() => expect(screen.queryByRole('columnheader', { name: /^Code/, hidden: true })).not.toBeInTheDocument())
    expect(screen.getByRole('menuitemcheckbox', { name: 'Phone' })).toHaveAttribute('data-disabled')
    fireEvent.click(screen.getByRole('menuitemcheckbox', { name: 'Phone' }))
    expect(screen.getByRole('columnheader', { name: 'Phone', hidden: true })).toBeInTheDocument()
    expect(screen.getByRole('menuitemcheckbox', { name: 'Phone' })).toHaveAttribute('aria-checked', 'true')
  })

  it('exports the same query with the visible columns in order, then names the file (EXP-01)', async () => {
    mockList()
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'things-2026-10-08.csv' })
    URL.createObjectURL = vi.fn(() => 'blob:things')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderList('/things?search=abc&sort=-code&page=2')
    await screen.findByText('Thing 26')

    openMenu('Export')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledTimes(1))
    const [path] = api.download.mock.calls[0]
    expect(path.split('?')[0]).toBe('things')
    const params = new URLSearchParams(path.split('?')[1])
    expect(Object.fromEntries([...params].filter(([key]) => key !== 'columns[]'))).toEqual({ status: 'active', search: 'abc', sort: '-code', format: 'csv' })
    // Notes has no export key; Phone exports as "phones".
    expect(params.getAll('columns[]')).toEqual(['name', 'code', 'phones'])
    expect(await screen.findByText('Export downloaded.')).toBeInTheDocument()
    await waitFor(() => expect(click).toHaveBeenCalled())
    expect(click.mock.contexts[0].download).toBe('things-2026-10-08.csv')
    click.mockRestore()
  })

  it('shows the API’s reason when an export is refused, and its own words when exports come too often', async () => {
    mockList()
    api.download.mockRejectedValueOnce(apiError(422, 'http_error', 'Too many rows for a PDF. Narrow the filters or export to Excel.'))
    renderList()
    await screen.findByText('Thing 1')
    openMenu('Export')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'PDF' }))
    expect(await screen.findByText('Too many rows for a PDF. Narrow the filters or export to Excel.')).toBeInTheDocument()
    expect(new URLSearchParams(api.download.mock.calls[0][0].split('?')[1]).get('format')).toBe('pdf')

    api.download.mockRejectedValueOnce(apiError(429, 'http_error', 'Too Many Attempts.'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Export' })).toBeEnabled())
    openMenu('Export')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Excel (.xlsx)' }))
    expect(await screen.findByText('Too many exports in a short time. Wait a minute and try again.')).toBeInTheDocument()
  })

  it('keeps a row actions column on screen but out of the Columns menu and the export, and can drop the search box', async () => {
    mockList()
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'things.csv' })
    URL.createObjectURL = vi.fn(() => 'blob:things')
    URL.revokeObjectURL = vi.fn()
    const columns = [...COLUMNS.slice(0, 2), actionsColumn('Actions', (row) => <button type="button">Edit {row.name}</button>)]
    renderList('/things', { columns, searchable: false })
    await screen.findByRole('button', { name: 'Edit Thing 1' })
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Actions' })).toBeInTheDocument()
    openMenu('Columns')
    expect(await screen.findByRole('menuitemcheckbox', { name: 'Code' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitemcheckbox', { name: 'Actions' })).not.toBeInTheDocument()
    fireEvent.keyDown(document.activeElement, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument())
    openMenu('Export')
    fireEvent.click(await screen.findByRole('menuitem', { name: 'CSV' }))
    await waitFor(() => expect(api.download).toHaveBeenCalled())
    expect(new URLSearchParams(api.download.mock.calls[0][0].split('?')[1]).getAll('columns[]')).toEqual(['name', 'code'])
  })

  it('keeps the filters in a drawer, opened from the Filters button with its count (EXP-01)', async () => {
    mockList()
    const { router } = renderList()
    await screen.findByText('Thing 1')
    // Nothing of the filters shows on the page until the button is pressed.
    expect(screen.queryByLabelText('Type')).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    const button = filtersButton()
    expect(button).toHaveAccessibleName('Filters')
    expect(button).toHaveAttribute('aria-expanded', 'false')

    const drawer = openFilters()
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(button).toHaveAttribute('aria-controls', drawer.id)
    expect(within(drawer).getByRole('button', { name: 'Clear filters' })).toBeDisabled()
    expect(within(drawer).getByRole('button', { name: 'Show 312 results' })).toBeInTheDocument()

    chooseOption(within(drawer).getByLabelText('Type'), 'Service')
    await waitFor(() => expect(router.state.location.search).toBe('?type=service'))
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=active&type=service&per_page=25&page=1'))
    chooseOption(within(drawer).getByLabelText('Status'), 'Archived')
    await waitFor(() => expect(listCalls().at(-1)).toBe('things?status=archived&type=service&per_page=25&page=1'))
    expect(filtersButton()).toHaveAccessibleName('Filters, 2 active')

    fireEvent.click(await within(drawer).findByRole('button', { name: 'Show 312 results' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(filtersButton()).toHaveFocus()
  })

  it('shows active filters as chips that remove one filter or clear them all', async () => {
    mockList()
    const { router } = renderList('/things?type=service&status=archived')
    await screen.findByText('Thing 1')
    const chips = screen.getByRole('list', { name: 'Active filters' })
    expect(within(chips).getByText('Status: Archived')).toBeInTheDocument()
    expect(within(chips).getByText('Type: Service')).toBeInTheDocument()

    fireEvent.click(within(chips).getByRole('button', { name: 'Remove filter Type: Service' }))
    await waitFor(() => expect(router.state.location.search).toBe('?status=archived'))
    expect(screen.queryByText('Type: Service')).not.toBeInTheDocument()
    expect(filtersButton()).toHaveFocus()

    fireEvent.click(screen.getByRole('button', { name: 'Clear all' }))
    await waitFor(() => expect(router.state.location.search).toBe(''))
    expect(screen.queryByRole('list', { name: 'Active filters' })).not.toBeInTheDocument()
    expect(filtersButton()).toHaveAccessibleName('Filters')
  })

  it('clears every filter from the drawer in one step', async () => {
    mockList()
    const { router } = renderList('/things?type=stock&status=archived&search=abc')
    await screen.findByText('Thing 1')
    const drawer = openFilters()
    fireEvent.click(within(drawer).getByRole('button', { name: 'Clear filters' }))
    await waitFor(() => expect(router.state.location.search).toBe('?search=abc'))
    expect(router.state.historyAction).toBe('PUSH')
    expect(within(drawer).getByRole('button', { name: 'Clear filters' })).toBeDisabled()
  })

  it('closes the drawer on Escape and gives focus back to the Filters button', async () => {
    mockList()
    renderList()
    await screen.findByText('Thing 1')
    filtersButton().focus()
    openFilters()
    await closeFilters()
    expect(filtersButton()).toHaveFocus()
  })

  it('names the drawer and its buttons in French', async () => {
    mockList()
    renderList('/things?type=service')
    await screen.findByText('Thing 1')
    await act(async () => {
      await setLocale('fr')
    })
    expect(await screen.findByText('Type : Service')).toBeInTheDocument()
    const drawer = openFilters()
    expect(within(drawer).getByRole('button', { name: 'Effacer les filtres' })).toBeEnabled()
  })

  it('still draws an older filters slot inside the drawer, without chips', async () => {
    mockList()
    const { router } = renderList('/things', { legacy: true })
    await screen.findByText('Thing 1')
    expect(screen.queryByLabelText('Status')).not.toBeInTheDocument()
    const drawer = openFilters()
    chooseOption(within(drawer).getByLabelText('Status'), 'Archived')
    await waitFor(() => expect(router.state.location.search).toBe('?status=archived'))
    expect(screen.queryByRole('list', { name: 'Active filters', hidden: true })).not.toBeInTheDocument()
  })
})
