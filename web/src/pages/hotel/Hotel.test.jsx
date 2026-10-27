// HOT-01 to HOT-38: the hotel back office: settings, rooms and their status, availability, a new reservation,
// the front desk (check-in and check-out), the folio (charges, payments, voids), housekeeping, the night audit,
// room charges to resolve, guests (ID masked), and the module and permission gating of the menu.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { todayIn } from '@/lib/dates'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { addDays } from './hotelData'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Lakeside Lodge', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const BRANCH = { id: 'br-1', name: 'Main building', company_id: 'c-1', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const TODAY = todayIn('Africa/Nairobi')

const TYPE = { id: 't-1', company_id: 'c-1', code: 'DBL', name: 'Double', description: null, base_adults: 2, max_adults: 2, max_children: 1, max_occupancy: 3, beds: '1 queen', sort: 0, rooms_count: 2, archived_at: null }
const ROOM = { id: 'r-1', company_id: 'c-1', branch: { id: 'br-1', name: 'Main building' }, room_type: { id: 't-1', code: 'DBL', name: 'Double' }, number: '101', floor: '1', features: ['balcony'], notes: null, status: 'vacant_clean', out_of_order: null, archived_at: null }
const PLAN = {
  id: 'p-1', company_id: 'c-1', room_type: { id: 't-1', code: 'DBL', name: 'Double' }, code: 'BB', name: 'Bed and breakfast', board: 'bed_breakfast', currency: 'KES', weekday_rate_minor: '500000', weekend_rate_minor: '600000',
  extra_adult_minor: '0', child_rate_minor: '0', free_children: 1, tax_inclusive: false, valid_from: null, valid_until: null, cancel_free_days: null, cancel_charge_kind: null, cancel_charge_value: null,
  noshow_charge_kind: null, noshow_charge_value: null, active: true, seasons: [], archived_at: null,
}
const STAY = {
  id: 's-1', reservation_id: 'rs-1', reservation_number: 'RS-MAIN-2026-00001', branch_id: 'br-1', status: 'reserved', guest: { id: 'g-1', name: 'Amina Njeri' },
  room_type: { id: 't-1', code: 'DBL', name: 'Double' }, rate_plan: { id: 'p-1', code: 'BB', name: 'Bed and breakfast' }, room: null, arrival_on: TODAY, departure_on: addDays(TODAY, 2), nights: 2, adults: 2, children: 0,
  board: 'bed_breakfast', currency: 'KES', total_minor: '1000000', rate_overridden: false, bill_to_master: false, keys_issued: 0, checked_in_at: null, checked_out_at: null, cancelled_at: null, cancel_reason: null,
  no_show_at: null, notes: null, folio_id: 'f-1',
  nightly: [{ date: TODAY, rate_minor: '500000', extra_minor: '0', amount_minor: '500000', season: null }, { date: addDays(TODAY, 1), rate_minor: '500000', extra_minor: '0', amount_minor: '500000', season: null }],
}
const LINE = (over = {}) => ({
  id: 'l-1', line_no: 1, kind: 'room', description: 'Room 101, night of 2026-10-27', qty: '1', amount_minor: '500000', net_minor: '500000', tax_minor: '0', taxes: [], currency: 'KES', business_date: TODAY,
  source: 'night', reference: null, reverses_id: null, flags: [], created_at: `${TODAY}T10:00:00Z`, ...over,
})
const FOLIO = (over = {}) => ({
  id: 'f-1', number: 'FO-MAIN-2026-00001', kind: 'guest', status: 'open', currency: 'KES', branch_id: 'br-1', reservation_id: 'rs-1', reservation_number: 'RS-MAIN-2026-00001', stay_id: 's-1', room_number: '101',
  guest: { id: 'g-1', name: 'Amina Njeri' }, charges_minor: '500000', payments_minor: '0', balance_minor: '500000', settled_at: null, invoice_number: null, balance_left_minor: null,
  lines: [LINE()], pending_payments: [], ...over,
})
const METHODS = [
  { id: 'pm-1', company_id: 'c-1', type: 'cash', name: 'Cash', currency: null, provider: null, active: true, archived_at: null },
  { id: 'pm-2', company_id: 'c-1', type: 'mobile_money', name: 'M-Pesa', currency: 'KES', provider: 'daraja', active: true, archived_at: null },
]
const SETTINGS = {
  company_id: 'c-1', check_in_time: '14:00', check_out_time: '10:00', night_audit_time: '02:00', early_checkin_fee_minor: '0', late_checkout_fee_minor: '0', fee_currency: 'KES', weekend_days: [5, 6],
  cancel_free_days: 1, cancel_charge_kind: 'nights', cancel_charge_value: '1', noshow_charge_kind: 'nights', noshow_charge_value: '1', deposit_percent: 30, require_guest_id: true, room_item_id: null,
  levy_tax_code_ids: [], charge_limit_minor: null, room_charge_method_id: null,
}
const GUEST = { id: 'g-1', company_id: 'c-1', party_id: 'pa-1', name: 'Amina Njeri', phone: '+254700000001', email: null, nationality: 'Kenyan', id_type: 'national_id', identity_document: '•••• 5678', has_identity_document: true, date_of_birth: null, address: null, notes: null, archived_at: null }
const AUDIT = {
  id: 'a-1', branch_id: 'br-1', business_date: addDays(TODAY, -1), status: 'completed', started_at: `${TODAY}T01:00:00Z`, completed_at: `${TODAY}T01:01:00Z`, run_by: null, scheduled: true,
  summary: {
    rooms_total: 4, rooms_out_of_order: 0, rooms_available: 4, rooms_sold: 3, rooms_complimentary: 0, occupancy_percent: 75, arrivals: 1, departures: 1, no_shows: 1, in_house_guests: 5, charges_posted: 3, tasks_created: 2,
    overstays: [{ stay_id: 's-9', room_number: '102', departure_on: addDays(TODAY, -1) }], flags: [], errors: [],
    revenue: { KES: { net_minor: '1500000', gross_minor: '1740000', tax_minor: '240000', adr_minor: '500000', revpar_minor: '375000' } },
  },
}

const ALL = [
  'core.company.view', 'core.branch.view', 'hotel.settings.view', 'hotel.settings.edit', 'hotel.room.view', 'hotel.room.manage', 'hotel.rate.view', 'hotel.rate.manage', 'hotel.rate.override',
  'hotel.guest.view', 'hotel.guest.manage', 'hotel.guest.identity', 'hotel.reservation.view', 'hotel.reservation.create', 'hotel.reservation.edit', 'hotel.reservation.cancel',
  'hotel.stay.checkin', 'hotel.stay.checkout', 'hotel.stay.move', 'hotel.folio.view', 'hotel.folio.charge', 'hotel.folio.payment', 'hotel.folio.refund', 'hotel.folio.void', 'hotel.folio.transfer',
  'hotel.folio.settle_open', 'hotel.fee.waive', 'hotel.charge.resolve', 'hotel.housekeeping.view', 'hotel.housekeeping.assign', 'hotel.housekeeping.update', 'hotel.audit.view', 'hotel.audit.run', 'hotel.report.view',
]

function setup(routes = [], permissions = ALL, modules = ['core', 'hotel']) {
  mockRoutes(
    api,
    [
      ...routes,
      [/^branches\?/, { data: [BRANCH] }],
      ['companies/c-1/hotel-settings', { data: SETTINGS }],
      ['companies/c-1/payment-methods?per_page=200', { data: METHODS }],
      ['companies/c-1/tax-codes?per_page=200', { data: [] }],
      [/^hotel\/room-types\?/, { data: [TYPE] }],
      [/^hotel\/rooms\?/, { data: [ROOM] }],
      [/^hotel\/rate-plans\?/, { data: [PLAN] }],
      [/^hotel\/rate-quote\?/, { data: { rate_plan_id: 'p-1', currency: 'KES', board: 'bed_breakfast', tax_inclusive: false, nights: STAY.nightly, total_minor: '1000000', estimate: { net_minor: '1000000', tax_minor: '0', gross_minor: '1000000', flags: [] } } }],
      ['hotel/folios/f-1', { data: FOLIO() }],
      ['hotel/guests/g-1', { data: GUEST }],
      [/^hotel\/guests\?/, page([GUEST])],
      [/^users\?/, page([])],
    ],
    { permissions: tenantWide(permissions), modules, companies: [COMPANY] },
  )
}

describe('Hotel back office', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('saves the company settings as the API takes them, with no levy or rate built in (HOT-02, HOT-32)', async () => {
    setup()
    api.put.mockResolvedValue({ data: { ...SETTINGS, cancel_free_days: 2 } })
    renderApp('/hotel/settings')
    expect(await screen.findByText(/None is taken from a regulator/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Free cancellation until/), { target: { value: '2' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith(
        'companies/c-1/hotel-settings',
        expect.objectContaining({ check_in_time: '14:00', check_out_time: '10:00', cancel_free_days: 2, cancel_charge_kind: 'nights', cancel_charge_value: '1', weekend_days: [5, 6], deposit_percent: 30, require_guest_id: true, levy_tax_code_ids: [], room_item_id: null }),
      ),
    )
    expect(await screen.findByText('Settings saved.')).toBeInTheDocument()
  })

  it('lists rooms by floor with their status and sets one out of order with a reason (HOT-04, HOT-05)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...ROOM, status: 'out_of_order' } })
    renderApp('/hotel/rooms')
    const row = (await screen.findByText('101')).closest('tr')
    expect(screen.getAllByText('Floor 1').length).toBeGreaterThan(0)
    expect(within(row).getByText('Vacant and clean').closest('[data-tone]')).toHaveAttribute('data-tone', 'success')
    fireEvent.click(within(row).getByRole('button', { name: 'Status of room 101' }))
    const dialog = await screen.findByRole('dialog', { name: 'Status of room 101' })
    chooseOption(within(dialog).getByLabelText(/^Status/), 'Out of order')
    const save = within(dialog).getByRole('button', { name: 'Save status' })
    expect(save).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Leaking pipe' } })
    fireEvent.click(save)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/rooms/r-1/status', { status: 'out_of_order', reason: 'Leaking pipe', from: null, until: null }))
  })

  it('shows the rooms free per room type and night (HOT-11)', async () => {
    setup([
      [
        /^hotel\/availability\?/,
        {
          data: {
            from: TODAY, to: addDays(TODAY, 14),
            types: [{ id: 't-1', code: 'DBL', name: 'Double', nights: [{ date: TODAY, rooms: 2, out_of_order: 0, booked: 0, free: 2 }, { date: addDays(TODAY, 1), rooms: 2, out_of_order: 0, booked: 2, free: 0 }] }],
            rooms: [{ id: 'r-1', number: '101', floor: '1', room_type_id: 't-1', status: 'vacant_clean', out_of_order: null, stays: [{ id: 's-1', reservation_id: 'rs-1', guest: 'Amina Njeri', status: 'reserved', arrival_on: TODAY, departure_on: addDays(TODAY, 2) }] }],
            unassigned: [],
          },
        },
      ],
    ])
    renderApp('/hotel/availability')
    expect(await screen.findByLabelText('2 rooms free')).toBeInTheDocument()
    expect(screen.getByLabelText('0 rooms free')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Amina Njeri' })).toHaveAttribute('href', '/hotel/reservations/rs-1')
  })

  it('creates a reservation for a new guest with the quoted room and plan (HOT-10, HOT-12)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { id: 'rs-1', number: 'RS-MAIN-2026-00001', stays: [STAY], folios: [] } })
    const { router } = renderApp('/hotel/reservations/new')
    fireEvent.click(await screen.findByRole('button', { name: 'Add a new guest' }))
    fireEvent.change(screen.getByLabelText(/^Guest name/), { target: { value: 'Zawadi Otieno' } })
    fireEvent.change(screen.getByLabelText(/^Phone/), { target: { value: '+254711000000' } })
    await waitForOption(screen.getByLabelText(/^Room type/), 'Double')
    chooseOption(screen.getByLabelText(/^Room type/), 'Double')
    await waitForOption(screen.getByLabelText(/^Rate plan/), /Bed and breakfast/)
    chooseOption(screen.getByLabelText(/^Rate plan/), /Bed and breakfast/)
    expect(await screen.findByText('Rate quote')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Create reservation' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        'hotel/reservations',
        expect.objectContaining({
          branch_id: 'br-1',
          kind: 'direct',
          guest: { name: 'Zawadi Otieno', phone: '+254711000000' },
          stays: [{ room_type_id: 't-1', rate_plan_id: 'p-1', arrival_on: TODAY, departure_on: addDays(TODAY, 1), adults: 2, children: 0 }],
        }),
      ),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/hotel/reservations/rs-1'))
  })

  it('checks a guest in to a clean room and hands over keys (HOT-17)', async () => {
    setup([
      [/^hotel\/reports\/arrivals\?/, page([STAY])],
      [/^hotel\/reports\/in-house\?/, page([])],
      [/^hotel\/reports\/departures\?/, page([])],
    ])
    api.post.mockResolvedValue({ data: { ...STAY, status: 'checked_in' } })
    renderApp('/hotel/front-desk')
    fireEvent.click(await screen.findByRole('button', { name: 'Check in Amina Njeri' }))
    const dialog = await screen.findByRole('dialog', { name: 'Check in Amina Njeri' })
    await waitForOption(within(dialog).getByLabelText(/^Room/), '101 · Double')
    chooseOption(within(dialog).getByLabelText(/^Room/), '101 · Double')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Check in' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/stays/s-1/check-in', { room_id: 'r-1', keys_issued: 1 }))
  })

  it('shows the folio balance at check-out and takes the payment that clears it (HOT-19)', async () => {
    const inHouse = { ...STAY, status: 'checked_in', room: { id: 'r-1', number: '101', status: 'occupied' } }
    setup([
      [/^hotel\/reports\/arrivals\?/, page([])],
      [/^hotel\/reports\/in-house\?/, page([inHouse])],
      [/^hotel\/reports\/departures\?/, page([inHouse])],
    ])
    api.post.mockResolvedValue({ data: { stay: { ...inHouse, status: 'checked_out' }, folio: FOLIO({ status: 'settled' }) } })
    renderApp('/hotel/front-desk?tab=in-house')
    fireEvent.click(await screen.findByRole('button', { name: 'Check out Amina Njeri' }))
    const dialog = await screen.findByRole('dialog', { name: 'Check out Amina Njeri' })
    expect(await within(dialog).findByText('Open folio FO-MAIN-2026-00001')).toBeInTheDocument()
    // A balance is owed: the check-out waits for a payment (or, for a manager, leaving it on account).
    expect(within(dialog).getByRole('button', { name: 'Check out' })).toBeDisabled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add a payment' }))
    await waitForOption(within(dialog).getByLabelText(/^Payment method/), 'Cash')
    chooseOption(within(dialog).getByLabelText(/^Payment method/), 'Cash')
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Check out' })).toBeEnabled())
    fireEvent.click(within(dialog).getByRole('button', { name: 'Check out' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/stays/s-1/check-out', { payments: [{ payment_method_id: 'pm-1', amount_minor: '500000' }] }))
  })

  it('leaves a balance on account at check-out only for those who may (HOT-19)', async () => {
    const inHouse = { ...STAY, status: 'checked_in', room: { id: 'r-1', number: '101', status: 'occupied' } }
    const permissions = ALL.filter((name) => name !== 'hotel.folio.settle_open')
    setup([[/^hotel\/reports\/in-house\?/, page([inHouse])], [/^hotel\/reports\/(arrivals|departures)\?/, page([])]], permissions)
    renderApp('/hotel/front-desk?tab=in-house')
    fireEvent.click(await screen.findByRole('button', { name: 'Check out Amina Njeri' }))
    const dialog = await screen.findByRole('dialog', { name: 'Check out Amina Njeri' })
    expect(await within(dialog).findByText(/Take a payment, or ask a manager/)).toBeInTheDocument()
    expect(within(dialog).queryByRole('switch', { name: 'Leave the balance on account' })).not.toBeInTheDocument()
  })

  it('shows folio lines as charges and payments, marks voids, and posts a fee (HOT-20, HOT-21)', async () => {
    setup([
      [
        'hotel/folios/f-1',
        {
          data: FOLIO({
            charges_minor: '500000', payments_minor: '200000', balance_minor: '300000',
            lines: [
              LINE(),
              LINE({ id: 'l-2', line_no: 2, kind: 'extra', description: 'Minibar', amount_minor: '30000', net_minor: '30000' }),
              LINE({ id: 'l-3', line_no: 3, kind: 'void', description: 'Void: Minibar', amount_minor: '-30000', net_minor: '-30000', reverses_id: 'l-2' }),
              LINE({ id: 'l-4', line_no: 4, kind: 'deposit', description: 'Deposit (Cash)', amount_minor: '-200000', net_minor: '-200000' }),
            ],
          }),
        },
      ],
    ])
    api.post.mockResolvedValue({ data: LINE({ id: 'l-5', line_no: 5, kind: 'fee', description: 'Parking', amount_minor: '2500' }) })
    renderApp('/hotel/folios/f-1')
    const minibar = (await screen.findByText('Minibar')).closest('tr')
    expect(within(minibar).getByText('Voided')).toBeInTheDocument()
    expect(screen.getByText('Reversal')).toBeInTheDocument()
    const deposit = screen.getByText('Deposit (Cash)').closest('tr')
    expect(within(deposit).getByText('2,000.00')).toBeInTheDocument()

    fireEvent.click(screen.getAllByRole('button', { name: 'Add a charge' })[0])
    const dialog = await screen.findByRole('dialog', { name: 'Add a charge' })
    chooseOption(within(dialog).getByLabelText(/^Type of charge/), 'Fee or allowance')
    fireEvent.change(within(dialog).getByLabelText(/^Description/), { target: { value: 'Parking' } })
    fireEvent.change(within(dialog).getByLabelText(/^Amount/), { target: { value: '25' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Post charge' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/folios/f-1/charges', { amount_minor: '2500', description: 'Parking' }))
  })

  it('voids a line with a reason, never a delete (HOT-21)', async () => {
    setup()
    api.post.mockResolvedValue({ data: LINE({ id: 'l-9', line_no: 2, kind: 'void', amount_minor: '-500000' }) })
    renderApp('/hotel/folios/f-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Void line 1' }))
    const dialog = await screen.findByRole('dialog', { name: 'Void line 1' })
    const confirm = within(dialog).getByRole('button', { name: 'Void' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Wrong night' } })
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/folios/f-1/lines/l-1/void', { reason: 'Wrong night' }))
  })

  it('starts an M-Pesa push, shows it pending and asks the API again on request (HOT-14, HOT-24)', async () => {
    setup()
    api.post
      .mockResolvedValueOnce({ data: { id: 'pay-1', folio_id: 'f-1', kind: 'payment', status: 'pending', amount_minor: '100000', currency: 'KES', failure: null } })
      .mockResolvedValueOnce({ data: { id: 'pay-1', folio_id: 'f-1', kind: 'payment', status: 'pending', amount_minor: '100000', currency: 'KES', failure: null } })
    renderApp('/hotel/folios/f-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Take a payment' }))
    const dialog = await screen.findByRole('dialog', { name: 'Take a payment' })
    await waitForOption(within(dialog).getByLabelText(/^Payment method/), 'M-Pesa')
    chooseOption(within(dialog).getByLabelText(/^Payment method/), 'M-Pesa')
    fireEvent.change(within(dialog).getByLabelText(/^Amount/), { target: { value: '1000' } })
    chooseOption(within(dialog).getByLabelText(/^How the guest pays/), 'M-Pesa request to the phone')
    fireEvent.change(within(dialog).getByLabelText(/^Phone/), { target: { value: '0712000000' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/folios/f-1/payments', { payment_method_id: 'pm-2', amount_minor: '100000', kind: 'payment', mode: 'stk', phone: '0712000000' }))
    const waiting = await screen.findByRole('dialog', { name: 'Waiting for the guest' })
    expect(within(waiting).getByText('Waiting for the provider')).toBeInTheDocument()
    fireEvent.click(within(waiting).getByRole('button', { name: 'Check now' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/folios/f-1/payments/pay-1/check'))
  })

  it('shows the housekeeping board by floor and starts a task (HOT-28)', async () => {
    setup([
      [
        'hotel/housekeeping/board?branch_id=br-1',
        {
          data: {
            business_date: TODAY, counts: { vacant_dirty: 1 },
            rooms: [{ id: 'r-1', number: '101', floor: '1', status: 'vacant_dirty', features: [], room_type: { id: 't-1', code: 'DBL', name: 'Double' }, out_of_order: null, departing_today: false, arriving_today: true, tasks: [{ id: 'k-1', kind: 'checkout_clean', status: 'pending', business_date: TODAY, assigned_to: null }] }],
          },
        },
      ],
      [/^hotel\/housekeeping\/tasks\?/, page([])],
    ])
    api.post.mockResolvedValue({ data: { id: 'k-1', status: 'in_progress' } })
    renderApp('/hotel/housekeeping')
    expect(await screen.findByText('Floor 1')).toBeInTheDocument()
    expect(screen.getByText('Check-out clean')).toBeInTheDocument()
    expect(screen.getByText('Arriving today')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Start cleaning room 101' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/housekeeping/tasks/k-1/start'))
  })

  it('shows the night audit snapshot and runs the audit on request (HOT-30)', async () => {
    setup([[/^hotel\/night-audits\?/, { data: [AUDIT], meta: { branch_id: 'br-1', business_date: TODAY } }]])
    api.post.mockResolvedValue({ data: { ...AUDIT, id: 'a-2', business_date: TODAY, scheduled: false } })
    renderApp('/hotel/night-audit')
    expect(await screen.findByText('75 %')).toBeInTheDocument()
    expect(screen.getByText('KES 15,000.00')).toBeInTheDocument()
    expect(screen.getByText('Overstays')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Run night audit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Run the night audit now' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Run audit' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/night-audits', { branch_id: 'br-1', business_date: TODAY }))
  })

  it('resolves a room charge that could not be posted by moving it to a folio (HOT-22)', async () => {
    setup([
      [/^hotel\/charge-exceptions\?/, page([{ id: 'e-1', branch_id: 'br-1', source: 'pos', source_id: 'x', reference: 'R-9', room_number: '104', currency: 'KES', amount_minor: '120000', reason: 'not_in_house', status: 'open', resolution: null, resolved_folio_id: null, note: null, resolved_at: null, created_at: `${TODAY}T08:00:00Z` }])],
      [/^hotel\/folios\?branch=br-1&status=open/, page([{ id: 'f-2', number: 'FO-MAIN-2026-00002', currency: 'KES', room_number: '102', guest: { id: 'g-2', name: 'Zawadi Otieno' } }])],
    ])
    api.post.mockResolvedValue({ data: { id: 'e-1', status: 'resolved' } })
    renderApp('/hotel/charge-exceptions')
    const row = (await screen.findByText('R-9')).closest('tr')
    expect(within(row).getByText('No guest in the room')).toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Resolve charge R-9' }))
    const dialog = await screen.findByRole('dialog', { name: 'Resolve charge R-9' })
    await waitForOption(within(dialog).getByLabelText(/^Post to folio/), /FO-MAIN-2026-00002/)
    chooseOption(within(dialog).getByLabelText(/^Post to folio/), /FO-MAIN-2026-00002/)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Resolve' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/charge-exceptions/e-1/resolve', { resolution: 'moved', folio_id: 'f-2' }))
  })

  it('masks the guest ID and reveals it in full only on request, for those who may (HOT-16, HOT-38)', async () => {
    setup([['hotel/guests/g-1?reveal=1', { data: { ...GUEST, identity_document: '12345678' } }]])
    renderApp('/hotel/guests/g-1')
    expect(await screen.findByText('•••• 5678')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Reveal' }))
    expect(await screen.findByText('12345678')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('hotel/guests/g-1?reveal=1')
    fireEvent.click(screen.getByRole('button', { name: 'Hide' }))
    expect(await screen.findByText('•••• 5678')).toBeInTheDocument()
  })

  it('offers no reveal without the identity permission (HOT-38)', async () => {
    setup([], ALL.filter((name) => name !== 'hotel.guest.identity'))
    renderApp('/hotel/guests/g-1')
    expect(await screen.findByText('•••• 5678')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reveal' })).not.toBeInTheDocument()
  })

  it('previews the cancellation charge of the rule and cancels with a reason (HOT-15)', async () => {
    setup([
      ['hotel/rate-plans/p-1', { data: PLAN }],
      ['hotel/reservations/rs-1', { data: { id: 'rs-1', number: 'RS-MAIN-2026-00001', kind: 'direct', branch: { id: 'br-1', name: 'Main building' }, guest: { id: 'g-1', name: 'Amina Njeri' }, group_name: null, source: 'direct', notes: null, stays_count: 1, stays: [STAY], folios: [{ id: 'f-1', number: 'FO-MAIN-2026-00001', kind: 'guest', stay_id: 's-1', status: 'open', currency: 'KES' }] } }],
    ])
    api.post.mockResolvedValue({ data: { ...STAY, status: 'cancelled' } })
    renderApp('/hotel/reservations/rs-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel stay' }))
    const dialog = await screen.findByRole('dialog', { name: 'Cancel this stay' })
    // The rule is one night and the guest arrives today, inside the free window of one day: the first night is charged.
    expect(await within(dialog).findByText(/The charge will be/)).toBeInTheDocument()
    expect(within(dialog).getByText('5,000.00')).toBeInTheDocument()
    const confirm = within(dialog).getByRole('button', { name: 'Cancel stay' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Reason/), { target: { value: 'Guest ill' } })
    fireEvent.click(within(dialog).getByRole('switch', { name: 'Waive the charge' }))
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('hotel/stays/s-1/cancel', { reason: 'Guest ill', waive_charge: true }))
  })

  it('shows the occupancy report with ADR and RevPAR per currency (HOT-31)', async () => {
    setup([
      [
        /^hotel\/reports\/occupancy\?/,
        {
          data: {
            branch_id: 'br-1', from: addDays(TODAY, -13), to: TODAY, business_date: TODAY,
            days: [{ date: TODAY, source: 'live', rooms_available: 4, rooms_sold: 3, occupancy_percent: 75, revenue: { KES: { net_minor: '1500000', adr_minor: '500000', revpar_minor: '375000' } } }],
            totals: { rooms_available: 4, rooms_sold: 3, occupancy_percent: 75, revenue: { KES: { net_minor: '1500000', adr_minor: '500000', revpar_minor: '375000' } } },
          },
        },
      ],
    ])
    renderApp('/hotel/reports/occupancy')
    expect((await screen.findAllByText('Rooms sold')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('KES 5,000.00').length).toBeGreaterThan(0)
    expect(screen.getAllByText('KES 3,750.00').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: 'Download CSV' })).toBeEnabled()
  })

  describe('menu gating (RBAC-08, RBAC-09)', () => {
    it('shows the Hotel group to a tenant with the module and the permissions', async () => {
      setup([], ALL, ['core', 'hotel'])
      renderApp('/')
      expect(await screen.findByRole('link', { name: 'Front desk' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Housekeeping' })).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Night audit' })).toBeInTheDocument()
    })

    it('hides it without the module, and hides items without their permission', async () => {
      setup([], ALL, ['core'])
      renderApp('/')
      await screen.findAllByRole('link')
      await waitFor(() => expect(screen.queryByRole('link', { name: 'Front desk' })).not.toBeInTheDocument())
    })

    it('shows only the items the role holds', async () => {
      setup([], ['core.company.view', 'hotel.housekeeping.view', 'hotel.room.view'], ['core', 'hotel'])
      renderApp('/')
      expect(await screen.findByRole('link', { name: 'Housekeeping' })).toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Night audit' })).not.toBeInTheDocument()
      expect(screen.queryByRole('link', { name: 'Hotel settings' })).not.toBeInTheDocument()
    })
  })
})
