import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { catalogue, ITEM } from '@/test/catalogue'
import { chooseOption } from '@/test/combobox'
import { apiError, renderApp, resetSession, signedIn } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const base = { help: null, default: null, required: false, unique: false, min: null, max: null, pattern: null, options: [], lookup_target: null, formula_type: null, show_on_pos: false, readonly: false }
const SCHEMA = [
  { ...base, key: 'notes', type: 'text', label: 'Notes', position: 1 },
  { ...base, key: 'weight', type: 'number', label: 'Weight (kg)', position: 2 },
  {
    ...base,
    key: 'colour',
    type: 'select',
    label: 'Colour',
    position: 3,
    options: [
      { value: 'red', label: 'Red' },
      { value: 'blue', label: 'Blue' },
    ],
  },
  { ...base, key: 'organic', type: 'boolean', label: 'Organic', position: 4 },
  { ...base, key: 'best_before', type: 'date', label: 'Best before', position: 5 },
  { ...base, key: 'floor_price', type: 'money', label: 'Floor price', position: 6 },
  { ...base, key: 'margin', type: 'formula', label: 'Margin', formula_type: 'number', position: 7, readonly: true },
  { ...base, key: 'buyer_note', type: 'text', label: 'Buyer note', position: 8, readonly: true },
  { ...base, key: 'sheet', type: 'file', label: 'Data sheet', position: 9 },
  { ...base, key: 'supplier', type: 'lookup', label: 'Main supplier', lookup_target: 'party', position: 10 },
]
const CUSTOM = {
  notes: 'Fragile',
  weight: '1.5',
  colour: 'red',
  organic: true,
  best_before: '2026-12-01',
  floor_price: { amount_minor: '5000', currency: 'KES' },
  margin: '12.5',
  buyer_note: 'Ask Juma',
  sheet: { id: 'f-1', name: 'spec.pdf', mime: 'application/pdf', size: 2048, url: 'https://files.test/spec.pdf' },
  supplier: { id: 'p-1', label: 'Bidco Ltd' },
}

function setUp() {
  catalogue(api, {
    item: { ...ITEM, custom: CUSTOM },
    extra: [
      ['custom-fields/schema?entity=item', { data: SCHEMA }],
      [/^custom-fields\/lookup\?/, { data: [{ id: 'p-2', label: 'Kapa Oil' }] }],
    ],
  })
}

describe('Custom fields in a form (CF-02, RBAC-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('draws each type with its own control; formulas and fields the user may not edit are read-only', async () => {
    setUp()
    renderApp('/catalogue/items/i-1')
    const section = (await screen.findByRole('heading', { name: 'More details' })).closest('[data-slot="card"]') ?? document.body
    expect(within(section).getByLabelText('Notes')).toHaveValue('Fragile')
    expect(within(section).getByLabelText('Weight (kg)')).toHaveValue('1.5')
    expect(within(section).getByLabelText('Colour')).toHaveTextContent('Red')
    expect(within(section).getByLabelText('Organic')).toBeChecked()
    expect(within(section).getByLabelText('Best before')).toHaveValue('2026-12-01')
    expect(within(section).getByLabelText('Floor price')).toHaveValue('50.00')
    expect(within(section).getByLabelText('Margin')).toHaveValue('12.5')
    expect(within(section).getByLabelText('Margin')).toBeDisabled()
    expect(within(section).getByLabelText('Buyer note')).toBeDisabled()
    expect(within(section).getByRole('link', { name: 'spec.pdf' })).toHaveAttribute('href', 'https://files.test/spec.pdf')
    expect(within(section).getByLabelText('Main supplier')).toHaveTextContent('Bidco Ltd')
  })

  it('sends only the custom keys that changed, never read-only ones', async () => {
    setUp()
    api.patch.mockResolvedValue({ data: { ...ITEM, custom: { ...CUSTOM, notes: 'Very fragile', supplier: { id: 'p-2', label: 'Kapa Oil' } } }, meta: { possible_duplicates: [] } })
    renderApp('/catalogue/items/i-1')
    fireEvent.change(await screen.findByLabelText('Notes'), { target: { value: 'Very fragile' } })
    // Typed back to what it was: not a change.
    fireEvent.change(screen.getByLabelText('Weight (kg)'), { target: { value: '1.50' } })
    fireEvent.click(screen.getByLabelText('Organic'))
    chooseOption('Colour', 'Blue')
    chooseOption('Main supplier', 'Kapa Oil')
    fireEvent.click(screen.getByRole('button', { name: 'Remove spec.pdf' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    const [path, body] = api.patch.mock.calls[0]
    expect(path).toBe('items/i-1')
    expect(body.custom).toEqual({ notes: 'Very fragile', organic: false, colour: 'blue', supplier: 'p-2', sheet: null })
  })

  it('leaves custom out when no custom value changed', async () => {
    setUp()
    api.patch.mockResolvedValue({ data: { ...ITEM, custom: CUSTOM }, meta: { possible_duplicates: [] } })
    renderApp('/catalogue/items/i-1')
    await screen.findByLabelText('Notes')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1]).not.toHaveProperty('custom')
  })

  it('uploads a file and sends its id', async () => {
    setUp()
    api.upload.mockResolvedValue({ data: { id: 'f-9', name: 'new.pdf', mime: 'application/pdf', size: 100, url: 'https://files.test/new.pdf' } })
    api.patch.mockResolvedValue({ data: { ...ITEM, custom: CUSTOM }, meta: { possible_duplicates: [] } })
    renderApp('/catalogue/items/i-1')
    const input = await screen.findByLabelText('Choose a file for Data sheet')
    fireEvent.change(input, { target: { files: [new File(['%PDF'], 'new.pdf', { type: 'application/pdf' })] } })
    expect(await screen.findByRole('link', { name: 'new.pdf' })).toBeInTheDocument()
    const [path, form] = api.upload.mock.calls[0]
    expect(path).toBe('custom-field-files')
    expect(form.get('entity')).toBe('item')
    expect(form.get('field')).toBe('sheet')
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(api.patch).toHaveBeenCalled())
    expect(api.patch.mock.calls[0][1].custom).toEqual({ sheet: 'f-9' })
  })

  it('shows an error for custom.<key> under that field', async () => {
    setUp()
    api.patch.mockRejectedValue(apiError(422, 'validation_failed', 'Some fields need attention.', { 'custom.notes': ['Notes may be at most 20 characters.'] }))
    renderApp('/catalogue/items/i-1')
    fireEvent.change(await screen.findByLabelText('Notes'), { target: { value: 'A very long note indeed, too long' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText('Notes may be at most 20 characters.')).toBeInTheDocument()
    expect(screen.getByLabelText('Notes')).toHaveAttribute('aria-invalid', 'true')
  })

  it('does not send a number that is not valid', async () => {
    setUp()
    renderApp('/catalogue/items/i-1')
    fireEvent.change(await screen.findByLabelText('Weight (kg)'), { target: { value: '1.5.2' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByText(/Enter a number such as/)).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()
  })

  it('shows nothing extra when the entity has no fields for the user', async () => {
    catalogue(api, { extra: [['custom-fields/schema?entity=item', { data: [] }]] })
    renderApp('/catalogue/items/i-1')
    await screen.findByLabelText(/^Code/)
    expect(screen.queryByRole('heading', { name: 'More details' })).not.toBeInTheDocument()
  })
})
