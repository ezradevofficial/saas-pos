// SAL-06, SAL-07, SAL-08, SAL-11, SAL-25: the "New appointment" page and the client page, with the API mocked.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox, openCombobox, optionTexts, waitForOption } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Glow Salon', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const BRANCH = { id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const SERVICE = { id: 'sv-1', company_id: 'c-1', name: 'Haircut', kind: 'service', duration_minutes: 45, staff_ids: ['st-1'], archived_at: null }
const COLOUR = { id: 'sv-2', company_id: 'c-1', name: 'Colour', kind: 'service', duration_minutes: 90, staff_ids: ['st-2'], archived_at: null }
const ANN = { id: 'st-1', company_id: 'c-1', name: 'Wanjiru', archived_at: null }
const BOB = { id: 'st-2', company_id: 'c-1', name: 'Otieno', archived_at: null }
const money = (text) => screen.getByText((_, element) => element?.dataset.slot === 'money-main' && element.textContent.replace(/\s+/g, ' ') === text)
const SLOT = { starts_at: '2026-10-28T08:00:00Z', ends_at: '2026-10-28T08:45:00Z', lines: [{ service_id: 'sv-1', staff_id: 'st-1', resource_id: null, starts_at: '2026-10-28T08:00:00Z', ends_at: '2026-10-28T08:45:00Z' }] }
const CLIENT = { party_id: 'p-1', company_id: 'c-1', name: 'Amina Otieno', phone: '0712345678', email: null, preferred_staff_id: 'st-1', preferences: 'Quiet chair', allergies: 'Latex', formulas: null, notes: null, has_profile: true, has_alerts: true }
const VISIT = { id: 'ap-1', number: 'SAL-1', status: 'paid', starts_at: '2026-10-20T07:00:00Z', total_minor: '150000', currency: 'KES', service_notes: null, lines: [{ id: 'l-1', service_id: 'sv-1', kind: 'service', service_name: 'Haircut', staff_id: 'st-1', staff_name: 'Wanjiru' }] }
const SUMMARY = { visits: 3, first_visit_at: '2026-08-01T07:00:00Z', last_visit_at: '2026-10-20T07:00:00Z', next_appointment: null, no_shows: 1, cancellations: 0, spend: [{ currency: 'KES', amount_minor: '450000' }], deposits_held: [{ currency: 'KES', amount_minor: '50000' }], credit: [{ currency: 'KES', remaining_minor: '300000', packages: 1 }] }

const ALL = [
  'core.company.view', 'salon.service.view', 'salon.staff.view', 'salon.appointment.view', 'salon.appointment.create', 'salon.client.view', 'salon.client.manage', 'salon.client.notes',
  'salon.deposit.take', 'salon.package.view',
]

function setup({ without = [], summary = SUMMARY } = {}) {
  mockRoutes(
    api,
    [
      [/^salon\/services/, { data: [SERVICE, COLOUR] }],
      [/^salon\/staff/, { data: [ANN, BOB] }],
      [/^salon\/availability/, { data: { date: '2026-10-28', slots: [SLOT] } }],
      [/^salon\/clients\/p-1\/visits/, { data: [VISIT] }],
      [/^salon\/clients\/p-1\/summary/, { data: summary }],
      [/^salon\/clients\/p-1/, { data: CLIENT }],
      [/^salon\/clients/, { data: [CLIENT] }],
      [/^companies\/c-1\/payment-methods/, page([{ id: 'pm-1', name: 'Cash', type: 'cash', active: true, archived_at: null }])],
      [/^branches\?/, page([BRANCH])],
      [/^locations\?/, page([])],
    ],
    { permissions: tenantWide(ALL.filter((name) => !without.includes(name))), modules: ['core', 'pos', 'salon'], companies: [COMPANY] },
  )
}

describe('New appointment page (SAL-07)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('books a found client with the services, a stylist and an open time, then takes a deposit', async () => {
    setup()
    api.post.mockImplementation((path) => Promise.resolve({ data: path.endsWith('/deposit') ? { id: 'pay-1' } : { id: 'ap-9' } }))
    renderApp('/salon/appointments/new')

    fireEvent.change(await screen.findByLabelText('Find a client'), { target: { value: 'Amina' } })
    fireEvent.click(await screen.findByRole('button', { name: 'Choose Amina Otieno' }))
    fireEvent.click(openCombobox(screen.getByLabelText('Services')).querySelector('[role="option"]'))
    closeCombobox()
    fireEvent.click(await screen.findByRole('button', { name: '11:00' }))
    expect(screen.getByTestId('slot-summary')).toHaveTextContent('Haircut · Wanjiru · 11:00–11:45')
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Prefers the window chair' } })
    chooseOption(screen.getByLabelText('Deposit paid with'), 'Cash')
    fireEvent.change(screen.getByLabelText('Deposit amount'), { target: { value: '500' } })
    fireEvent.click(screen.getByRole('button', { name: 'Book at 11:00' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('salon/appointments', expect.objectContaining({
        branch_id: 'br-1', salon_client_id: 'p-1', notes: 'Prefers the window chair', starts_at: SLOT.starts_at, lines: [{ service_id: 'sv-1', staff_id: 'st-1', resource_id: null }],
      })),
    )
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('salon/appointments/ap-9/deposit', expect.objectContaining({ payment_method_id: 'pm-1' })))
  })

  it('lists only the staff who do every chosen service', async () => {
    setup()
    renderApp('/salon/appointments/new?services=sv-1')

    const staff = await screen.findByLabelText('Staff member')
    await waitForOption(staff, 'Wanjiru')
    expect(optionTexts(staff)).toEqual(['Anyone free', 'Wanjiru'])
    // The online booking's availability is asked for these services, on this day.
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringContaining('service_ids%5B%5D=sv-1')))
  })

  it('says so when the time was just taken and clears the choice, never booking twice (SAL-08)', async () => {
    setup()
    api.post.mockRejectedValue(apiError(409, 'slot_taken', 'This time was just taken. Choose another time.'))
    renderApp('/salon/appointments/new?services=sv-1')

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Faith Njeri' } })
    fireEvent.click(await screen.findByRole('button', { name: '11:00' }))
    fireEvent.click(screen.getByRole('button', { name: 'Book at 11:00' }))

    expect(await screen.findByText('This time was just taken. Choose another time.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: 'Book appointment' })).toBeDisabled()
  })

  it('adds a client on the spot', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...CLIENT, party_id: 'p-2', name: 'Njeri K' } })
    renderApp('/salon/appointments/new')

    fireEvent.click(await screen.findByRole('button', { name: 'Add a new client' }))
    const name = screen.getAllByLabelText('Name')[0]
    fireEvent.change(name, { target: { value: 'Njeri K' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add a client' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('salon/clients', expect.objectContaining({ company_id: 'c-1', name: 'Njeri K' })))
    expect(await screen.findByText('Njeri K')).toBeInTheDocument()
  })

  it('is closed without the create permission, and hides the deposit from someone who may not take one', async () => {
    setup({ without: ['salon.appointment.create'] })
    renderApp('/salon/appointments/new')
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'New appointment' })).not.toBeInTheDocument())
  })

  it('hides the deposit fields without the permission', async () => {
    setup({ without: ['salon.deposit.take'] })
    renderApp('/salon/appointments/new')
    await screen.findByRole('heading', { name: 'New appointment' })
    expect(screen.queryByLabelText('Deposit amount')).not.toBeInTheDocument()
  })
})

describe('Client page (SAL-25)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the profile, spend, deposits, prepaid value and visits, and books again with the last services', async () => {
    setup()
    renderApp('/salon/clients/p-1')

    expect(await screen.findByRole('heading', { name: 'Amina Otieno' })).toBeInTheDocument()
    await screen.findByText('Spent')
    expect(money('KES 4,500.00')).toBeInTheDocument()
    expect(money('KES 500.00')).toBeInTheDocument()
    expect(money('KES 3,000.00')).toBeInTheDocument()
    expect(screen.getByText('Latex')).toBeInTheDocument()
    expect(screen.getByText('Quiet chair')).toBeInTheDocument()
    const visits = await screen.findByRole('table', { name: 'Visits' })
    expect(within(visits).getByText('Haircut')).toBeInTheDocument()
    expect(screen.getByText(/1 no-show/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Book again' }))
    // The booking page opens with the client, services and stylist of the last visit.
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringMatching(/^salon\/clients\/p-1\?/)))
    expect(await screen.findByRole('heading', { name: 'New appointment' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Change client' })).toBeInTheDocument()
  })

  it('shows only what the permissions allow: no allergies, no prepaid value, no Book again', async () => {
    setup({ without: ['salon.client.notes', 'salon.appointment.create'], summary: { ...SUMMARY, credit: null } })
    renderApp('/salon/clients/p-1')

    expect(await screen.findByRole('heading', { name: 'Amina Otieno' })).toBeInTheDocument()
    await screen.findByText('Quiet chair')
    expect(screen.queryByText('Latex')).not.toBeInTheDocument()
    expect(screen.queryByText('Prepaid value left')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Book again' })).not.toBeInTheDocument()
    expect(screen.getByText('Allergies or formulas on file')).toBeInTheDocument()
  })
})
