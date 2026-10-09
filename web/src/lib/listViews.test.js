// LAY-04, LAY-07, RBAC-05: saved list views over a list's own columns.
import { mergeEntries } from './catalogueMerge'
import { applyView, collectViews, defaultViewKey, removeView, upsertView, viewFromState, viewId, withoutHiddenColumns } from './listViews'

const COLUMNS = [
  { key: 'code', label: 'Code' },
  { key: 'name', label: 'Name', hideable: false },
  { key: 'unit', label: 'Unit', exportKey: 'base_unit' },
  { key: 'cf_colour', label: 'Colour', defaultHidden: true },
  { key: 'actions', label: '', inMenu: false, hideable: false },
]

const layer = (type, id, views, defaultView = null) => ({ payload: { views, default_view: defaultView, hidden_columns: [] }, source: { scope: { type, id } } })

describe('list views', () => {
  it('lays a view over the columns, adding new ones at the end per defaultHidden and skipping gone ones (LAY-07)', () => {
    const view = { id: 'v', name: 'V', columns: [{ id: 'unit', visible: true }, { id: 'gone', visible: true }, { id: 'name', visible: true }, { id: 'code', visible: false }] }
    const { columns, hidden } = applyView(COLUMNS, view)
    expect(columns.map((column) => column.key)).toEqual(['unit', 'name', 'code', 'cf_colour', 'actions'])
    expect(hidden).toEqual(['code', 'cf_colour'])
  })

  it('always shows a column that can’t be hidden', () => {
    const { hidden } = applyView(COLUMNS, { id: 'v', name: 'V', columns: [{ id: 'name', visible: false }] })
    expect(hidden).not.toContain('name')
  })

  it('drops columns the reader’s field rules hide, by key or export key (RBAC-05)', () => {
    expect(withoutHiddenColumns(COLUMNS, ['base_unit', 'code']).map((column) => column.key)).toEqual(['name', 'cf_colour', 'actions'])
  })

  it('collects views from every layer and starts with the most specific default', () => {
    const layers = [layer('user', 'u-1', [{ id: 'mine', name: 'Mine', columns: [] }]), layer('role', 'r-1', [{ id: 'tills', name: 'Tills', columns: [] }], 'tills'), layer('tenant', null, [{ id: 'all', name: 'All', columns: [] }], 'all')]
    expect(collectViews(layers).map((view) => [view.key, view.personal])).toEqual([
      ['user.mine', true],
      ['role.tills', false],
      ['tenant.all', false],
    ])
    expect(defaultViewKey(layers)).toBe('role.tills')
    expect(defaultViewKey([])).toBeNull()
  })

  it('saves the list as it stands, only filters that differ from their defaults', () => {
    const view = viewFromState(
      { id: 'mine', name: ' Mine ' },
      { columns: COLUMNS, hidden: ['code'], filters: { status: 'active', type: 'stock', category: '' }, filterDefaults: { status: 'active', type: '', category: '' }, sort: '-name', defaultSort: '', perPage: 50 },
    )
    expect(view).toEqual({
      id: 'mine',
      name: 'Mine',
      columns: [
        { id: 'code', visible: false },
        { id: 'name', visible: true },
        { id: 'unit', visible: true },
        { id: 'cf_colour', visible: true },
      ],
      filters: { type: 'stock' },
      sort: '-name',
      per_page: 50,
    })
  })

  it('adds, replaces and removes views, keeping the default straight', () => {
    const one = upsertView(null, { id: 'a', name: 'A', columns: [] }, { makeDefault: true })
    expect(one).toEqual({ views: [{ id: 'a', name: 'A', columns: [] }], default_view: 'a' })
    const two = upsertView(one, { id: 'a', name: 'A2', columns: [] })
    expect(two.views).toEqual([{ id: 'a', name: 'A2', columns: [] }])
    expect(removeView(two, 'a')).toEqual({ views: [], default_view: null })
    expect(viewId('Mes vues été', ['mes-vues-ete'])).toBe('mes-vues-ete-2')
  })

  it('merges like the API’s CatalogueMerge: after hints and the hidden rule (LAY-07)', () => {
    const merged = mergeEntries([{ id: 'b' }, { id: 'a', width: 3 }], [{ id: 'a', width: 1 }, { id: 'b' }, { id: 'c', after: 'b' }, { id: 'd', whenNew: 'hidden' }])
    expect(merged).toEqual([{ id: 'b' }, { id: 'c' }, { id: 'a', width: 3 }, { id: 'd', hidden: true }])
  })
})
