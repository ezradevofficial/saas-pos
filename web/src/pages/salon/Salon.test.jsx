// SAL-02, SAL-07, SAL-13, SAL-15, SAL-23: the service menu, the calendar (booking, a clash, check-in), commission
// batches (four eyes), the online booking settings and the public booking page, with the API mocked and answers shaped
// as docs/modules/salon.md gives them.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox, openCombobox } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Glow Salon', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const BRANCH = { id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })

const SERVICE = { id: 'sv-1', company_id: 'c-1', item_id: 'i-1', item_name: 'Haircut item', name: 'Haircut', category: 'Hair', kind: 'service', duration_minutes: 45, buffer_minutes: 5, online_bookable: true, staff_ids: [], resource_ids: [], addon_ids: [], products: [], price_minor: '150000', currency: 'KES', archived_at: null }
const STAFF = { id: 'st-1', company_id: 'c-1', branch_id: 'br-1', user_id: null, user_name: null, employee_id: null, name: 'Wanjiru', title: 'Stylist', color: 'c1', accepts_online: true, archived_at: null }

const LINE = { id: 'ln-1', service_id: 'sv-1', item_id: 'i-1', service_name: 'Haircut', kind: 'service', staff_id: 'st-1', staff_name: 'Wanjiru', resource_id: null, resource_name: null, starts_at: '2026-10-28T07:00:00Z', ends_at: '2026-10-28T07:45:00Z', price_minor: '150000', net_minor: null }
const APPOINTMENT = {
  id: 'ap-1', number: 'SAL-WL-2026-00001', company_id: 'c-1', branch_id: 'br-1', location_id: null, client_id: null, client_name: 'Amina Otieno', guest_phone: '0712345678', guest_email: null,
  source: 'staff', status: 'booked', starts_at: '2026-10-28T07:00:00Z', ends_at: '2026-10-28T07:45:00Z', total_minor: '150000', currency: 'KES', deposit_required_minor: '0', deposit_balance_minor: '0',
  deposit_hold_until: null, fee_minor: '0', fee_status: 'none', fee_reason: null, fee_waive_reason: null, notes: null, service_notes: null, checked_in_at: null, started_at: null, finished_at: null,
  cancelled_at: null, cancel_reason: null, sale_id: null, paid_at: null, lines: [LINE], payments: [],
}
const CALENDAR = {
  date: '2026-10-28', days: 1, timezone: 'Africa/Nairobi',
  staff: [{ id: 'st-1', name: 'Wanjiru', title: 'Stylist', color: 'c1', work: [['2026-10-28T06:00:00Z', '2026-10-28T14:00:00Z']], off: [['2026-10-28T09:00:00Z', '2026-10-28T10:00:00Z']] }],
  resources: [], appointments: [APPOINTMENT],
}
const SLOT = { starts_at: '2026-10-28T08:00:00Z', ends_at: '2026-10-28T08:45:00Z', lines: [{ service_id: 'sv-1', staff_id: 'st-1', staff_name: 'Wanjiru', resource_id: null, starts_at: '2026-10-28T08:00:00Z', ends_at: '2026-10-28T08:45:00Z' }] }

const BATCH_ROW = { id: 'b-1', company_id: 'c-1', number: 'SCB-2026-00001', period_start: '2026-10-01', period_end: '2026-10-31', currency: 'KES', status: 'submitted', total_minor: '450000', staff_count: 1, created_by: 'u-9', created_by_name: 'Peter', submitted_by_name: 'Peter', submitted_at: '2026-10-31T10:00:00Z', decided_by_name: null, decided_at: null, reject_reason: null, exported_at: null, export_mode: null }
const BATCH = { ...BATCH_ROW, payouts: [{ id: 'po-1', staff_id: 'st-1', staff_name: 'Wanjiru', employee_id: null, amount_minor: '450000', currency: 'KES', entry_count: 3, status: 'pending' }] }

const PAGE = { id: 'br-1', branch_id: 'br-1', company_id: 'c-1', location_id: null, enabled: true, public_name: 'Glow Westlands', lead_minutes: 60, max_days_ahead: 60, public_code: 'abc123abc123', path: '/book/abc123abc123' }
const SETTINGS = { company_id: 'c-1', slot_minutes: 15, cancel_window_hours: 24, late_cancel_fee_percent: '50', no_show_fee_percent: '100', deposit_percent: '20', deposit_online_required: false, hold_minutes: 30, reminder_hours: 24, guest_retention_days: 365, tip_item_id: null, fee_item_id: null, commission_item_code: 'COMM' }

const ALL = [
  'core.company.view', 'salon.service.view', 'salon.service.manage', 'salon.staff.view', 'salon.staff.manage', 'salon.resource.view', 'salon.resource.manage',
  'salon.appointment.view', 'salon.appointment.create', 'salon.appointment.edit', 'salon.appointment.cancel', 'salon.appointment.serve', 'salon.queue.view', 'salon.queue.manage',
  'salon.client.view', 'salon.client.manage', 'salon.client.notes', 'salon.commission.view', 'salon.commission.rule', 'salon.commission.build', 'salon.commission.approve', 'salon.commission.export',
  'salon.settings.view', 'salon.settings.edit', 'salon.online.view', 'salon.online.manage', 'salon.report.view',
]

function setup({ without = [], extra = [] } = {}) {
  mockRoutes(
    api,
    [
      ...extra,
      [/^salon\/services/, { data: [SERVICE] }],
      [/^salon\/package-types/, { data: [] }],
      [/^salon\/staff/, { data: [STAFF] }],
      [/^salon\/resources/, { data: [] }],
      [/^salon\/service-items/, { data: [{ id: 'i-2', code: 'COLOUR', name: 'Hair colour' }] }],
      [/^salon\/calendar/, { data: CALENDAR }],
      [/^salon\/availability/, { data: { date: '2026-10-28', slots: [SLOT] } }],
      [/^salon\/queue/, { data: [] }],
      ['salon/appointments/ap-1', { data: APPOINTMENT }],
      [/^salon\/clients/, { data: [] }],
      [/^salon\/commission-batches\?/, page([BATCH_ROW])],
      ['salon/commission-batches/b-1', { data: BATCH }],
      ['salon/companies/c-1/settings', { data: SETTINGS }],
      ['salon/branches/br-1/online', { data: PAGE }],
      [/^branches\?/, page([BRANCH])],
      [/^locations\?/, page([])],
      [/^items\?/, page([])],
      [/^users\?/, page([])],
    ],
    { permissions: tenantWide(ALL.filter((name) => !without.includes(name))), modules: ['core', 'pos', 'salon'], companies: [COMPANY] },
  )
}

describe('Salon', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  describe('service menu (SAL-02)', () => {
    it('lists services with their price and duration', async () => {
      setup()
      renderApp('/salon/services')
      expect(await screen.findByText('Haircut')).toBeInTheDocument()
      expect(screen.getByText('45 minutes')).toBeInTheDocument()
      expect(screen.getByText('1,500.00')).toBeInTheDocument()
    })

    it('adds a service from an item that is not on the menu', async () => {
      setup()
      api.post.mockResolvedValue({ data: { ...SERVICE, id: 'sv-2' } })
      renderApp('/salon/services')
      await screen.findByText('Haircut')
      fireEvent.click(screen.getByRole('button', { name: 'Add a service' }))
      const dialog = await screen.findByRole('dialog')
      await waitFor(() => expect(api.get).toHaveBeenCalledWith('salon/service-items?company_id=c-1'))
      await waitFor(() => chooseOption(within(dialog).getByLabelText('Item'), /Hair colour/))
      fireEvent.change(within(dialog).getByLabelText('Duration (minutes)'), { target: { value: '90' } })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Save service' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('salon/services', expect.objectContaining({ company_id: 'c-1', item_id: 'i-2', duration_minutes: 90 })))
    })

    it('hides setting up from a reader', async () => {
      setup({ without: ['salon.service.manage'] })
      renderApp('/salon/services')
      await screen.findByText('Haircut')
      expect(screen.queryByRole('button', { name: 'Add a service' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Edit Haircut' })).not.toBeInTheDocument()
    })
  })

  describe('calendar (SAL-07, SAL-13)', () => {
    it('shows each staff member with their hours and appointments', async () => {
      setup()
      renderApp('/salon/calendar')
      const column = await screen.findByRole('region', { name: 'Wanjiru' })
      expect(within(column).getByText('Works 09:00–17:00')).toBeInTheDocument()
      expect(within(column).getByText('Off 12:00–13:00')).toBeInTheDocument()
      expect(within(column).getByText('Amina Otieno')).toBeInTheDocument()
      expect(within(column).getByText('10:00–10:45')).toBeInTheDocument()
    })

    it('books an open time for a guest', async () => {
      setup()
      api.post.mockResolvedValue({ data: APPOINTMENT })
      renderApp('/salon/calendar')
      await screen.findByRole('region', { name: 'Wanjiru' })
      fireEvent.click(screen.getByRole('button', { name: 'Book with Wanjiru' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Faith Njeri' } })
      fireEvent.click(openCombobox(within(dialog).getByLabelText('Services')).querySelector('[role="option"]'))
      closeCombobox()
      fireEvent.click(await within(dialog).findByRole('button', { name: '11:00' }))
      fireEvent.click(within(dialog).getByRole('button', { name: 'Book at 11:00' }))
      await waitFor(() =>
        expect(api.post).toHaveBeenCalledWith('salon/appointments', expect.objectContaining({
          branch_id: 'br-1', guest_name: 'Faith Njeri', starts_at: SLOT.starts_at, lines: [{ service_id: 'sv-1', staff_id: 'st-1', resource_id: null }],
        })),
      )
    })

    it('says so when the time was just taken', async () => {
      setup()
      api.post.mockRejectedValue(apiError(409, 'slot_taken', 'This time was just taken. Choose another time.'))
      renderApp('/salon/calendar')
      await screen.findByRole('region', { name: 'Wanjiru' })
      fireEvent.click(screen.getByRole('button', { name: 'New booking' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Name'), { target: { value: 'Faith Njeri' } })
      fireEvent.click(openCombobox(within(dialog).getByLabelText('Services')).querySelector('[role="option"]'))
      closeCombobox()
      fireEvent.click(await within(dialog).findByRole('button', { name: '11:00' }))
      fireEvent.click(within(dialog).getByRole('button', { name: 'Book at 11:00' }))
      expect(await within(dialog).findByText('This time was just taken. Choose another time.')).toBeInTheDocument()
    })

    it('checks a client in', async () => {
      setup()
      api.post.mockResolvedValue({ data: { ...APPOINTMENT, status: 'checked_in' } })
      renderApp('/salon/calendar')
      const column = await screen.findByRole('region', { name: 'Wanjiru' })
      fireEvent.click(within(column).getByText('Haircut'))
      const dialog = await screen.findByRole('dialog', { name: /SAL-WL-2026-00001/ })
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Check in' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('salon/appointments/ap-1/check-in', {}))
    })
  })

  describe('reports (SAL-27)', () => {
    it('shows the utilisation of the staff on opening, whatever the other reports would need', async () => {
      setup({ extra: [[/^salon\/reports\/utilisation/, { data: { staff: [{ staff_id: 'st-1', staff_name: 'Wanjiru', branch_name: 'Main', available_minutes: 480, booked_minutes: 240, served_minutes: 180, utilisation_percent: 50 }] } }]] })
      renderApp('/salon/reports')
      expect(await screen.findByText('Wanjiru')).toBeInTheDocument()
    })
  })

  describe('commissions (SAL-23)', () => {
    it('hides approving from someone without the permission', async () => {
      setup({ without: ['salon.commission.approve'] })
      renderApp('/salon/commissions')
      fireEvent.click(await screen.findByRole('button', { name: 'Open batch SCB-2026-00001' }))
      const dialog = await screen.findByRole('dialog')
      await within(dialog).findByText('Wanjiru')
      expect(within(dialog).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    })

    it('words the batch period as dates in the user\u2019s language, not ISO text (L10N-01)', async () => {
      setup()
      renderApp('/salon/commissions')
      expect(await screen.findByText('1 Oct 2026 \u2013 31 Oct 2026')).toBeInTheDocument()
      fireEvent.click(await screen.findByRole('button', { name: 'Open batch SCB-2026-00001' }))
      const dialog = await screen.findByRole('dialog')
      expect(await within(dialog).findByText('Period 1 Oct 2026 to 31 Oct 2026')).toBeInTheDocument()
    })

    it('shows why the server refuses a self-approval', async () => {
      setup()
      api.post.mockRejectedValue(apiError(403, 'self_approval', 'You cannot decide a batch you built, submitted or are paid in. Ask someone else to do it.'))
      renderApp('/salon/commissions')
      fireEvent.click(await screen.findByRole('button', { name: 'Open batch SCB-2026-00001' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.click(await within(dialog).findByRole('button', { name: 'Approve' }))
      expect(await within(dialog).findByText(/You cannot decide a batch you built/)).toBeInTheDocument()
      expect(api.post).toHaveBeenCalledWith('salon/commission-batches/b-1/approve', {})
    })
  })

  describe('online booking settings (SAL-15)', () => {
    it('shows the public link and saves the page', async () => {
      setup()
      api.put.mockResolvedValue({ data: PAGE })
      renderApp('/salon/settings')
      const link = await screen.findByLabelText('Public link')
      expect(link).toHaveValue(`${window.location.origin}/book/abc123abc123`)
      fireEvent.change(screen.getByLabelText('Name shown'), { target: { value: 'Glow Karen' } })
      fireEvent.click(screen.getByRole('button', { name: 'Save booking page' }))
      await waitFor(() => expect(api.put).toHaveBeenCalledWith('salon/branches/br-1/online', expect.objectContaining({ enabled: true, public_name: 'Glow Karen', lead_minutes: 60 })))
    })

    it('regenerates the code', async () => {
      setup()
      api.post.mockResolvedValue({ data: { ...PAGE, public_code: 'zzz999zzz999', path: '/book/zzz999zzz999' } })
      renderApp('/salon/settings')
      await screen.findByLabelText('Public link')
      fireEvent.click(screen.getByRole('button', { name: 'New link' }))
      await waitFor(() => expect(api.post).toHaveBeenCalledWith('salon/branches/br-1/online/regenerate-code', {}))
      await waitFor(() => expect(screen.getByLabelText('Public link')).toHaveValue(`${window.location.origin}/book/zzz999zzz999`))
    })
  })

  describe('staff and clients (SAL-03, SAL-25)', () => {
    it('lists staff with their actions for a manager', async () => {
      setup()
      renderApp('/salon/staff')
      expect(await screen.findByText('Wanjiru')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Hours of Wanjiru' })).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Time off of Wanjiru' })).toBeInTheDocument()
    })

    it('shows allergies only to someone who may read client notes', async () => {
      const client = { party_id: 'p-1', company_id: 'c-1', name: 'Amina Otieno', phone: '0712345678', email: null, preferred_staff_id: null, preferences: 'Quiet', allergies: 'Latex', formulas: null, notes: null, has_profile: true, has_alerts: true }
      for (const [without, shown] of [[[], true], [['salon.client.notes'], false]]) {
        resetSession()
        signedIn()
        setup({ without })
        mockRoutes(api, [[/^salon\/clients\/p-1\/visits/, { data: [] }], [/^salon\/clients\/p-1/, { data: client }], [/^salon\/clients/, { data: [client] }], [/^salon\/staff/, { data: [STAFF] }]], {
          permissions: tenantWide(ALL.filter((name) => !without.includes(name))), modules: ['core', 'pos', 'salon'], companies: [COMPANY],
        })
        const { unmount } = renderApp('/salon/clients')
        fireEvent.click(await screen.findByRole('button', { name: 'Open Amina Otieno' }))
        const dialog = await screen.findByRole('dialog')
        await within(dialog).findByLabelText('Preferences')
        if (shown) expect(within(dialog).getByLabelText('Allergies')).toHaveValue('Latex')
        else expect(within(dialog).queryByLabelText('Allergies')).not.toBeInTheDocument()
        unmount()
      }
    })
  })

  describe('public booking page (SAL-15, SAL-26)', () => {
    const MENU = {
      name: 'Glow Westlands', currency: 'KES', timezone: 'Africa/Nairobi', lead_minutes: 60, max_days_ahead: 60, slot_minutes: 15, deposit_percent: '0', deposit_required: false, cancel_window_hours: 24,
      services: [{ id: 'sv-1', name: 'Haircut', category: 'Hair', kind: 'service', duration_minutes: 45, addon_ids: [], price_minor: '150000' }],
      staff: [{ id: 'st-1', name: 'Wanjiru', title: 'Stylist' }],
    }
    const json = (data, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(data) })

    it('books with a name and a phone, sending an idempotency key and no sign-in', async () => {
      const fetchMock = vi.fn((url, init = {}) => {
        if (init.method === 'POST') return json({ data: { number: 'SAL-WL-2026-00007', status: 'booked', starts_at: SLOT.starts_at, ends_at: SLOT.ends_at, services: ['Haircut'], currency: 'KES', total_minor: '150000', deposit_required_minor: '0', deposit_paid_minor: '0', deposit_hold_until: null } })
        if (String(url).includes('/slots')) return json({ data: [{ starts_at: SLOT.starts_at, ends_at: SLOT.ends_at }] })
        return json({ data: MENU })
      })
      vi.stubGlobal('fetch', fetchMock)
      try {
        renderApp('/book/abc123abc123')
        fireEvent.click(await screen.findByRole('checkbox', { name: /Haircut/ }))
        fireEvent.click(await screen.findByRole('button', { name: '11:00' }))
        fireEvent.change(await screen.findByLabelText('Your name'), { target: { value: 'Faith Njeri' } })
        fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '0712345678' } })
        fireEvent.click(screen.getByRole('button', { name: 'Book at 11:00' }))
        expect(await screen.findByText('Your appointment is booked')).toBeInTheDocument()
        expect(screen.getByText('Reference SAL-WL-2026-00007')).toBeInTheDocument()
        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
        expect(post[0]).toContain('/api/v1/salon-booking/abc123abc123/appointments')
        expect(post[1].headers['Idempotency-Key']).toBeTruthy()
        expect(post[1].headers.Authorization).toBeUndefined()
        expect(JSON.parse(post[1].body)).toEqual(expect.objectContaining({ guest_name: 'Faith Njeri', guest_phone: '0712345678', service_ids: ['sv-1'], starts_at: SLOT.starts_at }))
        expect(post[1].credentials).toBe('omit')
      } finally {
        vi.unstubAllGlobals()
      }
    })

    it('says so when the page is not available', async () => {
      vi.stubGlobal('fetch', vi.fn(() => json({ message: 'Not found.' }, 404)))
      try {
        renderApp('/book/abc123abc123')
        expect(await screen.findByText('This booking page is not available.')).toBeInTheDocument()
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })
})
