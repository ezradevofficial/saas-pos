import { routes } from '@/routes'
import { RequirePermission } from '@/auth/RequirePermission'
import { ACTIONS, EXTRA_PAGES } from './searchRegistry'

/** The route with this exact path, searching nested children. */
function findRoute(list, path) {
  for (const route of list) {
    if (route.path === path) return route
    const nested = route.children ? findRoute(route.children, path) : null
    if (nested) return nested
  }
  return null
}

// LAY-01, RBAC-08: the palette and the router cannot drift apart.
describe('quick search registry', () => {
  it.each([...EXTRA_PAGES, ...ACTIONS].map((entry) => [entry.to, entry]))('%s matches its route and permission', (to, entry) => {
    const route = findRoute(routes, to)
    expect(route, `no route for ${to}`).not.toBeNull()
    const element = route.element
    if (element?.type === RequirePermission) {
      expect(entry.permission).toEqual(element.props.permission)
      expect(Boolean(entry.tenantWide)).toBe(Boolean(element.props.tenantWide))
    } else {
      expect(entry.permission).toBeUndefined()
    }
  })

  it('has one entry per path', () => {
    const paths = [...EXTRA_PAGES, ...ACTIONS].map((entry) => entry.to)
    expect(new Set(paths).size).toBe(paths.length)
  })
})
