// The manual stays complete: every route of the web app is the `screens` of
// some topic in docs/manual, or is listed with a reason in
// docs/manual/route-exclusions.json (RBAC-08, L10N-01).
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { routes } from './routes'
import { screenMatches } from './lib/helpScreens'

const manual = resolve(__dirname, '../../docs/manual')

/** Every full path in the route tree (children join to their parent's path). */
function collect(list, base = '') {
  return list.flatMap((route) => {
    const own = route.path === undefined ? base : route.path.startsWith('/') ? route.path : `${base.replace(/\/$/, '')}/${route.path}`
    const here = route.path !== undefined || route.index ? [own] : []
    return [...here, ...collect(route.children ?? [], own)]
  })
}

/** `:id?` gives two paths. */
function variants(path) {
  const parts = path.split('/')
  let results = ['']
  for (const part of parts.slice(1)) {
    const optional = part.startsWith(':') && part.endsWith('?')
    results = results.flatMap((prefix) => (optional ? [prefix, `${prefix}/${part}`] : [`${prefix}/${part}`]))
  }
  return results.map((value) => value || '/')
}

/** Param names do not matter: `/a/:id` and `/a/:aId` are the same page. */
const normalise = (path) => (path.length > 1 ? path.replace(/\/$/, '') : path).replace(/:[^/]+/g, ':')

function topicScreens() {
  const files = []
  const walk = (dir) => {
    for (const entry of readdirSync(dir)) {
      const full = join(dir, entry)
      if (statSync(full).isDirectory()) walk(full)
      else if (entry.endsWith('.md') && entry !== 'README.md') files.push(full)
    }
  }
  walk(join(manual, 'en'))
  return files.flatMap((file) => {
    const match = /^screens:\s*\[(.*)\]\s*$/m.exec(readFileSync(file, 'utf8').split(/^---$/m)[1] ?? '')
    return match ? match[1].split(',').map((value) => value.trim()).filter(Boolean).map((screen) => ({ file, screen })) : []
  })
}

const exclusions = JSON.parse(readFileSync(join(manual, 'route-exclusions.json'), 'utf8'))
const allRoutes = [...new Set(collect(routes))]
const matchesEntry = (entry, path) => (entry.prefix ? path.startsWith(entry.prefix) : normalise(path) === normalise(entry.path))
const excluded = (path) => exclusions.some((entry) => matchesEntry(entry, path))

describe('manual coverage', () => {
  it('every route is in a topic or in the exclusion list', () => {
    const screens = new Set(topicScreens().filter((entry) => entry.screen.startsWith('/')).map((entry) => normalise(entry.screen)))
    const missing = allRoutes.filter((path) => path !== '*' && !excluded(path) && !variants(path).some((variant) => screens.has(normalise(variant))))
    expect(missing, "Routes without a manual topic: add each to a topic's screens, or to docs/manual/route-exclusions.json with a reason").toEqual([])
  })

  it('every exclusion has a reason and names a real route', () => {
    for (const entry of exclusions) expect(entry.reason, JSON.stringify(entry)).toBeTruthy()
    // The dev gallery exists only in development builds.
    const stale = exclusions.filter((entry) => entry.path !== '/dev/components' && !allRoutes.some((path) => matchesEntry(entry, path)))
    expect(stale.map((entry) => entry.path ?? entry.prefix)).toEqual([])
  })

  it('every web screen of a topic is a real route, so the help "?" finds it', () => {
    const real = allRoutes.flatMap(variants)
    const screens = topicScreens().filter((entry) => entry.screen.startsWith('/'))
    const wrong = screens.filter(({ screen }) => !real.some((path) => normalise(path) === normalise(screen)))
    expect(wrong.map(({ file, screen }) => `${screen} in ${file}`)).toEqual([])
    // The help page's own matcher accepts a concrete path of each screen.
    for (const { screen } of screens) expect(screenMatches(screen, screen.replace(/:[^/]+/g, 'sample'))).toBe(true)
  })
})
