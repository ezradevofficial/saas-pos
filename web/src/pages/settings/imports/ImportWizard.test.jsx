import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api, ApiError } from '@/api/client'
import { chooseOption } from '@/test/combobox'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const IMPORTERS = {
  data: [
    {
      key: 'items',
      label: 'Items',
      can_update: true,
      match_columns: ['code'],
      columns: [
        { key: 'code', label: 'Code', help: 'Existing items are matched by code.', type: 'text', required: true, options: [] },
        { key: 'name', label: 'Name', help: '', type: 'text', required: true, options: [] },
        { key: 'base_unit', label: 'Base unit', help: '', type: 'text', required: true, options: [] },
        { key: 'cf_colour', label: 'Colour', help: '', type: 'custom:select', required: false, options: [] },
      ],
    },
    { key: 'customers', label: 'Customers', can_update: true, match_columns: ['tax_id'], columns: [{ key: 'name', label: 'Name', help: '', type: 'text', required: true, options: [] }] },
  ],
}

function record(overrides = {}) {
  return {
    id: 'imp-1',
    importer: 'items',
    importer_label: 'Items',
    mode: 'create',
    status: 'ready',
    file_name: 'items.xlsx',
    headers: ['Code', 'Name', 'Unit', 'Colour'],
    mapping: { code: 0, name: 1, cf_colour: 3 },
    total_rows: 20,
    processed_rows: 0,
    created_rows: 0,
    updated_rows: 0,
    failed_rows: 0,
    checked: false,
    failure: null,
    blocking: [],
    user: { id: 'u-1', name: 'Amina Otieno' },
    created_at: '2026-10-13T08:00:00Z',
    finished_at: null,
    undo_until: null,
    can_change: true,
    can_undo: false,
    error_file_url: null,
    result_file_url: null,
    ...overrides,
  }
}

const PREVIEW = {
  data: {
    total_rows: 20,
    valid: 19,
    invalid: 1,
    rows: [
      { row: 2, values: { code: 'A-1', name: 'One' }, status: 'valid', action: 'create', errors: [] },
      { row: 3, values: { code: 'A-2', name: 'Two' }, status: 'failed', action: null, errors: [{ column: 'base_unit', message: 'There is no unit with code XX.' }] },
    ],
  },
}

function setUp(current) {
  mockRoutes(
    api,
    [
      ['imports/importers', IMPORTERS],
      ['imports/imp-1', () => ({ data: current() })],
      ['imports/imp-1/preview', PREVIEW],
    ],
    { permissions: tenantWide(['core.import.run', 'core.item.create', 'core.item.view']) },
  )
}

describe('Import wizard (IMP-01..IMP-03)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('chooses a type, offers the template in English and French, and uploads the file', async () => {
    setUp(() => record())
    api.download.mockResolvedValue({ blob: new Blob(['x']), filename: 'items-template.xlsx' })
    api.upload.mockResolvedValue({ data: record() })
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:x')
    globalThis.URL.revokeObjectURL = vi.fn()
    renderApp('/settings/imports/new')

    const types = await screen.findByRole('radiogroup', { name: 'What do you want to import?' })
    expect(screen.getByRole('button', { name: 'Continue to the template' })).toBeDisabled()
    fireEvent.click(await within(types).findByRole('radio', { name: /Items/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Continue to the template' }))

    expect(await screen.findByText('Existing items are matched by code.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Download template in French' }))
    await waitFor(() => expect(api.download).toHaveBeenCalledWith('imports/importers/items/template?locale=fr'))

    fireEvent.click(screen.getByRole('button', { name: 'Continue to upload' }))
    const send = screen.getByRole('button', { name: 'Upload and map columns' })
    expect(send).toBeDisabled()
    const file = new File(['code,name'], 'items.csv', { type: 'text/csv' })
    fireEvent.change(document.getElementById('import-file'), { target: { files: [file] } })
    expect(screen.getByText('items.csv')).toBeInTheDocument()
    fireEvent.click(send)

    await waitFor(() => expect(api.upload).toHaveBeenCalledTimes(1))
    const [path, form] = api.upload.mock.calls[0]
    expect(path).toBe('imports')
    expect(form.get('importer')).toBe('items')
    expect(form.get('file').name).toBe('items.csv')
    // The upload leads to the mapping step of the new import.
    expect(await screen.findByRole('heading', { name: 'Map columns' })).toBeInTheDocument()
  })

  it('starts with the type from the list toolbar', async () => {
    setUp(() => record())
    renderApp('/settings/imports/new?type=customers')
    expect(await screen.findByRole('heading', { name: 'Customers template' })).toBeInTheDocument()
  })

  it('maps columns with comboboxes, saves the mode and previews rows with their errors', async () => {
    let current = record()
    setUp(() => current)
    api.patch.mockImplementation(async (path, body) => {
      current = record({ mapping: body.mapping, mode: body.mode })
      return { data: current }
    })
    renderApp('/settings/imports/imp-1')

    await screen.findByRole('heading', { name: 'Map columns' })
    expect(screen.getByText('Map columns', { selector: '[aria-current="step"] *' })).toBeInTheDocument()
    chooseOption('Base unit', 'Unit')
    chooseOption('When a record already exists', 'Add new and update existing records')
    fireEvent.click(screen.getByRole('button', { name: 'Save and preview' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('imports/imp-1', { mode: 'upsert', mapping: { code: 0, name: 1, base_unit: 2, cf_colour: 3 } }))
    expect(await screen.findByText('There is no unit with code XX.', { exact: false })).toBeInTheDocument()
    expect(screen.getByText(/19 rows are ready and 1 have errors/)).toBeInTheDocument()
    // Only rows with errors first; all rows on demand.
    expect(screen.queryByText('A-1')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'All rows' }))
    expect(screen.getByText('A-1')).toBeInTheDocument()
  })

  it('shows mapping errors from the API next to the column', async () => {
    setUp(() => record())
    api.patch.mockRejectedValue(new ApiError({ status: 422, code: 'validation_failed', message: 'Map a file column to Base unit.', errors: { 'mapping.base_unit': ['Map a file column to Base unit.'] } }))
    renderApp('/settings/imports/imp-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Save and preview' }))
    expect(await screen.findByText('Map a file column to Base unit.')).toBeInTheDocument()
  })

  it('after the dry run, imports the rows that passed, with progress, then offers the result file and undo', async () => {
    let current = record({ status: 'checked', checked: true, processed_rows: 20, created_rows: 19, failed_rows: 1, error_file_url: 'https://api.test/api/v1/import-files/x/errors.xlsx' })
    setUp(() => current)
    api.post.mockImplementation(async (path) => {
      if (path === 'imports/imp-1/run') {
        current = record({ status: 'importing', processed_rows: 10, created_rows: 10 })
        return { data: current }
      }
      if (path === 'imports/imp-1/undo') {
        current = record({ status: 'undone', processed_rows: 20, created_rows: 19, failed_rows: 1 })
        return { data: current }
      }
      throw new Error(`unexpected ${path}`)
    })
    renderApp('/settings/imports/imp-1')

    expect(await screen.findByText('1 row has errors')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Download rows with errors' })).toHaveAttribute('href', 'https://api.test/api/v1/import-files/x/errors.xlsx')
    fireEvent.click(screen.getByRole('button', { name: 'Import 19 rows' }))

    const bar = await screen.findByRole('progressbar', { name: 'Importing' })
    expect(bar).toHaveAttribute('aria-valuetext', '10 of 20 rows')

    current = record({ status: 'completed', processed_rows: 20, created_rows: 19, failed_rows: 1, can_undo: true, result_file_url: 'https://api.test/api/v1/import-files/x/result.xlsx' })
    expect(await screen.findByRole('link', { name: 'Download result file' }, { timeout: 3000 })).toHaveAttribute('href', 'https://api.test/api/v1/import-files/x/result.xlsx')

    fireEvent.click(screen.getByRole('button', { name: 'Undo import' }))
    const dialog = await screen.findByRole('dialog', { name: 'Undo this import?' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Undo import' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('imports/imp-1/undo'))
    expect(await screen.findByText('This import was undone.')).toBeInTheDocument()
  })

  it('lists the rows that block an undo', async () => {
    setUp(() => record({ status: 'completed', processed_rows: 20, created_rows: 20, can_undo: true }))
    api.post.mockRejectedValue(
      new ApiError({
        status: 422,
        code: 'import_undo_blocked',
        message: '1 records are already in use or were changed since.',
        data: { blocking: { count: 1, rows: [{ row: 3, record: 'A-2 Two', reason: 'Used in a sale' }] } },
      }),
    )
    renderApp('/settings/imports/imp-1')

    fireEvent.click(await screen.findByRole('button', { name: 'Undo import' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Undo import' }))
    expect(await screen.findByText('Row 3, A-2 Two: Used in a sale')).toBeInTheDocument()
    expect(screen.getByText('The import was not undone: 1 record is in use or changed since')).toBeInTheDocument()
  })
})
