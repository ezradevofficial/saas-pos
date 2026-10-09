import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const RECEIPT_DEFAULT = {
  paper: '80mm',
  margins: { top: 3, right: 3, bottom: 3, left: 3 },
  language: 'en',
  blocks: [
    { id: 'header', type: 'text', text: '{{company.legal_name}}', align: 'center', size: 'large', weight: 'medium' },
    { id: 'fiscal', type: 'fiscal' },
  ],
  variants: [],
}

const TYPES = {
  data: [
    {
      key: 'pos.receipt',
      label: 'POS receipt',
      paper: '80mm',
      live: true,
      fiscal: { allowed: true, required: true, authority: 'kra_etims' },
      locked: ['fiscal'],
      fields: [{ path: 'company.legal_name', group: 'company', type: 'text', label: 'Legal name' }],
      columns: [{ key: 'item_name', type: 'text', label: 'Item', numeric: false }],
      default: RECEIPT_DEFAULT,
    },
    {
      key: 'sales.quote',
      label: 'Quote',
      paper: 'A4',
      live: false,
      fiscal: { allowed: false, required: false, authority: null },
      locked: [],
      fields: [],
      columns: [],
      default: { ...RECEIPT_DEFAULT, paper: 'A4', blocks: [] },
    },
  ],
  meta: { country: 'KE', papers: ['58mm', '80mm', 'A4', 'A5'], languages: ['en', 'fr', 'both'], blocks: ['text', 'field', 'fiscal'], operators: ['eq', 'gt'] },
}

const EDITOR = tenantWide(['core.template.view', 'core.template.edit', 'core.template.publish'])

function setup(permissions = EDITOR) {
  mockRoutes(
    api,
    [
      [/^templates\/types\?/, TYPES],
      [/^config\/template\?/, { data: [], meta: { total: 0 } }],
      [/^branches\?/, { data: [] }],
    ],
    { permissions },
  )
  api.post.mockImplementation(async (path, body) => {
    if (path === 'templates/preview') return { data: { html: '<p>Preview</p>', pdf_url: 'http://localhost:8019/api/v1/templates/previews/x/pdf', problems: [] } }
    if (path === 'config/template') {
      return { data: { id: 'doc-1', kind: 'template', key: body.key, scope: { type: 'tenant', id: null }, draft: { version: 1, revision: 1, status: 'draft', payload: body.payload }, published: null, history: [] }, meta: { problems: [] } }
    }
    throw new Error(`unexpected POST ${path}`)
  })
}

describe('Document templates (TPL-01..TPL-05)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
  })

  it('lists the document types with sample-data and locked fiscal notes', async () => {
    setup()
    renderApp('/settings/document-templates')
    const list = await screen.findByRole('list', { name: 'Document types' })
    expect(within(list).getByRole('link', { name: 'POS receipt' })).toHaveAttribute('href', '/settings/document-templates/pos.receipt')
    expect(within(list).getByText(/KRA eTIMS block locked/)).toBeInTheDocument()
    expect(within(list).getByText(/Previewed with sample data/)).toBeInTheDocument()
  })

  it('shows the fiscal block locked with no remove action, adds a text block and saves the draft', async () => {
    setup()
    renderApp('/settings/document-templates/pos.receipt')
    const canvas = await screen.findByRole('list', { name: 'Template blocks' })

    // TPL-03: the tax authority's block is locked: a lock, no remove.
    expect(within(canvas).getByRole('img', { name: 'Tax authority is locked' })).toBeInTheDocument()
    expect(within(canvas).queryByRole('button', { name: 'Remove Tax authority' })).not.toBeInTheDocument()
    expect(within(canvas).getByRole('button', { name: 'Remove Text' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Add Text' }))
    expect(within(canvas).getAllByRole('button', { name: 'Remove Text' })).toHaveLength(2)

    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        'config/template',
        expect.objectContaining({
          key: 'pos.receipt',
          scope_type: 'tenant',
          payload: expect.objectContaining({ blocks: expect.arrayContaining([expect.objectContaining({ id: 'text-1', type: 'text' }), { id: 'fiscal', type: 'fiscal' }]) }),
        }),
      ),
    )
  })

  it('previews the template through the API in a sandboxed frame', async () => {
    setup()
    renderApp('/settings/document-templates/pos.receipt')
    const frame = await screen.findByTestId('template-preview', {}, { timeout: 3000 })
    expect(frame).toHaveAttribute('sandbox', '')
    expect(api.post).toHaveBeenCalledWith('templates/preview', expect.objectContaining({ type: 'pos.receipt', scope_type: 'tenant' }))
  })

  it('lets a viewer look without editing', async () => {
    setup(tenantWide(['core.template.view']))
    renderApp('/settings/document-templates/pos.receipt')
    await screen.findByRole('list', { name: 'Template blocks' })
    expect(screen.queryByRole('button', { name: 'Add Text' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save draft' })).not.toBeInTheDocument()
  })
})
