import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { screenMatches } from '@/lib/helpScreens'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

// RBAC-08, L10N-01: the manual in the app.
const TOPICS = [
  { id: 'core-choose-appearance', title: 'Choose how the app looks', module: 'core', module_label: 'Account and administration', screens: ['/settings/appearance'], excerpt: 'Pick a theme.' },
  { id: 'rbac-create-role', title: 'Create a role and set its limits', module: 'core', module_label: 'Account and administration', screens: ['/settings/roles', '/settings/roles/:roleId'], excerpt: 'Roles group permissions.' },
  { id: 'pos-sell', title: 'Sell items', module: 'pos', module_label: 'Point of sale', screens: ['pos:SellScreen'], excerpt: 'Build the sale, then charge it.' },
]

const TOPIC_PATH = 'manual/topics?locale=en'

function setup(path = '/help') {
  mockApi(api, {
    extra: {
      [TOPIC_PATH]: { data: TOPICS },
      'manual/topics/pos-sell?locale=en': { data: { ...TOPICS[2], html: '<ol><li>Tap a product.</li></ol>' } },
    },
  })
  return renderApp(path)
}

describe('Help', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the topics grouped by module', async () => {
    setup()
    const nav = await screen.findByRole('navigation', { name: 'Help topics' })
    const core = await within(nav).findByRole('region', { name: 'Account and administration' })
    expect(within(core).getByText('Choose how the app looks')).toBeInTheDocument()
    expect(within(core).getByText('Create a role and set its limits')).toBeInTheDocument()
    const pos = within(nav).getByRole('region', { name: 'Point of sale' })
    expect(within(pos).getByText('Sell items')).toBeInTheDocument()
    expect(screen.getByText('Choose a topic to read it here.')).toBeInTheDocument()
  })

  it('filters topics by title', async () => {
    setup()
    const nav = await screen.findByRole('navigation', { name: 'Help topics' })
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search topics' }), { target: { value: 'sell' } })
    expect(within(nav).getByText('Sell items')).toBeInTheDocument()
    expect(within(nav).queryByText('Choose how the app looks')).not.toBeInTheDocument()
    expect(within(nav).queryByRole('region', { name: 'Account and administration' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search topics' }), { target: { value: 'zzz' } })
    expect(within(nav).getByText('No topics match "zzz". Try a different word.')).toBeInTheDocument()
  })

  it('opens a topic and shows its text', async () => {
    setup()
    fireEvent.click(await screen.findByRole('button', { name: /Sell items/ }))
    const article = await screen.findByRole('article', { name: 'Sell items' })
    expect(within(article).getByText('Tap a product.')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('manual/topics/pos-sell?locale=en')
  })

  it('downloads the guide of the topics the user can see', async () => {
    setup()
    const blob = new Blob(['%PDF-1.7'], { type: 'application/pdf' })
    api.download.mockResolvedValue({ blob, filename: 'guide-en.pdf' })
    URL.createObjectURL = vi.fn(() => 'blob:guide')
    URL.revokeObjectURL = vi.fn()

    fireEvent.click(await screen.findByRole('button', { name: 'Download my guide (PDF)' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('manual/my-guide.pdf?locale=en'))
    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalledWith(blob))
  })

  it('says what went wrong when the guide cannot be downloaded', async () => {
    setup()
    api.download.mockRejectedValue(new Error('boom'))
    fireEvent.click(await screen.findByRole('button', { name: 'Download my guide (PDF)' }))
    expect(await screen.findByText('The guide could not be downloaded. Try again in a minute.')).toBeInTheDocument()
  })

  it('narrows the list to the topics written for the page it was opened from', async () => {
    setup('/help?screen=%2Fsettings%2Froles%2Fr-1')
    const nav = await screen.findByRole('navigation', { name: 'Help topics' })
    await within(nav).findByText('Create a role and set its limits')
    expect(within(nav).queryByText('Sell items')).not.toBeInTheDocument()
    expect(screen.getByText('Showing the topics for this page.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Show all topics' }))
    expect(await within(nav).findByText('Sell items')).toBeInTheDocument()
  })

  it('shows every topic when none is written for the page', async () => {
    setup('/help?screen=%2Fsettings%2Fsessions')
    const nav = await screen.findByRole('navigation', { name: 'Help topics' })
    expect(await within(nav).findByText('Sell items')).toBeInTheDocument()
    expect(screen.getByText('No topic is written for this page yet. Showing all topics.')).toBeInTheDocument()
  })

  it('has a Help entry in the sidebar', async () => {
    setup()
    const sidebar = await screen.findByRole('navigation', { name: 'Main' })
    expect(await within(sidebar).findByRole('link', { name: 'Help' })).toHaveAttribute('href', '/help')
  })

  it('puts a help link in the header of other pages that opens the help for that page', async () => {
    mockApi(api, { extra: { 'auth/sessions?per_page=25&page=1': { data: [], meta: { last_page: 1, total: 0, from: 0, to: 0 } } } })
    renderApp('/settings/sessions')
    const link = await screen.findByRole('link', { name: 'Help for this page' })
    expect(link).toHaveAttribute('href', '/help?screen=%2Fsettings%2Fsessions')
  })
})

describe('screenMatches', () => {
  it('matches routes with parameters and ignores POS screens', () => {
    expect(screenMatches('/settings/roles/:roleId', '/settings/roles/abc')).toBe(true)
    expect(screenMatches('/settings/roles', '/settings/roles/abc')).toBe(false)
    expect(screenMatches('pos:SellScreen', '/settings/roles')).toBe(false)
  })
})
