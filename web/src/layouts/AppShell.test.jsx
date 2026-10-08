import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api, getCompanyId, setCompanyId } from '@/api/client'
import i18n from '@/i18n'
import { mockApi, OWNER, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { NAV_GROUPS, visibleGroups } from './navigation'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const COMPANIES = [
  { id: 'c-1', name: 'Amani Retail' },
  { id: 'c-2', name: 'Amani Wholesale' },
]

async function mainNav() {
  // The desktop sidebar; the phone sheet renders the same content only when open.
  return (await screen.findAllByRole('navigation', { name: 'Main' }))[0]
}

describe('app shell navigation', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })
  afterEach(() => i18n.changeLanguage('en'))

  it('shows Dashboard and every settings page to an owner', async () => {
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const nav = await mainNav()
    for (const name of ['Dashboard', 'Organisation', 'Users', 'Roles', 'Appearance', 'Sessions']) {
      expect(await within(nav).findByRole('link', { name })).toBeInTheDocument()
    }
    expect(within(nav).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page')
  })

  it('shows the app name and the tenant name in the logo block', async () => {
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const sidebar = (await mainNav()).parentElement
    expect(await within(sidebar).findByText('Amani Retail Group')).toBeInTheDocument()
    expect(within(sidebar).getByText(import.meta.env.VITE_APP_NAME)).toBeInTheDocument()
  })

  it('replaces a stored company the user no longer sees, so the header matches the switcher', async () => {
    setCompanyId('c-gone')
    mockApi(api, {
      companies: [COMPANIES[0]],
      permissions: [{ name: 'core.company.view', scopes: [{ type: 'company', id: 'c-1' }] }],
    })
    renderApp('/')
    await waitFor(() => expect(getCompanyId()).toBe('c-1'))
  })

  it('clears a stored company for a tenant-wide user who sees all companies', async () => {
    setCompanyId('c-gone')
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const trigger = await within((await mainNav()).parentElement).findByRole('combobox', { name: 'Company' })
    await waitFor(() => expect(trigger).toHaveTextContent('All companies'))
    expect(getCompanyId()).toBeNull()
  })

  it('hides Users when core.user.view is missing', async () => {
    mockApi(api, {
      permissions: [
        { name: 'core.company.view', scopes: [{ type: 'location', id: 'l-1' }] },
        { name: 'core.role.view', scopes: [{ type: 'location', id: 'l-1' }] },
      ],
    })
    renderApp('/')
    const nav = await mainNav()
    expect(await within(nav).findByRole('link', { name: 'Organisation' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Users' })).not.toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Sessions' })).toBeInTheDocument()
  })

  it('hides items whose module is not active', () => {
    const groups = visibleGroups(
      [{ id: 'g', label: () => 'G', items: [{ to: '/x', label: () => 'X', module: 'inventory' }] }, ...NAV_GROUPS],
      { can: () => true, hasModule: (m) => m === 'core' },
    )
    expect(groups.map((g) => g.id)).toEqual(['overview', 'catalogue', 'contacts', 'settings', 'finance', 'automation', 'masterData'])
  })

  it('shows Catalogue and Contacts only with their view permissions (MD-01, MD-02)', () => {
    const only = (names) => visibleGroups(NAV_GROUPS, { can: (name) => (Array.isArray(name) ? name : [name]).some((one) => names.includes(one)), hasModule: () => true })
    const labels = (groups, id) => groups.find((g) => g.id === id)?.items.map((item) => item.to) ?? []

    const cashier = only(['core.item.view', 'core.party.view'])
    expect(labels(cashier, 'catalogue')).toEqual(['/catalogue/items'])
    expect(labels(cashier, 'contacts')).toEqual(['/contacts/customers', '/contacts/suppliers', '/contacts/credit-limit-changes'])

    const stock = only(['core.item.view', 'core.item_category.view', 'core.uom.view'])
    expect(labels(stock, 'catalogue')).toEqual(['/catalogue/items', '/catalogue/categories', '/catalogue/units'])
    expect(stock.some((g) => g.id === 'contacts')).toBe(false)
  })

  it('hides pages that work on one company from a user with no company in reach (RBAC-04, RBAC-09)', () => {
    const can = (name) => (Array.isArray(name) ? name : [name]).some((one) => ['core.currency.view', 'core.exchange_rate.view', 'core.payment_method.view'].includes(one))
    const finance = (hasCompany) => visibleGroups(NAV_GROUPS, { can, hasModule: () => true, hasCompany }).find((g) => g.id === 'finance')?.items.map((item) => item.to)

    expect(finance(true)).toEqual(['/settings/currencies', '/settings/exchange-rates', '/settings/payment-methods'])
    expect(finance(false)).toEqual(['/settings/currencies'])
  })

  it('offers notification settings to everyone and the admin pages only with tenant-wide permissions (NOT-03, NOT-06, RBAC-09)', () => {
    const settings = (granted, wide = granted) =>
      visibleGroups(NAV_GROUPS, {
        can: (name) => (Array.isArray(name) ? name : [name]).some((one) => granted.includes(one)),
        tenantWide: (name) => wide.includes(name),
        hasModule: () => true,
      })
        .find((g) => g.id === 'settings')
        .items.map((item) => item.to)
        .filter((to) => to.includes('notification'))

    expect(settings([])).toEqual(['/settings/notifications'])
    expect(settings(['core.notification_template.view', 'core.notification_delivery.view'])).toEqual([
      '/settings/notifications',
      '/settings/notification-templates',
      '/settings/notification-deliveries',
    ])
    // Granted at one company only: the tenant's texts and log stay hidden.
    expect(settings(['core.notification_template.edit', 'core.notification_delivery.view'], [])).toEqual(['/settings/notifications'])
  })

  it('shows Notifications under Settings and the bell to every signed-in user', async () => {
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const nav = await mainNav()
    expect(await within(nav).findByRole('link', { name: 'Notifications' })).toHaveAttribute('href', '/settings/notifications')
    expect(within(nav).queryByRole('link', { name: 'Notification templates' })).not.toBeInTheDocument()
    expect((await screen.findAllByRole('button', { name: 'Notifications' })).length).toBeGreaterThan(0)
  })

  it('offers all companies to a tenant-wide user and remembers the choice', async () => {
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const nav = (await mainNav()).parentElement
    const trigger = await within(nav).findByRole('combobox', { name: 'Company' })
    await waitFor(() => expect(trigger).toHaveTextContent('All companies'))

    fireEvent.click(trigger)
    fireEvent.click(await screen.findByRole('option', { name: 'Amani Wholesale' }))

    await waitFor(() => expect(getCompanyId()).toBe('c-2'))
    expect(trigger).toHaveTextContent('Amani Wholesale')
  })

  it('searches companies in the switcher (BR-01)', async () => {
    mockApi(api, { companies: COMPANIES })
    renderApp('/')
    const trigger = await within((await mainNav()).parentElement).findByRole('combobox', { name: 'Company' })
    fireEvent.click(trigger)
    fireEvent.change(screen.getByPlaceholderText('Search'), { target: { value: 'whole' } })
    expect(within(screen.getByRole('listbox')).getAllByRole('option').map((option) => option.textContent)).toEqual(['Amani Wholesale'])
  })

  it('does not offer all companies to a user scoped to one company', async () => {
    mockApi(api, {
      companies: [COMPANIES[0]],
      permissions: [{ name: 'core.company.view', scopes: [{ type: 'company', id: 'c-1' }] }],
    })
    renderApp('/')
    const trigger = await within((await mainNav()).parentElement).findByRole('combobox', { name: 'Company' })
    await waitFor(() => expect(trigger).toHaveTextContent('Amani Retail'))
    fireEvent.click(trigger)
    expect(screen.queryByRole('option', { name: 'All companies' })).not.toBeInTheDocument()
  })

  it('changes the language from the account menu and saves it to the profile', async () => {
    mockApi(api)
    api.patch.mockResolvedValue({ data: { ...OWNER, locale: 'fr' } })
    renderApp('/')
    const account = await screen.findAllByRole('button', { name: /Account menu/ })
    fireEvent.pointerDown(account[0], { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitemradio', { name: 'Français' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('me', { locale: 'fr' }))
    await waitFor(() => expect(i18n.language).toBe('fr'))
  })

  it('signs out from the account menu', async () => {
    mockApi(api)
    api.post.mockResolvedValue(null)
    setCompanyId('c-1')
    const { router } = renderApp('/settings/sessions')
    const account = await screen.findAllByRole('button', { name: /Account menu/ })
    fireEvent.pointerDown(account[0], { button: 0, ctrlKey: false })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Sign out' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/sign-in'))
    // A chosen sign-out forgets the page: no ?next=.
    expect(router.state.location.search).toBe('')
    expect(api.post).toHaveBeenCalledWith('auth/sign-out')
    expect(getCompanyId()).toBeNull()
  })
})
