import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { chooseOption, closeCombobox } from '@/test/combobox'
import { apiError, mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const META = {
  data: {
    entities: [
      { key: 'item', label: 'Items' },
      { key: 'party', label: 'Customers and suppliers' },
    ],
    types: ['text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'file', 'lookup', 'formula'],
    lookup_targets: [
      { key: 'item', label: 'Item' },
      { key: 'party', label: 'Customer or supplier' },
    ],
    formula: { functions: ['if', 'round', 'concat'], operators: ['+', '-', '*', '/'] },
  },
}
const SHELF_LIFE = {
  id: 'cf-1',
  entity: 'item',
  key: 'shelf_life_days',
  type: 'number',
  label: 'Shelf life (days)',
  help: null,
  default: null,
  required: true,
  unique: false,
  min: '0',
  max: null,
  pattern: null,
  options: [],
  lookup_target: null,
  formula: null,
  formula_type: null,
  visible_roles: [],
  editable_roles: [],
  show_on_pos: false,
  position: 1,
  archived_at: null,
  created_at: '2026-10-01T08:00:00Z',
  updated_at: '2026-10-01T08:00:00Z',
}
const ROLES = [
  { id: 'r-cashier', name: 'Cashier' },
  { id: 'r-manager', name: 'Store manager' },
]
const VIEW = ['core.custom_field.view']
const MANAGE = ['core.custom_field.view', 'core.custom_field.manage']
const listCalls = () => api.get.mock.calls.map(([path]) => path).filter((path) => /^custom-fields\?/.test(path) && !path.includes('per_page=100'))

function setUp(permissions = MANAGE) {
  mockRoutes(
    api,
    [
      ['custom-fields/meta', META],
      [/^custom-fields\?/, { data: [SHELF_LIFE], meta: { total: 1, from: 1, to: 1, last_page: 1 } }],
      ['roles?per_page=200', { data: ROLES }],
    ],
    { permissions: tenantWide(['core.company.view', 'core.role.view', ...permissions]) },
  )
}

describe('Custom fields settings (CF-01, CF-03, RBAC-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists an entity’s fields in order, switches entity and filters on the server', async () => {
    setUp()
    renderApp('/settings/custom-fields')
    const table = await screen.findByRole('table', { name: 'Custom fields · Items' })
    const row = (await within(table).findByText('Shelf life (days)')).closest('tr')
    expect(within(row).getByText('shelf_life_days')).toBeInTheDocument()
    expect(within(row).getByText('Number')).toBeInTheDocument()
    expect(listCalls()[0]).toBe('custom-fields?entity=item&status=active&sort=position&per_page=25&page=1')

    fireEvent.mouseDown(screen.getByRole('tab', { name: 'Customers and suppliers' }))
    await waitFor(() => expect(listCalls().at(-1)).toBe('custom-fields?entity=party&status=active&sort=position&per_page=25&page=1'))
  })

  it('adds a choice field with its options in order and the roles who see it', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...SHELF_LIFE, id: 'cf-2' } })
    renderApp('/settings/custom-fields')
    await screen.findByText('Shelf life (days)')
    fireEvent.click(screen.getByRole('button', { name: 'Add field' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a custom field' })

    fireEvent.change(within(dialog).getByLabelText(/^Label/), { target: { value: 'Colour' } })
    // The key follows the label until it is typed.
    expect(within(dialog).getByLabelText(/^Key/)).toHaveValue('colour')
    chooseOption(within(dialog).getByLabelText(/^Type/), 'One choice')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Add choice' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add choice' }))
    const labels = within(dialog).getAllByLabelText('Choice label')
    fireEvent.change(labels[0], { target: { value: 'Red' } })
    fireEvent.change(labels[1], { target: { value: 'Dark blue' } })
    expect(within(dialog).getAllByLabelText('Value')[1]).toHaveValue('dark_blue')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Move Dark blue up' }))

    // Several roles can be ticked in a row; Escape closes the picker.
    chooseOption(within(dialog).getByLabelText(/^Who can see it/), 'Cashier')
    closeCombobox()
    expect(within(dialog).getByLabelText(/^Who can see it/)).toHaveTextContent('Cashier')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add field' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('custom-fields', {
        entity: 'item',
        key: 'colour',
        type: 'select',
        label: 'Colour',
        help: null,
        required: false,
        options: [
          { value: 'dark_blue', label: 'Dark blue' },
          { value: 'red', label: 'Red' },
        ],
        default: null,
        visible_roles: ['r-cashier'],
        editable_roles: [],
        show_on_pos: false,
      }),
    )
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Add a custom field' })).not.toBeInTheDocument())
  })

  it('inserts field keys and operators into a formula from the help panel', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...SHELF_LIFE, id: 'cf-3' } })
    renderApp('/settings/custom-fields')
    await screen.findByText('Shelf life (days)')
    fireEvent.click(screen.getByRole('button', { name: 'Add field' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a custom field' })
    fireEvent.change(within(dialog).getByLabelText(/^Label/), { target: { value: 'Shelf weeks' } })
    chooseOption(within(dialog).getByLabelText(/^Type/), 'Formula')

    fireEvent.click(await within(dialog).findByRole('button', { name: 'Insert shelf_life_days' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Insert /' }))
    const formula = within(dialog).getByRole('textbox', { name: /^Formula/ })
    expect(formula).toHaveValue('shelf_life_days / ')
    fireEvent.change(formula, { target: { value: 'shelf_life_days / 7' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add field' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('custom-fields', expect.objectContaining({ key: 'shelf_weeks', type: 'formula', formula: 'shelf_life_days / 7', formula_type: 'number' })),
    )
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('default')
  })

  it('edits a field without sending its key, type or entity, and shows a refused value under its field', async () => {
    setUp()
    api.patch.mockRejectedValueOnce(apiError(422, 'validation_failed', 'Some fields need attention.', { label: ['Another field has this label.'] }))
    renderApp('/settings/custom-fields')
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Shelf life (days)' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit Shelf life (days)' })
    expect(within(dialog).getByLabelText(/^Key/)).toBeDisabled()
    fireEvent.change(within(dialog).getByLabelText(/^Label/), { target: { value: 'Shelf life' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    const [path, body] = api.patch.mock.calls[0]
    expect(path).toBe('custom-fields/cf-1')
    expect(body).toMatchObject({ label: 'Shelf life', required: true, unique: false, min: '0', max: null })
    expect(body).not.toHaveProperty('key')
    expect(body).not.toHaveProperty('type')
    expect(body).not.toHaveProperty('entity')
    expect(await within(dialog).findByText('Another field has this label.')).toBeInTheDocument()
  })

  it('archives a field after confirming', async () => {
    setUp()
    api.post.mockResolvedValue({ data: { ...SHELF_LIFE, archived_at: '2026-10-09T08:00:00Z' } })
    renderApp('/settings/custom-fields')
    fireEvent.click(await screen.findByRole('button', { name: 'Archive Shelf life (days)' }))
    const dialog = await screen.findByRole('dialog', { name: 'Archive Shelf life (days)?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Archive field' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('custom-fields/cf-1/archive'))
  })

  it('shows the list without add, edit or archive to a user who may only view', async () => {
    setUp(VIEW)
    renderApp('/settings/custom-fields')
    expect(await screen.findByText('Shelf life (days)')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Add field' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit Shelf life (days)' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Archive Shelf life (days)' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'History of Shelf life (days)' })).toBeInTheDocument()
  })
})
