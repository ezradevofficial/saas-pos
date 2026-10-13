import { matchRoutes } from 'react-router'
import { routes } from '@/routes'

/** The import wizard (IMP-01); linked only once the app has its route. */
export const IMPORT_ITEMS_PATH = '/settings/imports/new?type=items'

/**
 * True when the app has a page for `path` other than the catch-all "not
 * found", so a link to a page another branch adds is shown only once it
 * exists. Reads the routes at call time (routes.jsx imports the pages
 * that call this).
 */
export function hasRoute(path) {
  const matches = matchRoutes(routes, path.split('?')[0]) ?? []
  return matches.length > 0 && matches[matches.length - 1].route.path !== '*'
}
