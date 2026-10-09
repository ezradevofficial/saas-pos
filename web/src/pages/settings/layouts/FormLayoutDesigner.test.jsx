import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { editableLayout, layoutPayload } from './formLayoutDraft'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const field = (id, label, extra = {}) => ({ id, default_label: label, required: false, has_default: false, wide: false, source: 'builtin', type: null, group: 'details', ...extra })
const FIELDS = [
  field('code', 'Code', { required: true }),
  field('name', 'Name', { required: true }),
  field('category_id', 'Category'),
  field('units', 'Units of measure', { group: 'units', wide: true, required: true, has_default: true }),
  field('custom.shelf', 'Shelf', { source: 'custom', type: 'text', group: 'custom' }),
]
const DEFAULTS = {
  tabs: [],
  sections: [
    { id: 'details', title: 'Details', tab: null, columns: 2, fields: [{ id: 'code' }, { id: 'name' }, { id: 'category_id' }] },
    { id: 'units', title: 'Units', tab: null, columns: 1, fields: [{ id: 'units' }] },
  ],
}

function setUp() {
  mockRoutes(
    api,
    [
      ['form-layouts', { data: [{ key: 'item', label: 'Item' }, { key: 'party', label: 'Customer or supplier' }] }],
      ['form-layouts?form=item', { data: { key: 'item', label: 'Item', fields: FIELDS, defaults: DEFAULTS } }],
      [/^config\/form_layout\?/, { data: [], meta: { total: 0 } }],
      [/^roles\?/, { data: [{ id: 'r-1', name: 'Cashier' }] }],
    ],
    { permissions: tenantWide(['core.layout.view', 'core.layout.edit', 'core.layout.publish']) },
  )
  api.post.mockImplementation(async (path, body) => ({ data: { id: 'd-1', kind: 'form_layout', key: 'item', scope: { type: 'tenant', id: null }, draft: { version: 1, revision: 1, payload: body.payload }, published: null, history: [] }, meta: { problems: [] } }))
}

describe('Form layout designer (LAY-03)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lays the defaults over the catalogue, hides a field into the palette and saves the draft', async () => {
    setUp()
    renderApp('/settings/layouts/forms')
    const canvas = await screen.findByRole('list', { name: 'Sections' })
    // A field the defaults don't place (the new custom field) goes to the last section.
    await waitFor(() => expect(within(canvas).getByRole('list', { name: 'Units' })).toHaveTextContent('Shelf'))
    // A required field without a default can't be hidden.
    expect(screen.getByRole('button', { name: 'Hide Code' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Hide Category' }))
    const palette = screen.getByRole('list', { name: 'Hidden fields' })
    expect(palette).toHaveTextContent('Category')

    fireEvent.click(screen.getByRole('button', { name: 'Add section' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [path, body] = api.post.mock.calls[0]
    expect(path).toBe('config/form_layout')
    expect(body.key).toBe('item')
    expect(body.payload.sections.map((section) => section.id)).toEqual(['details', 'units', 'section-1'])
    expect(body.payload.sections[0].fields).toEqual([{ id: 'code' }, { id: 'name' }, { id: 'category_id', hidden: true }])
    expect(body.payload.sections[1].fields.map((entry) => entry.id)).toEqual(['units', 'custom.shelf'])
  })

  it('keeps only what a layout sets when it saves', () => {
    const draft = editableLayout({ tabs: [{ id: 't', title: ' Main ' }], sections: [{ id: 'a', title: '', columns: 3, fields: [{ id: 'name', label: ' Full name ', hidden_roles: ['r-1'] }, { id: 'gone' }] }] }, FIELDS, DEFAULTS)
    const payload = layoutPayload(draft)
    expect(payload.tabs).toEqual([{ id: 't', title: 'Main' }])
    expect(payload.sections[0]).toMatchObject({ id: 'a', title: null, tab: 't', columns: 3 })
    expect(payload.sections[0].fields[0]).toEqual({ id: 'name', label: 'Full name', hidden_roles: ['r-1'] })
    expect(payload.sections[0].fields.map((entry) => entry.id)).not.toContain('gone')
  })
})
