// LAY-02, LAY-07, RBAC-09: the sidebar is the catalogue laid out by the
// role's navigation layout, then filtered by permissions and modules.
import { applyNavigationLayout, homeOf, navigationTree, visibleGroups } from './navigation'

const t = (key) => key
const CATALOGUE = [
  { id: 'overview', label: () => 'Overview', items: [{ to: '/', icon: 'dashboard', label: () => 'Dashboard' }] },
  {
    id: 'pos',
    label: () => 'Point of sale',
    items: [
      { to: '/pos/sales', icon: 'sales', label: () => 'Sales', permission: 'pos.sale.view', module: 'pos' },
      { to: '/pos/held', icon: 'held', label: () => 'Held', permission: 'pos.sale.void', module: 'pos' },
    ],
  },
  { id: 'catalogue', label: () => 'Catalogue', items: [{ to: '/catalogue/items', icon: 'items', label: () => 'Items', permission: 'core.item.view' }] },
]

const labels = (groups) => groups.map((group) => [group.label(t), group.items.map((item) => item.label(t))])

describe('navigation layouts', () => {
  it('keeps the catalogue as it is without a layout', () => {
    expect(applyNavigationLayout(CATALOGUE, null)).toBe(CATALOGUE)
  })

  it('renames, reorders, hides and moves items between groups', () => {
    const layout = {
      groups: [
        { id: 'pos', label: 'Till', items: [{ id: '/catalogue/items', label: 'Products' }, { id: '/pos/sales', label: 'Receipts' }, { id: '/pos/held', hidden: true }] },
        { id: 'overview', items: [{ id: '/' }] },
      ],
    }
    expect(labels(applyNavigationLayout(CATALOGUE, layout))).toEqual([
      ['Till', ['Products', 'Receipts']],
      ['Overview', ['Dashboard']],
    ])
  })

  it('puts items added by the platform in their default group and skips items that are gone (LAY-07)', () => {
    const layout = { groups: [{ id: 'pos', items: [{ id: '/pos/gone' }, { id: '/pos/held' }] }] }
    const tree = navigationTree(CATALOGUE, layout)
    expect(tree.map((group) => [group.id, group.items.map((item) => item.id)])).toEqual([
      ['pos', ['/pos/held', '/pos/sales']],
      ['overview', ['/']],
      ['catalogue', ['/catalogue/items']],
    ])
  })

  it('keeps an organisation group of its own, and drops a group left empty', () => {
    const layout = {
      groups: [
        { id: 'custom-1', label: 'Daily', items: [{ id: '/pos/sales' }, { id: '/pos/held' }] },
        { id: 'pos', items: [] },
      ],
    }
    const groups = labels(applyNavigationLayout(CATALOGUE, layout))
    expect(groups[0]).toEqual(['Daily', ['Sales', 'Held']])
    expect(groups.map(([label]) => label)).not.toContain('Point of sale')
  })

  it('never grants: permissions and modules still filter what the layout shows (RBAC-09)', () => {
    const layout = { groups: [{ id: 'pos', items: [{ id: '/pos/sales' }, { id: '/pos/held' }] }], home: '/pos/held' }
    const visible = visibleGroups(applyNavigationLayout(CATALOGUE, layout), {
      can: (name) => name === 'pos.sale.view',
      hasModule: (module) => module === 'pos',
    })
    expect(labels(visible)).toEqual([
      ['Point of sale', ['Sales']],
      ['Overview', ['Dashboard']],
    ])
    // A home page the user can't open is ignored.
    expect(homeOf(layout, visible)).toBeNull()
    expect(homeOf({ ...layout, home: '/pos/sales' }, visible)).toBe('/pos/sales')
    expect(homeOf({ ...layout, home: '/pos/sales' }, visibleGroups(applyNavigationLayout(CATALOGUE, layout), { can: () => true, hasModule: () => false }))).toBeNull()
  })
})
