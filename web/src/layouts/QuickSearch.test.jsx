import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import i18n from '@/i18n'
import { mockApi, OWNER, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const TOPICS = {
  data: [
    { id: 'core.getting-started', title: 'Getting started', module: 'core', module_label: 'Core' },
    { id: 'inventory.stock', title: 'Counting stock', module: 'inventory', module_label: 'Inventory' },
  ],
}

const PERMISSIONS = tenantWide(['core.company.view', 'core.item.view', 'core.item.create', 'inventory.stock.view', 'core.user.view', 'core.user.invite', 'hr.employee.view', 'hr.employee.create'])

function setup({ permissions = PERMISSIONS, modules = ['core', 'inventory'], path = '/', user = OWNER } = {}) {
  mockApi(api, { user, permissions, modules, extra: { 'manual/topics?locale=en': TOPICS, 'manual/topics?locale=fr': TOPICS } })
  return renderApp(path)
}

const pressCtrlK = () => fireEvent.keyDown(document.body, { key: 'k', ctrlKey: true })
const field = () => screen.findByRole('combobox', { name: 'Search pages, actions and help' })
const typeQuery = async (text) => fireEvent.change(await field(), { target: { value: text } })

async function openPalette() {
  await screen.findAllByRole('navigation', { name: 'Main' })
  await waitFor(() => expect(screen.getAllByRole('link', { name: 'Stock on hand' }).length).toBeGreaterThan(0))
  pressCtrlK()
  return screen.findByRole('dialog', { name: 'Search' })
}

describe('quick search (LAY-01, RBAC-08, L10N-01)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('opens with Ctrl+K and with the slash key outside a field', async () => {
    setup()
    await openPalette()
    expect(await field()).toBeInTheDocument()
    fireEvent.keyDown(document.body, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Search' })).not.toBeInTheDocument())
    fireEvent.keyDown(document.body, { key: '/' })
    expect(await screen.findByRole('dialog', { name: 'Search' })).toBeInTheDocument()
  })

  it('does not open on the slash key while typing in a field', async () => {
    setup()
    await openPalette()
    fireEvent.keyDown(document.body, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Search' })).not.toBeInTheDocument())
    const input = document.createElement('input')
    document.body.appendChild(input)
    fireEvent.keyDown(input, { key: '/' })
    expect(screen.queryByRole('dialog', { name: 'Search' })).not.toBeInTheDocument()
    input.remove()
  })

  it('filters pages as the user types and shows the group', async () => {
    setup()
    await openPalette()
    await typeQuery('stock')
    const options = await screen.findAllByRole('option')
    expect(screen.getByRole('option', { name: /Inventory › Stock on hand/ })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Roles/ })).not.toBeInTheDocument()
    expect(options.length).toBeGreaterThan(0)
    await typeQuery('zzzzqq')
    expect(await screen.findByText(/No matches/)).toBeInTheDocument()
  })

  it('never offers pages or actions the user lacks permission or a module for', async () => {
    setup()
    await openPalette()
    await typeQuery('payroll')
    expect(screen.queryByRole('option', { name: /Payroll/ })).not.toBeInTheDocument()
    await typeQuery('purchase order')
    expect(screen.queryByRole('option', { name: /purchase order/i })).not.toBeInTheDocument()
    // Has the module and the view permission, but not the create permission.
    await typeQuery('new employee')
    expect(screen.queryByRole('option', { name: /New employee/ })).not.toBeInTheDocument()
    await typeQuery('roles')
    expect(screen.queryByRole('option', { name: /Roles/ })).not.toBeInTheDocument()
  })

  it('offers an action only with its permission and module', async () => {
    setup({ permissions: tenantWide([...PERMISSIONS.map((p) => p.name), 'hr.employee.create']), modules: ['core', 'inventory'] })
    await openPalette()
    await typeQuery('add item')
    expect(await screen.findByRole('option', { name: /Add item/ })).toBeInTheDocument()
    await typeQuery('invite')
    expect(await screen.findByRole('option', { name: /Invite user/ })).toBeInTheDocument()
    // The permission is there but the HR module is not active.
    await typeQuery('employee')
    expect(screen.queryByRole('option', { name: /New employee/ })).not.toBeInTheDocument()
  })

  it('opens the highlighted result with Enter', async () => {
    const { router } = setup()
    await openPalette()
    await typeQuery('stock on hand')
    await screen.findByRole('option', { name: /Stock on hand/ })
    fireEvent.keyDown(await field(), { key: 'Enter' })
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/stock'))
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Search' })).not.toBeInTheDocument())
  })

  it('moves with the arrow keys', async () => {
    const { router } = setup()
    await openPalette()
    await typeQuery('add')
    await screen.findAllByRole('option')
    const input = await field()
    fireEvent.keyDown(input, { key: 'ArrowDown' })
    const selected = screen.getAllByRole('option').find((option) => option.getAttribute('aria-selected') === 'true')
    expect(selected).toBeDefined()
    fireEvent.keyDown(input, { key: 'Enter' })
    await waitFor(() => expect(router.state.location.pathname).not.toBe('/'))
  })

  it('lists help topics, fetched when the palette first opens, and opens one', async () => {
    const { router } = setup()
    await screen.findAllByRole('navigation', { name: 'Main' })
    expect(api.get).not.toHaveBeenCalledWith('manual/topics?locale=en')
    await openPalette()
    await typeQuery('counting')
    const option = await screen.findByRole('option', { name: /Inventory › Counting stock/ })
    expect(api.get).toHaveBeenCalledWith('manual/topics?locale=en')
    fireEvent.click(option)
    await waitFor(() => expect(router.state.location.pathname + router.state.location.search).toBe('/help?topic=inventory.stock'))
  })

  it('finds pages by French label and synonym, ignoring accents', async () => {
    setup({ user: { ...OWNER, locale: 'fr' } })
    await screen.findAllByRole('navigation', { name: 'Principal' })
    await waitFor(() => expect(screen.getAllByRole('link', { name: /Stock disponible/ }).length).toBeGreaterThan(0))
    pressCtrlK()
    fireEvent.change(await screen.findByRole('combobox', { name: /Rechercher des pages/ }), { target: { value: 'inventaire' } })
    expect(await screen.findByRole('option', { name: /Stock disponible/ })).toBeInTheDocument()
    fireEvent.change(screen.getByRole('combobox', { name: /Rechercher des pages/ }), { target: { value: 'ajouter un article' } })
    expect(await screen.findByRole('option', { name: /Ajouter un article/ })).toBeInTheDocument()
    fireEvent.change(screen.getByRole('combobox', { name: /Rechercher des pages/ }), { target: { value: 'utilisateurs' } })
    expect(await screen.findByRole('option', { name: /Utilisateurs/ })).toBeInTheDocument()
  })

  it('shows the last visited destinations when the query is empty', async () => {
    window.localStorage.setItem('qs.recent.u-1', JSON.stringify([{ to: '/inventory/stock' }, { to: '/payroll/runs' }]))
    setup()
    await openPalette()
    expect(await screen.findByText('Recent')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /Stock on hand/ })).toBeInTheDocument()
    // A destination the user can no longer open is not offered.
    expect(screen.queryByRole('option', { name: /Payroll/ })).not.toBeInTheDocument()
  })

  it('remembers a visited page for next time', async () => {
    const { router } = setup()
    await openPalette()
    await typeQuery('stock on hand')
    fireEvent.keyDown(await field(), { key: 'Enter' })
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/stock'))
    await waitFor(() => expect(JSON.parse(window.localStorage.getItem('qs.recent.u-1'))[0].to).toBe('/inventory/stock'))
  })

  it('opens from the search button in the phone top bar', async () => {
    setup()
    await screen.findAllByRole('navigation', { name: 'Main' })
    fireEvent.click(await screen.findByRole('button', { name: 'Open search' }))
    const dialog = await screen.findByRole('dialog', { name: 'Search' })
    expect(within(dialog).getByRole('combobox', { name: 'Search pages, actions and help' })).toBeInTheDocument()
  })

  it('opens from the Search box at the top of the sidebar', async () => {
    setup()
    const nav = (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
    const box = within(nav.parentElement).getByRole('button', { name: /^Search/ })
    await act(async () => fireEvent.click(box))
    expect(await screen.findByRole('dialog', { name: 'Search' })).toBeInTheDocument()
  })
})
