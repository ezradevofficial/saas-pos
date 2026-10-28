import { loadRecents, matchEntries, RECENT_LIMIT, saveRecent } from './quickSearch'

const entries = [
  { id: 'a', label: 'Stock on hand', group: 'Inventory', keywords: 'quantity' },
  { id: 'b', label: 'Salaire des employés', group: 'Paie', keywords: '' },
  { id: 'c', label: 'Roles', group: 'Settings', keywords: 'permissions' },
]

describe('quick search matching and recents (LAY-01)', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    window.localStorage.clear()
  })

  it('matches label, group and synonyms, ignoring accents and case', () => {
    expect(matchEntries(entries, 'INVENTORY').map((e) => e.id)).toEqual(['a'])
    expect(matchEntries(entries, 'permission').map((e) => e.id)).toEqual(['c'])
    expect(matchEntries(entries, 'employes').map((e) => e.id)).toEqual(['b'])
    expect(matchEntries(entries, 'stock hand').map((e) => e.id)).toEqual(['a'])
    expect(matchEntries(entries, '   ')).toEqual([])
  })

  it('keeps the newest five without duplicates', () => {
    for (const to of ['/1', '/2', '/3', '/4', '/5', '/6', '/3']) saveRecent('u', { to })
    expect(loadRecents('u').map((r) => r.to)).toEqual(['/3', '/6', '/5', '/4', '/2'])
    expect(loadRecents('u')).toHaveLength(RECENT_LIMIT)
    expect(loadRecents('other')).toEqual([])
  })

  it('works when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    expect(loadRecents('u')).toEqual([])
    expect(saveRecent('u', { to: '/x' })).toEqual([{ to: '/x' }])
  })
})
