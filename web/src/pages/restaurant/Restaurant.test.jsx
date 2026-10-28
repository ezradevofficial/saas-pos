// REST-02, REST-05, REST-07 to REST-09, REST-11 to REST-15, REST-18: the floor
// plan, stations, modifier groups, settings, orders, kitchen display and
// reports, with the API client mocked as the other module pages do.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Eats', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const LOCATION = { id: 'l-1', name: 'Westlands restaurant', branch_id: 'br-1', company_id: 'c-1', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })

const AREA = { id: 'a-1', location_id: 'l-1', name: 'Terrace', sort: 0, archived_at: null }
const TABLE = { id: 'tb-1', location_id: 'l-1', area_id: 'a-1', name: 'T1', seats: 4, x: 5, y: 10, width: 12, height: 12, shape: 'round', manual_status: 'free', status: 'occupied', order: { id: 'o-1', kind: 'table', waiter_id: 'u-1', waiter_name: 'Grace Achieng', covers: 3, opened_at: '2026-10-27T10:00:00Z', total_minor: '450000', currency: 'KES', bill_requested: false }, archived_at: null }
const STATION = { id: 's-1', location_id: 'l-1', name: 'Kitchen', kind: 'kitchen', printer: 'Pass printer', is_default: true, sort: 0, category_ids: ['cat-1'], archived_at: null }
const CATEGORIES = [{ id: 'cat-1', name: 'Mains' }, { id: 'cat-2', name: 'Drinks' }]
const ITEMS = [{ id: 'i-svc', name: 'Service charge', code: 'SVC', type: 'service' }, { id: 'i-tip', name: 'Tip', code: 'TIP', type: 'service' }, { id: 'i-beef', name: 'Beef', code: 'BEEF', type: 'stock' }]
const USERS = [{ id: 'u-1', name: 'Grace Achieng' }, { id: 'u-2', name: 'Peter Mwangi' }]
const GROUP = { id: 'g-1', company_id: 'c-1', name: 'Cooking', min_select: 1, max_select: 1, currency: 'KES', item_ids: [], category_ids: ['cat-1'], modifiers: [{ id: 'm-1', name: 'Rare', price_minor: '0', sort: 0, archived_at: null }, { id: 'm-2', name: 'Extra cheese', price_minor: '15000', sort: 1, archived_at: null }], archived_at: null }
const SETTINGS = { company_id: 'c-1', service_charge_percent: '10.0000', service_charge_item_id: 'i-svc', tip_item_id: 'i-tip', tip_presets: [5, 10, 15] }

const LINE = { id: 'ln-1', item_id: 'i-b', name: 'Burger', qty: '2', unit_price_minor: '90000', tax_code_id: null, tax_rate: '16', tax_inclusive: true, course: 1, seat: 1, note: 'no onions', modifiers: [{ id: 'm-1', name: 'Rare', price_minor: 0 }], round: 1, sent_at: '2026-10-27T10:05:00Z', ticket_status: 'preparing', void: null }
const VOID_LINE = { ...LINE, id: 'ln-2', name: 'Soup', modifiers: [], note: null, void: { at: '2026-10-27T10:20:00Z', by: 'u-1', reason: 'Guest left', after_send: true } }
const ORDER_ROW = { id: 'o-1', location_id: 'l-1', kind: 'table', table_id: 'tb-1', table_name: '4', tab_name: null, waiter_id: 'u-1', waiter_name: 'Grace Achieng', covers: 3, status: 'open', currency: 'KES', opened_at: '2026-10-27T10:00:00Z', flags: [], bill: { lines_minor: '450000', service_charge_minor: '45000', service_tax_minor: '7200', tip_minor: '0', total_minor: '502200' } }
const ORDER = { ...ORDER_ROW, lines: [LINE, VOID_LINE], tickets: [{ id: 't-1', number: 7, station_name: 'Kitchen', kind: 'order', status: 'preparing' }], settlements: [] }
const OTHER_ORDER = { ...ORDER_ROW, id: 'o-2', table_id: 'tb-2', table_name: '5' }
const UNSENT = { ...ORDER, lines: [{ ...LINE, sent_at: null, ticket_status: null }], tickets: [] }

const ticket = (overrides) => ({ id: 't-1', number: 12, order_id: 'o-1', location_id: 'l-1', station_id: 's-1', station_name: 'Kitchen', kind: 'order', status: 'new', course: 2, round: 1, table_name: '4', tab_name: null, waiter_name: 'Grace Achieng', covers: 3, created_at: new Date(Date.now() - 12 * 60000).toISOString(), preparing_at: null, ready_at: null, served_at: null, lines: [{ id: 'tl-1', order_line_id: 'ln-1', name: 'Burger', qty: '2', modifiers: [{ name: 'Rare' }], note: 'no onions', void: null }, { id: 'tl-2', order_line_id: 'ln-2', name: 'Soup', qty: '1', modifiers: [], note: null, void: { reason: 'Guest left' } }], ...overrides })

const PENDING = { order_id: 'o-1', location_id: 'l-1', order_kind: 'table', table_name: '4', tab_name: null, line_id: 'ln-1', name: 'Steak', qty: '2', value_minor: '281250', currency: 'KES', asked_by_id: 'u-1', asked_by_name: 'Grace Achieng', named_approver_id: 'u-9', named_approver_name: 'Peter Mwangi', reason: 'Guest left', asked_at: '2026-10-27T10:20:00Z', flags: ['override_unverified'], can_apply: true }
const OVER_LIMIT = { ...PENDING, line_id: 'ln-3', name: 'Lobster', can_apply: false, value_minor: '900000' }

const REPORT = { from: '2026-10-21', to: '2026-10-27', currencies: { KES: { orders: 30, covers: 80, sales_minor: '1245000', tips_minor: '80000', avg_per_cover_minor: '15563', avg_per_order_minor: '41500' } }, avg_turn_minutes: 54.4, waiters: [{ waiter_id: 'u-1', name: 'Grace Achieng', currency: 'KES', orders: 20, covers: 50, sales_minor: '800000', tips_minor: '50000' }], voids: [{ user_id: 'u-1', name: 'Grace Achieng', currency: 'KES', count: 3, value_minor: '270000' }], void_reasons: [{ reason: 'Guest left', count: 2 }] }

const ALL = [
  'core.company.view', 'restaurant.floor.view', 'restaurant.floor.manage', 'restaurant.station.view', 'restaurant.station.manage', 'restaurant.modifier.view', 'restaurant.modifier.manage',
  'restaurant.order.view', 'restaurant.order.edit', 'restaurant.order.transfer', 'restaurant.order.merge', 'restaurant.ticket.view', 'restaurant.ticket.update', 'restaurant.settings.edit', 'restaurant.report.view', 'pos.sale.void',
]

function setup({ without = [], modules = ['core', 'pos', 'restaurant'], extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      [/^locations\?/, page([LOCATION])],
      ['restaurant/areas?location_id=l-1', { data: [AREA] }],
      ['restaurant/tables?location_id=l-1', { data: [TABLE] }],
      ['restaurant/stations?location_id=l-1', { data: [STATION] }],
      [/^item-categories/, page(CATEGORIES)],
      [/^items\?/, page(ITEMS)],
      [/^users\?/, page(USERS)],
      ['restaurant/modifier-groups?company_id=c-1', { data: [GROUP] }],
      ['restaurant/companies/c-1/settings', { data: SETTINGS }],
      [/^restaurant\/orders\?.*status=open.*per_page=100/, { data: [ORDER_ROW, OTHER_ORDER] }],
      [/^restaurant\/orders\?/, page([ORDER_ROW])],
      ['restaurant/orders/o-1', { data: ORDER }],
      [/^restaurant\/tickets\?/, { data: [ticket({}), ticket({ id: 't-2', number: 13, status: 'preparing', kind: 'resend' })] }],
      [/^restaurant\/reports\/summary/, { data: REPORT }],
      ['tenant/currencies', { data: [] }],
    ],
    { permissions: tenantWide(ALL.filter((name) => !without.includes(name))), modules, companies: [COMPANY] },
  )
}

describe('Restaurant', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  describe('floor plan (REST-02)', () => {
    it('shows the areas, the plan and derived statuses as a dot and a word', async () => {
      setup()
      renderApp('/restaurant/floor')
      expect(await screen.findByRole('tab', { name: 'Terrace' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'T1, 4 seats' })).toBeInTheDocument()
      expect(screen.getAllByText('Occupied').length).toBeGreaterThan(0)
      expect(screen.getByText('Grace Achieng · 3 guests')).toBeInTheDocument()
    })

    it('adds a table with its place on the grid', async () => {
      setup()
      api.post.mockResolvedValue({ data: { id: 'tb-9' } })
      renderApp('/restaurant/floor')
      await screen.findByRole('tab', { name: 'Terrace' })
      fireEvent.click(screen.getByRole('button', { name: 'Add table' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'T9' } })
      fireEvent.change(within(dialog).getByLabelText('Left (0 to 99)'), { target: { value: '40' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save table' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/tables', { location_id: 'l-1', area_id: 'a-1', name: 'T9', seats: 4, x: 40, y: 0, width: 10, height: 10, shape: 'square' }))
    })

    it('sets the manual status of a table when saving it', async () => {
      setup()
      api.put.mockResolvedValue({ data: TABLE })
      api.post.mockResolvedValue({ data: TABLE })
      renderApp('/restaurant/floor')
      fireEvent.click(await screen.findByRole('button', { name: 'Edit table' }))
      const dialog = await screen.findByRole('dialog')
      chooseOption(within(dialog).getByLabelText('Status set by hand'), 'Cleaning')
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save table' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/tables/tb-1/status', { status: 'cleaning' }))
      expect(api.put).toHaveBeenCalledWith('restaurant/tables/tb-1', expect.objectContaining({ name: 'T1', shape: 'round' }))
    })

    it('adds an area and archives a table', async () => {
      setup()
      api.post.mockResolvedValue({ data: {} })
      renderApp('/restaurant/floor')
      fireEvent.click(await screen.findByRole('button', { name: 'Add area' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Main hall' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Add area' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/areas', { location_id: 'l-1', name: 'Main hall', sort: 0 }))
    })

    it('hides setting up from a reader and shows the empty and error states', async () => {
      setup({ without: ['restaurant.floor.manage'] })
      renderApp('/restaurant/floor')
      await screen.findByRole('tab', { name: 'Terrace' })
      expect(screen.queryByRole('button', { name: 'Add table' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Edit table' })).not.toBeInTheDocument()
    })

    it('says so when a location has no areas, and shows a failed load', async () => {
      setup({ extra: [['restaurant/areas?location_id=l-1', { data: [] }], ['restaurant/tables?location_id=l-1', apiError(500, 'server_error', 'Something broke on our side.')]] })
      renderApp('/restaurant/floor')
      expect(await screen.findByText(/no areas yet/)).toBeInTheDocument()
      expect(await screen.findByText('Something broke on our side.')).toBeInTheDocument()
    })
  })

  describe('stations (REST-08)', () => {
    it('lists stations with the categories routed to them', async () => {
      setup()
      renderApp('/restaurant/stations')
      expect(await screen.findByText('Pass printer')).toBeInTheDocument()
      expect(screen.getByText('Mains')).toBeInTheDocument()
    })

    it('adds a station and routes a category to it', async () => {
      setup()
      api.post.mockResolvedValue({ data: {} })
      renderApp('/restaurant/stations')
      fireEvent.click(await screen.findByRole('button', { name: 'Add station' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Bar' } })
      chooseOption(within(dialog).getByLabelText('Kind'), 'Bar')
      fireEvent.change(within(dialog).getByLabelText('Printer name'), { target: { value: 'Bar printer' } })
      chooseOption(within(dialog).getByLabelText('Item categories'), 'Drinks')
      closeCombobox()
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save station' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/stations', { location_id: 'l-1', name: 'Bar', kind: 'bar', printer: 'Bar printer', is_default: false, sort: 0, category_ids: ['cat-2'] }))
    })

    it('hides the actions from someone who cannot manage stations', async () => {
      setup({ without: ['restaurant.station.manage'] })
      renderApp('/restaurant/stations')
      await screen.findByText('Pass printer')
      expect(screen.queryByRole('button', { name: 'Add station' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Archive station' })).not.toBeInTheDocument()
    })
  })

  describe('modifier groups (REST-07)', () => {
    it('lists groups with their choices and price effects, currency first', async () => {
      setup()
      renderApp('/restaurant/modifiers')
      expect(await screen.findByText('Extra cheese')).toBeInTheDocument()
      expect(document.body.textContent).toContain('KES 150.00')
    })

    it('adds a group with a priced choice in minor units', async () => {
      setup()
      api.post.mockResolvedValue({ data: {} })
      renderApp('/restaurant/modifiers')
      fireEvent.click(await screen.findByRole('button', { name: 'Add modifier group' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Extras' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Add choice' }))
      fireEvent.change(within(dialog).getByLabelText('Choice name'), { target: { value: 'Bacon' } })
      fireEvent.change(within(dialog).getByLabelText('Price added'), { target: { value: '75' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save modifier group' }))
      await waitFor(() =>
        expect(api.post).toHaveBeenCalledWith('restaurant/modifier-groups', {
          company_id: 'c-1',
          name: 'Extras',
          min_select: 0,
          max_select: 1,
          currency: 'KES',
          item_ids: [],
          category_ids: [],
          modifiers: [{ name: 'Bacon', price_minor: '7500', sort: 0 }],
        }),
      )
    })

    it('refuses a range where the most is below the fewest', async () => {
      setup()
      renderApp('/restaurant/modifiers')
      fireEvent.click(await screen.findByRole('button', { name: 'Add modifier group' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Extras' } })
      fireEvent.change(within(dialog).getByLabelText('Fewest choices'), { target: { value: '3' } })
      expect(within(dialog).getByText(/must be at least 1/)).toBeInTheDocument()
      expect(within(dialog).getByRole('button', { name: 'Save modifier group' })).toBeDisabled()
    })
  })

  describe('settings (REST-13)', () => {
    it('saves the service charge, items and tip presets', async () => {
      setup()
      api.put.mockResolvedValue({ data: SETTINGS })
      renderApp('/restaurant/settings')
      const percent = await screen.findByLabelText('Service charge')
      fireEvent.change(percent, { target: { value: '12.5' } })
      fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
      await waitFor(() => expect(api.put).toHaveBeenCalledWith('restaurant/companies/c-1/settings', { service_charge_percent: '12.5', service_charge_item_id: 'i-svc', tip_item_id: 'i-tip', tip_presets: [5, 10, 15] }))
    })

    it('does not save a service charge above 30 percent', async () => {
      setup()
      renderApp('/restaurant/settings')
      fireEvent.change(await screen.findByLabelText('Service charge'), { target: { value: '31' } })
      expect(screen.getByRole('button', { name: 'Save settings' })).toBeDisabled()
      expect(screen.getByText('The service charge cannot be more than 30 percent.')).toBeInTheDocument()
    })
  })

  describe('orders (REST-05, REST-11, REST-14, REST-15)', () => {
    async function openOrder() {
      renderApp('/restaurant/orders')
      fireEvent.click(await screen.findByText('Table 4'))
      return screen.findByRole('dialog')
    }
    const top = () => screen.getByRole('dialog')

    it('lists open orders and shows an order with lines, void marks, tickets and bill', async () => {
      setup()
      const dialog = await openOrder()
      expect(await within(dialog).findByText('Burger')).toBeInTheDocument()
      expect(within(dialog).getByText(/Void after send · Guest left/)).toBeInTheDocument()
      expect(within(dialog).getByText('no onions')).toBeInTheDocument()
      expect(within(dialog).getByText('Ticket 7')).toBeInTheDocument()
      expect(dialog.textContent).toContain('KES 5,022.00')
    })

    it('transfers the order to another waiter', async () => {
      setup()
      api.post.mockResolvedValue({ data: ORDER })
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Transfer to waiter' }))
      await screen.findByRole('dialog', { name: 'Transfer to waiter' })
      chooseOption(within(top()).getByLabelText('New waiter'), 'Peter Mwangi')
      fireEvent.click(within(top()).getByRole('button', { name: 'Transfer order' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/transfer', { waiter_id: 'u-2' }))
    })

    it('merges into another open order', async () => {
      setup()
      api.post.mockResolvedValue({ data: ORDER })
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Merge into another order' }))
      await screen.findByRole('dialog', { name: 'Merge into another order' })
      await waitFor(() => expect(within(top()).getByLabelText('Merge into')).toBeEnabled())
      chooseOption(within(top()).getByLabelText('Merge into'), 'Table 5')
      fireEvent.click(within(top()).getByRole('button', { name: 'Merge orders' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/merge', { into_order_id: 'o-2' }))
    })

    it('needs a reason to void a line', async () => {
      setup()
      api.post.mockResolvedValue({ data: ORDER })
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Void line Burger' }))
      await screen.findByRole('dialog', { name: 'Void line' })
      const confirm = within(top()).getByRole('button', { name: 'Void line' })
      expect(confirm).toBeDisabled()
      fireEvent.change(within(top()).getByLabelText(/^Reason/), { target: { value: 'Wrong dish' } })
      fireEvent.click(confirm)
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/lines/ln-1/void', { reason: 'Wrong dish' }))
    })

    it('says in words when a void is refused', async () => {
      setup()
      api.post.mockRejectedValue(apiError(403, 'void_limit_exceeded', 'No.'))
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Void line Burger' }))
      await screen.findByRole('dialog', { name: 'Void line' })
      fireEvent.change(within(top()).getByLabelText(/^Reason/), { target: { value: 'Wrong dish' } })
      fireEvent.click(within(top()).getByRole('button', { name: 'Void line' }))
      expect(await within(top()).findByText(/worth more than your limit/)).toBeInTheDocument()
    })

    it('cancels an order with nothing sent, and not one that was sent', async () => {
      setup({ extra: [['restaurant/orders/o-1', { data: UNSENT }]] })
      api.post.mockResolvedValue({ data: UNSENT })
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Cancel order' }))
      await screen.findByRole('dialog', { name: 'Cancel order' })
      fireEvent.change(within(top()).getByLabelText(/^Reason/), { target: { value: 'Guests left' } })
      fireEvent.click(within(top()).getByRole('button', { name: 'Cancel order' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/cancel', { reason: 'Guests left' }))
    })

    it('does not offer cancel once a line was sent', async () => {
      setup()
      const dialog = await openOrder()
      await within(dialog).findByText('Burger')
      expect(within(dialog).queryByRole('button', { name: 'Cancel order' })).not.toBeInTheDocument()
    })

    it('previews a split as a table of parts', async () => {
      setup()
      api.post.mockResolvedValue({ data: { parts: [{ line_ids: [], lines_minor: '225000', service_charge_minor: '22500', service_tax_minor: '3600', tip_minor: '0', total_minor: '251100', equal: true }, { line_ids: [], lines_minor: '225000', service_charge_minor: '22500', service_tax_minor: '3600', tip_minor: '0', total_minor: '251100', equal: true }] } })
      const dialog = await openOrder()
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Split bill' }))
      await screen.findByRole('dialog', { name: 'Split bill' })
      fireEvent.click(within(top()).getByRole('button', { name: 'Show parts' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/split', { mode: 'equal', parts: 2 }))
      expect(await within(top()).findAllByText('An equal share of every line')).toHaveLength(2)
      expect(top().textContent).toContain('KES 2,511.00')
    })

    it('hides transfer, merge and void from someone without those permissions', async () => {
      setup({ without: ['restaurant.order.transfer', 'restaurant.order.merge', 'pos.sale.void'] })
      const dialog = await openOrder()
      await within(dialog).findByText('Burger')
      expect(within(dialog).queryByRole('button', { name: 'Transfer to waiter' })).not.toBeInTheDocument()
      expect(within(dialog).queryByRole('button', { name: 'Merge into another order' })).not.toBeInTheDocument()
      expect(within(dialog).queryByRole('button', { name: 'Void line Burger' })).not.toBeInTheDocument()
      expect(within(dialog).getByRole('button', { name: 'Split bill' })).toBeInTheDocument()
    })

    it('filters the list by status, kind, waiter and search', async () => {
      setup()
      renderApp('/restaurant/orders')
      await screen.findByText('Table 4')
      chooseOption(screen.getByLabelText('Kind'), 'Tab')
      await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^restaurant\/orders\?.*kind=tab/)))
      expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^restaurant\/orders\?.*status=open/))
    })

    it('shows an empty list and a failed load', async () => {
      setup({ extra: [[/^restaurant\/orders\?/, page([])]] })
      renderApp('/restaurant/orders')
      expect(await screen.findByText('No orders match these filters.')).toBeInTheDocument()
    })

    it('shows the error when orders cannot be read', async () => {
      setup({ extra: [[/^restaurant\/orders\?/, apiError(500, 'server_error', 'Something broke on our side.')]] })
      renderApp('/restaurant/orders')
      expect(await screen.findByText('Something broke on our side.')).toBeInTheDocument()
    })
  })

  describe('kitchen display (REST-09, REST-12)', () => {
    it('shows tickets in columns with age, course, modifiers, notes and marks', async () => {
      setup()
      renderApp('/restaurant/kitchen')
      expect(await screen.findByRole('article', { name: 'Ticket 12' })).toBeInTheDocument()
      const card = screen.getByRole('article', { name: 'Ticket 12' })
      expect(within(card).getByText('Table 4')).toBeInTheDocument()
      expect(within(card).getByText('Grace Achieng · Course 2')).toBeInTheDocument()
      expect(within(card).getByText('12 min')).toBeInTheDocument()
      expect(within(card).getByText('Rare')).toBeInTheDocument()
      expect(within(card).getByText('no onions')).toBeInTheDocument()
      expect(within(card).getAllByText('Void').length).toBeGreaterThan(0)
      expect(within(screen.getByRole('article', { name: 'Ticket 13' })).getByText('Resend')).toBeInTheDocument()
      expect(api.get).toHaveBeenCalledWith('restaurant/tickets?location_id=l-1&station_id=s-1&status=active')
    })

    it('starts a new ticket and moves it to the preparing column', async () => {
      setup()
      api.post.mockResolvedValue({ data: ticket({ status: 'preparing' }) })
      renderApp('/restaurant/kitchen')
      const card = await screen.findByRole('article', { name: 'Ticket 12' })
      fireEvent.click(within(card).getByRole('button', { name: 'Start' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/tickets/t-1/status', { status: 'preparing' }))
      const preparing = screen.getByRole('region', { name: 'Preparing' })
      await waitFor(() => expect(within(preparing).getByRole('article', { name: 'Ticket 12' })).toBeInTheDocument())
    })

    it('marks a preparing ticket ready and recalls a ready one', async () => {
      setup({ extra: [[/^restaurant\/tickets\?/, { data: [ticket({ status: 'preparing' }), ticket({ id: 't-3', number: 14, status: 'ready' })] }]] })
      api.post.mockResolvedValue({ data: {} })
      renderApp('/restaurant/kitchen')
      const card = await screen.findByRole('article', { name: 'Ticket 12' })
      fireEvent.click(within(card).getByRole('button', { name: 'Mark ready' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/tickets/t-1/status', { status: 'ready' }))
      fireEvent.click(within(screen.getByRole('article', { name: 'Ticket 14' })).getByRole('button', { name: 'Recall' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/tickets/t-3/status', { status: 'preparing' }))
    })

    it('shows no buttons to someone who cannot update tickets', async () => {
      setup({ without: ['restaurant.ticket.update'] })
      renderApp('/restaurant/kitchen')
      await screen.findByRole('article', { name: 'Ticket 12' })
      expect(screen.queryByRole('button', { name: 'Start' })).not.toBeInTheDocument()
    })

    it('has no sidebar and says when there are no tickets', async () => {
      setup({ extra: [[/^restaurant\/tickets\?/, { data: [] }]] })
      renderApp('/restaurant/kitchen')
      expect((await screen.findAllByText('No tickets')).length).toBe(3)
      expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
    })

    it('refreshes by polling with since after the first load', async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true })
      try {
        setup()
        renderApp('/restaurant/kitchen')
        await screen.findByRole('article', { name: 'Ticket 12' })
        await vi.advanceTimersByTimeAsync(5200)
        await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^restaurant\/tickets\?.*status=all&since=/)))
      } finally {
        vi.useRealTimers()
      }
    })
  })

  describe('reports (REST-18)', () => {
    it('shows the figures with the currency first, waiters and voids after send', async () => {
      setup()
      renderApp('/restaurant/reports')
      expect(await screen.findByText('Average spend per guest')).toBeInTheDocument()
      expect(document.body.textContent).toContain('KES 12,450.00')
      expect(document.body.textContent).toContain('KES 155.63')
      expect(screen.getByText('54 min')).toBeInTheDocument()
      expect(screen.getAllByText('Grace Achieng').length).toBeGreaterThan(1)
      expect(screen.getByText('Guest left')).toBeInTheDocument()
      expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^restaurant\/reports\/summary\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}$/))
    })

    it('sends the chosen place', async () => {
      setup()
      renderApp('/restaurant/reports')
      await screen.findByText('Average spend per guest')
      chooseOption(screen.getByLabelText('Location'), 'Westlands restaurant')
      await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/location_id=l-1/)))
    })
  })

  describe('voids waiting for approval (REST-11)', () => {
    it('lists the order, line, value, who asked and why, and applies a void through the void route', async () => {
      setup({ extra: [['restaurant/pending-voids', { data: [PENDING] }]] })
      api.post.mockResolvedValue({ data: ORDER })
      renderApp('/restaurant/pending-voids')
      const row = (await screen.findByText('2 × Steak')).closest('tr')
      expect(within(row).getByText('Table 4')).toBeInTheDocument()
      expect(within(row).getByText('Grace Achieng')).toBeInTheDocument()
      expect(within(row).getByText('Guest left')).toBeInTheDocument()
      expect(row.textContent).toContain('KES 2,812.50')

      fireEvent.click(within(row).getByRole('button', { name: 'Apply the void of Steak' }))
      const dialog = await screen.findByRole('dialog', { name: 'Apply this void' })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Apply' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/lines/ln-1/void', { reason: 'Guest left' }))
    })

    it('rejects a void through the reject route', async () => {
      setup({ extra: [['restaurant/pending-voids', { data: [PENDING] }]] })
      api.post.mockResolvedValue({ data: ORDER })
      renderApp('/restaurant/pending-voids')
      const row = (await screen.findByText('2 × Steak')).closest('tr')
      fireEvent.click(within(row).getByRole('button', { name: 'Reject the void of Steak' }))
      const dialog = await screen.findByRole('dialog', { name: 'Reject this void' })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Reject' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('restaurant/orders/o-1/lines/ln-1/void/reject', {}))
    })

    it('offers Apply only for lines within the reader\'s limit, and Reject always', async () => {
      setup({ extra: [['restaurant/pending-voids', { data: [PENDING, OVER_LIMIT] }]] })
      renderApp('/restaurant/pending-voids')
      const row = (await screen.findByText('2 × Lobster')).closest('tr')
      expect(within(row).queryByRole('button', { name: 'Apply the void of Lobster' })).not.toBeInTheDocument()
      expect(within(row).getByText('Above your limit')).toBeInTheDocument()
      expect(within(row).getByRole('button', { name: 'Reject the void of Lobster' })).toBeInTheDocument()
    })

    it('says so when nothing waits, and is not in the menu without pos.sale.void', async () => {
      setup({ extra: [['restaurant/pending-voids', { data: [] }]] })
      renderApp('/restaurant/pending-voids')
      expect(await screen.findByText('No voids are waiting for approval.')).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Pending voids' })).toBeInTheDocument()
    })

    it('keeps the page and the menu entry from someone without pos.sale.void', async () => {
      setup({ without: ['pos.sale.void'], extra: [['restaurant/pending-voids', { data: [] }]] })
      renderApp('/restaurant/orders')
      await screen.findByRole('heading', { name: 'Orders and tabs' })
      expect(screen.queryByRole('link', { name: 'Pending voids' })).not.toBeInTheDocument()
    })
  })

  describe('navigation (RBAC-08)', () => {
    it('shows the Restaurant group only while the module is active', async () => {
      setup()
      renderApp('/restaurant/orders')
      expect(await screen.findByRole('link', { name: 'Kitchen display' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Floor plan' })).toBeInTheDocument()
    })

    it('hides it without the module', async () => {
      setup({ modules: ['core', 'pos'] })
      renderApp('/restaurant/orders')
      await screen.findByRole('heading', { name: 'Orders and tabs' })
      expect(screen.queryByRole('link', { name: 'Kitchen display' })).not.toBeInTheDocument()
    })
  })
})
