import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { layoutFrom, move, previewTiles } from './posLayout/layout'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const DESIGNER = tenantWide(['core.company.view', 'pos.layout.view', 'pos.layout.edit', 'pos.layout.publish'])
const DRINKS = { id: 'cat-drinks', name: 'Drinks' }
const BAKERY = { id: 'cat-bakery', name: 'Bakery' }
const COLA = { id: 'item-cola', code: 'COLA', name: 'Coca-Cola 500 ml', category_id: DRINKS.id, images: [] }
const LOAF = { id: 'item-loaf', code: 'LOAF', name: 'White loaf', category_id: BAKERY.id, images: [] }

const documentWith = (payload, scope = { type: 'tenant', id: null }) => ({
  data: { id: 'doc-1', kind: 'pos_layout', key: 'default', scope, published: null, draft: { id: 'v-1', version: 1, status: 'draft', source: 'draft', payload, revision: 1 }, history: [] },
  meta: { problems: [] },
})

function setup({ documents = [], document = null, permissions = DESIGNER } = {}) {
  mockRoutes(
    api,
    [
      [/^config\/pos_layout\?key=default/, { data: documents }],
      ['config/pos_layout/doc-1', () => document],
      ['companies?per_page=200', { data: [{ id: 'co-1', name: 'Duka' }] }],
      ['branches?per_page=200', { data: [{ id: 'br-1', name: 'Gombe', company_id: 'co-1', company: { id: 'co-1', name: 'Duka' } }] }],
      ['locations?per_page=200', { data: [{ id: 'loc-1', name: 'Gombe till', branch_id: 'br-1', branch: { id: 'br-1', name: 'Gombe' } }] }],
      [/^item-categories\?/, { data: [DRINKS, BAKERY] }],
      [/^items\?per_page=24/, { data: [COLA, LOAF] }],
      [/^items\?per_page=20/, { data: [COLA, LOAF] }],
      [/^items\?per_page=50/, { data: [] }],
      ['branding/assets', { data: [{ id: 'asset-1', kind: 'logo', url: 'https://example.test/logo.png' }] }],
    ],
    { permissions, modules: ['core', 'pos'] },
  )
}

describe('POS layout designer (LAY-05)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    vi.useRealTimers()
  })

  it('lists the business, companies, branches and locations to design for, searchably', async () => {
    setup()
    renderApp('/settings/pos-layout')
    expect(await screen.findByRole('heading', { name: 'POS layout' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('combobox', { name: 'Layout for' }))
    fireEvent.change(await screen.findByPlaceholderText(/search/i), { target: { value: 'gombe till' } })
    expect(await screen.findByRole('option', { name: 'Location: Gombe till (Gombe)' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Company: Duka' })).not.toBeInTheDocument()
  })

  it('previews the sell screen and saves the layout as a draft with token colours and a quick button', async () => {
    setup()
    api.post.mockImplementation(async (_path, body) => documentWith(body.payload))
    renderApp('/settings/pos-layout')

    // New categories appear in name order; the preview draws 4 tablet columns by default.
    const preview = await screen.findByRole('group', { name: 'Tablet' })
    expect(preview.querySelector('[data-preview="grid"]').dataset.columns).toBe('4')

    fireEvent.click(screen.getByRole('radio', { name: 'Left' }))
    expect(preview.querySelector('[data-preview="tablet"]').className).toContain('flex-row-reverse')

    // A token swatch on Drinks tints its tiles and chip in the preview (never a typed colour).
    fireEvent.click(within(screen.getByRole('radiogroup', { name: 'Colour of Drinks' })).getByRole('radio', { name: 'Brand tint' }))
    const cola = within(preview).getByRole('button', { name: 'Coca-Cola 500 ml' })
    expect(cola.className).toContain('bg-primary-tint')
    expect(cola.getAttribute('style')).toBeNull()

    await waitFor(
      () =>
        expect(api.post).toHaveBeenCalledWith(
          'config/pos_layout',
          expect.objectContaining({
            scope_type: 'tenant',
            payload: expect.objectContaining({
              keypad: 'left',
              categories: [
                { id: BAKERY.id, hidden: false, color: null, image: null },
                { id: DRINKS.id, hidden: false, color: 'primary-tint', image: null },
              ],
            }),
          }),
        ),
      { timeout: 3000 },
    )
  })

  it('is read-only without pos.layout.edit', async () => {
    setup({ permissions: tenantWide(['pos.layout.view']) })
    renderApp('/settings/pos-layout')
    expect(await screen.findByText(/see this layout but not change it/i)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Left' })).toBeDisabled()
  })
})

describe('POS layout helpers (LAY-05, LAY-07)', () => {
  it('merges a stored layout with the categories: its order first, new ones last, deleted ones skipped', () => {
    const layout = layoutFrom({ categories: [{ id: 'gone' }, { id: DRINKS.id, color: 'warning-tint', hidden: true }, { id: 'x', color: '#ff0000' }], keypad: 'up' }, [BAKERY, DRINKS])
    expect(layout.categories).toEqual([
      { id: DRINKS.id, hidden: true, color: 'warning-tint', image: null },
      { id: BAKERY.id, hidden: false, color: null, image: null },
    ])
    expect(layout.keypad).toBe('right')
  })

  it('orders preview tiles like the till: pinned, then by category', () => {
    const layout = layoutFrom({ products: { pinned: [LOAF.id], order: 'category' }, categories: [{ id: DRINKS.id }, { id: BAKERY.id }] }, [DRINKS, BAKERY])
    expect(previewTiles([COLA, LOAF], layout).map((item) => item.name)).toEqual(['White loaf', 'Coca-Cola 500 ml'])
  })

  it('moves an entry to where it is dropped', () => {
    expect(move(['a', 'b', 'c'], (id) => id, 'c', 'a')).toEqual(['c', 'a', 'b'])
    expect(move(['a', 'b'], (id) => id, 'a', 'zzz')).toEqual(['a', 'b'])
  })
})
