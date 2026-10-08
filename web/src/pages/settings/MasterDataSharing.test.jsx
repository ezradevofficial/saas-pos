import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { apiError, CD_COMPANY, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const OTHER = { ...CD_COMPANY, id: 'c-2', name: 'Lubumbashi Trading' }
const SETTINGS = [
  { data_type: 'items', mode: 'shared', changed_at: null },
  { data_type: 'customers', mode: 'per_company', changed_at: '2026-10-01T10:00:00+00:00' },
  { data_type: 'suppliers', mode: 'shared', changed_at: null },
  { data_type: 'employees', mode: 'shared', changed_at: null },
]

function sharing(permissions = tenantWide(['core.company.view', 'core.master_data_settings.edit'])) {
  mockRoutes(api, [['master-data/settings', { data: SETTINGS }]], { permissions, companies: [CD_COMPANY, OTHER] })
}

const card = (name) => screen.getByRole('heading', { name }).closest('[data-slot="card"]')

describe('MasterDataSharing', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('shows the mode of each data type and what it means', async () => {
    sharing()
    renderApp('/settings/sharing')
    await screen.findByRole('heading', { name: 'Customers' })
    expect(within(card('Items')).getByText('Shared across companies')).toBeInTheDocument()
    expect(within(card('Customers')).getByText('Per company')).toBeInTheDocument()
    expect(within(card('Customers')).getByText('Changed on 1 Oct 2026')).toBeInTheDocument()
  })

  it('asks for the company that receives records with none, after the API says some need one', async () => {
    sharing()
    api.put
      .mockRejectedValueOnce(Object.assign(apiError(422, 'records_need_company', '12 records have no company.'), { data: { count: 12 } }))
      .mockResolvedValueOnce({ data: SETTINGS.map((s) => (s.data_type === 'items' ? { ...s, mode: 'per_company' } : s)), meta: { assigned: 12, released: 0 } })
    renderApp('/settings/sharing')

    fireEvent.click(await screen.findByRole('button', { name: 'Change sharing for Items' }))
    const dialog = await screen.findByRole('dialog', { name: 'Keep items per company?' })
    expect(within(dialog).getByText(/Records with no company yet go to the company you choose/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Keep per company' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('master-data/settings', { data_type: 'items', mode: 'per_company' }))
    expect(await within(dialog).findByText('12 records have no company. Choose the company that receives them, then try again.')).toBeInTheDocument()

    chooseOption(within(dialog).getByLabelText(/Company that receives/), 'Lubumbashi Trading')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Keep per company' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenLastCalledWith('master-data/settings', { data_type: 'items', mode: 'per_company', assign_to_company_id: 'c-2' }),
    )
    expect(await screen.findByText('Items are now kept per company. 12 records went to Lubumbashi Trading.')).toBeInTheDocument()
  })

  it('needs an explicit confirmation before sharing per-company records', async () => {
    sharing()
    api.put.mockResolvedValue({ data: SETTINGS, meta: { assigned: 0, released: 3 } })
    renderApp('/settings/sharing')

    fireEvent.click(await screen.findByRole('button', { name: 'Change sharing for Customers' }))
    const dialog = await screen.findByRole('dialog', { name: 'Share customers across companies?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Share across companies' }))
    expect(await within(dialog).findByText('Tick the box to confirm.')).toBeInTheDocument()
    expect(api.put).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByLabelText(/I understand/))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Share across companies' }))
    await waitFor(() => expect(api.put).toHaveBeenCalledWith('master-data/settings', { data_type: 'customers', mode: 'shared', confirm: true }))
  })

  it('names duplicate codes that block a switch', async () => {
    sharing()
    api.put.mockRejectedValue(Object.assign(apiError(422, 'duplicate_codes', 'Codes collide.'), { data: { codes: ['SKU-1'], barcodes: ['6001234'] } }))
    renderApp('/settings/sharing')

    fireEvent.click(await screen.findByRole('button', { name: 'Change sharing for Items' }))
    const dialog = await screen.findByRole('dialog')
    chooseOption(within(dialog).getByLabelText(/Company that receives/), 'Kin Market')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Keep per company' }))
    expect(await within(dialog).findByText('More than one company uses these codes: SKU-1, 6001234. Change them so each is unique, then try again.')).toBeInTheDocument()
  })
})
