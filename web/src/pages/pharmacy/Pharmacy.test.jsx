// PHA-05 to PHA-26: the pharmacy back office: stock by lot and receiving, quarantine, write-offs,
// recalls and who bought the batch, prescriptions and their images, the controlled-drug register
// and its print, the dispenses to review, and the settings.
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, waitForOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const COMPANY = { id: 'c-1', name: 'Amani Pharmacy', country: 'KE', base_currency: 'KES', timezone: 'Africa/Nairobi', archived_at: null }
const named = (id, name) => ({ id, name })
const page = (data) => ({ data, meta: { total: data.length, last_page: 1, from: 1, to: data.length } })
const ITEM = { id: 'i-1', code: 'AMOX', name: 'Amoxicillin 500 mg', type: 'stock', base_uom_id: 'u-ea', uoms: [] }
const LOCATIONS = [{ id: 'l-1', name: 'Front pharmacy', branch_id: 'br-1', branch: named('br-1', 'Westlands'), archived_at: null }]

const ROW = (over = {}) => ({
  id: 'b-1', lot_id: 'lot-1', item: { id: 'i-1', code: 'AMOX', name: 'Amoxicillin 500 mg' }, location: named('l-1', 'Front pharmacy'),
  batch_number: 'B-100', manufactured_on: '2026-01-10', expires_on: '2026-12-31', days_left: 64, state: 'near_expiry', qty: '12', ...over,
})
const LOT = {
  id: 'lot-1', item: { id: 'i-1', code: 'AMOX', name: 'Amoxicillin 500 mg' }, company: named('c-1', 'Amani Pharmacy'), batch_number: 'B-100', manufactured_on: '2026-01-10', expires_on: '2026-12-31',
  days_left: 64, state: 'active', status: 'active', quarantine_reason: null, quarantined_at: null, recall_id: null, supplier: null, balances: [{ location: named('l-1', 'Front pharmacy'), qty: '12' }],
}
const RECALL = { id: 'r-1', item: { id: 'i-1', code: 'AMOX', name: 'Amoxicillin 500 mg' }, batch_number: 'B-100', reference: 'REF-7', reason: 'Mislabelled', status: 'open', lots_count: 1, issued_by: named('u-1', 'Amina'), issued_at: '2026-10-20T10:00:00Z', lifted_by: null, lifted_at: null, lift_reason: null }
const PRESCRIPTION = {
  id: 'p-1', status: 'valid', source: 'office', company: named('c-1', 'Amani Pharmacy'), branch: named('br-1', 'Westlands'), location: named('l-1', 'Front pharmacy'),
  prescriber_name: 'Dr Wanjiru', prescriber_licence: 'LIC-9', facility: 'Westlands Clinic', patient_name: 'Pat <b>Patient</b>', patient_phone: null, patient_birth_date: null, patient: null,
  prescribed_on: '2026-10-20', notes: null, sale_id: null, image: { name: 'rx.jpg', mime: 'image/jpeg', size: 1200 }, cancelled_at: null, cancel_reason: null, created_by: named('u-1', 'Amina'), created_at: '2026-10-20T10:00:00Z',
}
const DISPENSE = {
  id: 'd-1', sale_id: 's-1', receipt_number: 'R-0042', item: { id: 'i-1', code: 'AMOX', name: 'Amoxicillin 500 mg' }, class: 'prescription_only', qty: '2', location: named('l-1', 'Front pharmacy'), customer: null, prescription: null,
  pharmacist: null, cashier: named('u-2', 'Cora'), approval_mode: 'none', flags: ['prescription_missing', 'pharmacist_missing'], review_status: 'open', review_note: null, reviewed_at: null, dispensed_at: '2026-10-21T08:00:00Z',
}
const ENTRY = { id: 'e-1', entry_no: 1, kind: 'receipt', item: { id: 'i-9', code: 'MORPH', name: 'Morphine 10 mg' }, location: named('l-1', 'Front pharmacy'), qty: '10', balance: '10', batch_number: 'M-1', expires_on: '2027-01-01', prescription_id: null, prescriber_name: null, prescriber_licence: null, patient_name: null, pharmacist: null, recorded_by: named('u-1', 'Amina'), source_type: 'pharmacy.receipt', reason: null, occurred_at: '2026-10-20T10:00:00Z' }

const ALL = [
  'core.company.view', 'pharmacy.lot.view', 'pharmacy.lot.receive', 'pharmacy.lot.transfer', 'pharmacy.lot.quarantine', 'pharmacy.writeoff.view', 'pharmacy.writeoff.create', 'pharmacy.writeoff.cancel',
  'pharmacy.recall.view', 'pharmacy.recall.issue', 'pharmacy.recall.lift', 'pharmacy.prescription.view', 'pharmacy.prescription.create', 'pharmacy.prescription.cancel', 'pharmacy.prescription.image',
  'pharmacy.register.view', 'pharmacy.register.print', 'pharmacy.register.adjust', 'pharmacy.report.view', 'pharmacy.dispense.review', 'pharmacy.settings.view', 'pharmacy.settings.edit', 'pharmacy.item.view', 'pharmacy.item.configure',
]

function setup(routes = [], permissions = ALL) {
  mockRoutes(
    api,
    [
      ...routes,
      [/^pharmacy\/lots\?/, page([ROW(), ROW({ id: 'b-2', lot_id: 'lot-2', batch_number: 'B-OLD', state: 'expired', days_left: -3, expires_on: '2026-10-20', qty: '4' })])],
      ['pharmacy/lots/lot-1', { data: LOT }],
      [/^pharmacy\/recalls\?/, page([RECALL])],
      ['pharmacy/recalls/r-1', { data: RECALL }],
      ['pharmacy/recalls/r-1/customers', { data: { customers: [{ customer: { id: 'pt-1', name: 'Amina Customer', phone: '+254700000001' }, sale_id: 's-1', receipt_number: 'R-0042', location_id: 'l-1', qty: '3', sold_at: '2026-10-19T10:00:00Z' }], unattributed: { sales: 2, qty: '5' } } }],
      [/^pharmacy\/prescriptions\?/, page([PRESCRIPTION])],
      ['pharmacy/prescriptions/p-1', { data: PRESCRIPTION }],
      ['pharmacy/prescriptions/p-1/image-link', { data: { url: 'https://api.example.test/pharmacy-files/x?signature=1' } }],
      [/^pharmacy\/items\?/, page([{ item: { id: 'i-9', code: 'MORPH', name: 'Morphine 10 mg' }, configured: true, class: 'controlled', product_kind: 'human', batch_tracked: true, restricted_sale: false, usage_notes: null, schedule_note: null, withdrawal_days: null }])],
      [/^pharmacy\/register\?/, page([ENTRY])],
      [/^pharmacy\/dispenses\?/, page([DISPENSE])],
      [/^pharmacy\/reports\/prescriptions\?/, page([])],
      [/^pharmacy\/reports\/expiry\?/, page([ROW()])],
      ['companies/c-1/pharmacy-settings', { data: { company_id: 'c-1', configured: false, near_expiry_days: 90, min_sale_days: 0, prescription_classes: ['prescription_only', 'controlled'], pharmacist_classes: ['prescription_only', 'controlled', 'poison'], prescription_valid_days: null, quarantine_expired: true } }],
      [/^items\?/, page([ITEM])],
      [/^uoms\?/, page([{ id: 'u-ea', code: 'EA', name: 'Each', kind: 'count' }])],
      [/^companies\?/, { data: [COMPANY] }],
      [/^branches\?/, { data: [{ id: 'br-1', name: 'Westlands', company_id: 'c-1', archived_at: null }] }],
      [/^locations\?/, { data: LOCATIONS }],
      [/^document-workflows\//, new Error('none')],
    ],
    { permissions: tenantWide(permissions), modules: ['core', 'pharmacy'], companies: [COMPANY] },
  )
}

describe('Pharmacy back office', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists stock by lot with the state as a dot and a word, and offers receiving and moving to those who may (PHA-05, PHA-09)', async () => {
    setup()
    renderApp('/pharmacy/lots')
    const near = (await screen.findByText('B-100')).closest('tr')
    expect(within(near).getByText('Expires in 64 days').closest('[data-tone]')).toHaveAttribute('data-tone', 'warning')
    const old = screen.getByText('B-OLD').closest('tr')
    expect(within(old).getByText('Expired').closest('[data-tone]')).toHaveAttribute('data-tone', 'danger')
    expect(screen.getByRole('button', { name: 'Receive stock' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move stock' })).toBeInTheDocument()
  })

  it('hides receiving and moving from those without the permission', async () => {
    setup([], ['core.company.view', 'pharmacy.lot.view'])
    renderApp('/pharmacy/lots')
    await screen.findByText('B-100')
    expect(screen.queryByRole('button', { name: 'Receive stock' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Move stock' })).not.toBeInTheDocument()
  })

  it('receives a lot with its batch, expiry and cost, and sends it as the API takes it (PHA-06)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { id: 'rc-1' } })
    const { router } = renderApp('/pharmacy/receive')
    await waitForOption(await screen.findByLabelText(/^Location/), 'Front pharmacy · Westlands')
    chooseOption(screen.getByLabelText(/^Location/), 'Front pharmacy · Westlands')
    await waitForOption(screen.getByLabelText(/^Item/), 'Amoxicillin 500 mg · AMOX')
    chooseOption(screen.getByLabelText(/^Item/), 'Amoxicillin 500 mg · AMOX')
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '24' } })
    fireEvent.change(screen.getByLabelText(/^Batch number/), { target: { value: 'B-200' } })
    fireEvent.change(screen.getByLabelText(/^Expiry date/), { target: { value: '2027-06-30' } })
    fireEvent.change(screen.getByLabelText(/^Manufacture date/), { target: { value: '2026-06-01' } })
    fireEvent.change(screen.getByLabelText(/^Unit cost/), { target: { value: '15.50' } })
    fireEvent.click(screen.getByRole('button', { name: 'Receive stock' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('pharmacy/receipts', {
        location_id: 'l-1',
        lines: [{ item_id: 'i-1', qty: '24', batch_number: 'B-200', expires_on: '2027-06-30', manufactured_on: '2026-06-01', unit_cost_minor: '1550', currency: 'KES' }],
      }),
    )
    await waitFor(() => expect(router.state.location.pathname).toBe('/pharmacy/lots'))
  })

  it('asks for a reason before quarantining a lot (PHA-11)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...LOT, status: 'quarantined', state: 'quarantined' } })
    renderApp('/pharmacy/lots/lot-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Quarantine' }))
    const dialog = await screen.findByRole('dialog', { name: 'Quarantine this lot' })
    const confirm = within(dialog).getByRole('button', { name: 'Quarantine' })
    expect(confirm).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Torn packaging' } })
    fireEvent.click(confirm)
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pharmacy/lots/lot-1/quarantine', { reason: 'Torn packaging' }))
  })

  it('writes off lots of one location, offering the lots with stock there (PHA-12)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { id: 'w-1', number: 'WO-WL-2026-00001' } })
    renderApp('/pharmacy/write-offs/new')
    await waitForOption(await screen.findByLabelText(/^Location/), 'Front pharmacy · Westlands')
    chooseOption(screen.getByLabelText(/^Location/), 'Front pharmacy · Westlands')
    chooseOption(screen.getByLabelText(/^Reason/), 'Expired')
    await waitFor(() => expect(api.get).toHaveBeenCalledWith(expect.stringContaining('pharmacy/lots?location=l-1')))
    const lot = screen.getByRole('combobox', { name: /^Lot/ })
    await waitForOption(lot, /B-OLD/)
    chooseOption(lot, /B-OLD/)
    fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '4' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send for approval' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pharmacy/write-offs', { location_id: 'l-1', reason: 'expired', lines: [{ lot_id: 'lot-2', qty: '4' }] }))
  })

  it('shows who bought a recalled batch and lifts the recall with a reason (PHA-22, PHA-23)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...RECALL, status: 'lifted' } })
    renderApp('/pharmacy/recalls/r-1')
    const row = (await screen.findByText('Amina Customer')).closest('tr')
    expect(within(row).getByText('R-0042')).toBeInTheDocument()
    expect(screen.getByText('2 sales (5 units) had no named customer.')).toBeInTheDocument()
    expect(screen.getByText('This recall blocks 1 lot.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Lift recall' }))
    const dialog = await screen.findByRole('dialog', { name: 'Lift this recall' })
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'The maker withdrew it' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Lift recall' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pharmacy/recalls/r-1/lift', { reason: 'The maker withdrew it' }))
  })

  it('opens a prescription image through a signed link, and shows patient text as text (PHA-15, PHA-28)', async () => {
    setup()
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    renderApp('/pharmacy/prescriptions/p-1')
    // A name with markup is shown as typed, never as markup.
    expect((await screen.findAllByText('Pat <b>Patient</b>')).length).toBeGreaterThan(0)
    expect(document.querySelector('b')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Open the image' }))
    await waitFor(() => expect(open).toHaveBeenCalledWith('https://api.example.test/pharmacy-files/x?signature=1', '_blank', 'noopener'))
    open.mockRestore()
  })

  it('uploads a prescription image as a file (PHA-15)', async () => {
    setup()
    api.upload.mockResolvedValue({ data: PRESCRIPTION })
    renderApp('/pharmacy/prescriptions/p-1')
    const input = await screen.findByLabelText('Choose an image or PDF')
    fireEvent.change(input, { target: { files: [new File(['x'], 'scan.png', { type: 'image/png' })] } })
    await waitFor(() => expect(api.upload).toHaveBeenCalledWith('pharmacy/prescriptions/p-1/image', expect.any(FormData)))
  })

  it('prints the register of one item at one place in a sandboxed frame, every value escaped (PHA-19)', async () => {
    setup([
      [
        /^pharmacy\/register\/print\?/,
        {
          data: {
            item: { id: 'i-9', code: 'MORPH', name: 'Morphine 10 mg' }, location: named('l-1', 'Front pharmacy'), from: null, to: null, opening_balance: '0', closing_balance: '7', printed_at: '2026-10-22T10:00:00Z', printed_by: named('u-1', 'Amina'),
            entries: [{ ...ENTRY, kind: 'dispense', qty: '-3', balance: '7', patient_name: '<script>alert(1)</script>', prescriber_name: 'Dr Wanjiru', prescriber_licence: 'LIC-9' }],
          },
        },
      ],
    ])
    renderApp('/pharmacy/register?item_id=i-9&location=l-1')
    await screen.findByText('Morphine 10 mg')
    fireEvent.click(screen.getByRole('button', { name: 'Print' }))
    const frame = await waitFor(() => {
      const found = document.querySelector('iframe[sandbox]')
      expect(found).not.toBeNull()
      return found
    })
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts allow-modals')
    const html = frame.getAttribute('srcdoc')
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html).toContain('Closing balance')
  })

  it('adds a correction as a new entry with a reason, never an edit (PHA-18)', async () => {
    setup()
    api.post.mockResolvedValue({ data: ENTRY })
    renderApp('/pharmacy/register?item_id=i-9&location=l-1')
    fireEvent.click(await screen.findByRole('button', { name: 'Add correction' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a correction' })
    fireEvent.change(within(dialog).getByLabelText('Quantity to add or remove'), { target: { value: '-2' } })
    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Broken ampoule' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add correction' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pharmacy/register/adjustments', { item_id: 'i-9', location_id: 'l-1', qty: '-2', reason: 'Broken ampoule' }))
  })

  it('lists flagged dispenses and closes one with a note (PHA-26)', async () => {
    setup()
    api.post.mockResolvedValue({ data: { ...DISPENSE, review_status: 'reviewed' } })
    renderApp('/pharmacy/dispenses')
    const row = (await screen.findByText('R-0042')).closest('tr')
    expect(within(row).getByText('No prescription')).toBeInTheDocument()
    expect(within(row).getByText('No pharmacist approval')).toBeInTheDocument()
    fireEvent.click(within(row).getByRole('button', { name: 'Review' }))
    const dialog = await screen.findByRole('dialog', { name: 'Review receipt R-0042' })
    fireEvent.change(within(dialog).getByLabelText('What you checked'), { target: { value: 'Prescription came back' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Close review' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('pharmacy/dispenses/d-1/review', { note: 'Prescription came back' }))
  })

  it('saves the company settings as the API takes them, with no number taken from a regulator (PHA-02)', async () => {
    setup()
    api.put.mockResolvedValue({ data: { company_id: 'c-1', configured: true, near_expiry_days: 120, min_sale_days: 0, prescription_classes: ['prescription_only', 'controlled'], pharmacist_classes: ['prescription_only', 'controlled', 'poison'], prescription_valid_days: 30, quarantine_expired: true } })
    renderApp('/pharmacy/settings')
    expect(await screen.findByText(/none is taken from a regulator/)).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Near-expiry window/), { target: { value: '120' } })
    fireEvent.change(screen.getByLabelText(/^Days a prescription stays valid/), { target: { value: '30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('companies/c-1/pharmacy-settings', {
        near_expiry_days: 120, min_sale_days: 0, prescription_classes: ['prescription_only', 'controlled'], pharmacist_classes: ['prescription_only', 'controlled', 'poison'], prescription_valid_days: 30, quarantine_expired: true,
      }),
    )
  })

  it('shows the expiry report with the same lot states (PHA-10, PHA-24)', async () => {
    setup()
    renderApp('/pharmacy/reports/expiry')
    const row = (await screen.findByText('B-100')).closest('tr')
    expect(within(row).getByText('Expires in 64 days')).toBeInTheDocument()
  })
})
