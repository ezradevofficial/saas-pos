import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { apiError, mockApi, renderApp, resetSession } from '@/test/renderApp'
import { chooseOption } from '@/test/combobox'
import { platformApi, setPlatformToken } from './platformClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

vi.mock('./platformClient', async (importOriginal) => ({
  ...(await importOriginal()),
  platformApi: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))

const staff = (role, abilities) => ({ id: 's-1', name: 'Wanjiru Kamau', email: 'wanjiru@example.com', role, status: 'active', two_factor_enabled: true, abilities })

function mockConsole(user, { sms = true } = {}) {
  platformApi.get.mockImplementation(async (path) => {
    if (path === 'platform/me') return { data: user }
    if (path === 'public/branding') return { data: null, meta: { sms_available: sms } }
    if (path.startsWith('catalogue?country=')) {
      return { data: { country: 'CD', currency: 'USD', modules: [{ key: 'pos', kind: 'module', name: 'Point of sale' }, { key: 'inventory', kind: 'module', name: 'Inventory' }], bundles: [] } }
    }
    throw apiError(404, 'not_found', 'Not found.')
  })
}

const fill = (label, value) => fireEvent.change(screen.getByLabelText(label, { exact: false }), { target: { value } })

describe('Platform: new tenant (ONB-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api)
    setPlatformToken('plat_x|y')
  })
  afterEach(() => setPlatformToken(null))

  it('asks for the contact’s email address when text messages aren’t available (AUTH-05)', async () => {
    mockConsole(staff('support', ['platform.catalogue.view', 'platform.tenants.create']), { sms: false })
    renderApp('/platform/tenants/new')

    const field = await screen.findByLabelText('Contact email address', { exact: false })
    expect(field).toHaveAttribute('type', 'email')
    expect(screen.queryByLabelText('Contact email or phone number', { exact: false })).not.toBeInTheDocument()
    // Asked with the console's own client: no tenant token goes out (ADR 013).
    expect(api.get).not.toHaveBeenCalledWith('public/branding')
  })

  it('creates a tenant for a customer and says where the invitation went', async () => {
    mockConsole(staff('support', ['platform.catalogue.view', 'platform.tenants.create']))
    platformApi.post.mockResolvedValue({ data: { tenant_id: 't-9', slug: 'quincaillerie-lumiere', invitation_id: 'i-1', destination_masked: 'j***@example.com' } })
    renderApp('/platform/tenants/new')

    expect(await screen.findByRole('heading', { level: 1, name: 'New tenant' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'New tenant' })).toBeInTheDocument()
    fill('Business name', 'Quincaillerie Lumière')
    chooseOption('Country', 'DR Congo')
    chooseOption('Business type', 'Pharmacy or agrovet')
    fill('Contact name', 'Jeanne Mbuyi')
    fill('Contact email or phone number', 'jeanne@example.com')
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))

    expect(await screen.findByText('The invitation went to j***@example.com. Subdomain: quincaillerie-lumiere.')).toBeInTheDocument()
    // A link to the new tenant, and a clean form for the next customer.
    expect(screen.getByRole('link', { name: 'Open quincaillerie-lumiere' })).toHaveAttribute('href', '/platform/tenants/t-9')
    expect(screen.getByLabelText('Business name', { exact: false })).toHaveValue('')
    expect(screen.getByLabelText('Contact name', { exact: false })).toHaveValue('')
    expect(platformApi.post).toHaveBeenCalledWith('platform/tenants', expect.objectContaining({
      business_name: 'Quincaillerie Lumière',
      country: 'CD',
      business_type: 'pharmacy',
      contact_name: 'Jeanne Mbuyi',
      email: 'jeanne@example.com',
      modules: ['pos', 'inventory', 'loyalty'],
    }))
  })

  it('disables Create tenant while the request is pending', async () => {
    mockConsole(staff('support', ['platform.catalogue.view', 'platform.tenants.create']))
    platformApi.post.mockReturnValue(new Promise(() => {}))
    renderApp('/platform/tenants/new')

    await screen.findByRole('heading', { level: 1, name: 'New tenant' })
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    await waitFor(() => expect(screen.getByRole('button', { name: /Create tenant/ })).toBeDisabled())
  })

  it('shows field errors from the API and hides the link from staff who cannot create tenants', async () => {
    mockConsole(staff('finance', ['platform.catalogue.view', 'platform.catalogue.manage']))
    platformApi.post.mockRejectedValue(apiError(422, 'validation_failed', 'Check the form.', { email: ['This email is already taken.'] }))
    renderApp('/platform/tenants/new')

    await screen.findByRole('heading', { level: 1, name: 'New tenant' })
    expect(screen.queryByRole('link', { name: 'New tenant' })).not.toBeInTheDocument()
    fill('Contact email or phone number', 'taken@example.com')
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    await waitFor(() => expect(screen.getByText('This email is already taken.')).toBeInTheDocument())
  })
})
