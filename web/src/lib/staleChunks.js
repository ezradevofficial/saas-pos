// After a deploy the previous build's chunk files are gone, so a tab opened
// before it fails to load a page it had not visited yet ("Failed to fetch
// dynamically imported module"). Such a tab reloads once to pick up the new
// build; a second failure within a minute is a real error, not a stale tab.
const KEY = 'app.chunk-reload-at'

const PATTERNS = [
  /Failed to fetch dynamically imported module/i,
  /Importing a module script failed/i,
  /error loading dynamically imported module/i,
  /Unable to preload CSS/i,
]

/** Whether $error is a missing build chunk. */
export function isStaleChunkError(error) {
  const message = String(error?.message ?? error ?? '')
  return PATTERNS.some((pattern) => pattern.test(message))
}

/** Reloads the page once per minute; returns false when it already tried, so the caller shows the error. */
export function reloadForNewBuild(storage = window.sessionStorage, reload = () => window.location.reload(), now = Date.now()) {
  let last = 0
  try {
    last = Number(storage.getItem(KEY) ?? 0)
  } catch {
    // Blocked storage: reload anyway, the minute guard is a nicety.
  }
  if (now - last < 60_000) return false
  try {
    storage.setItem(KEY, String(now))
  } catch {
    // ignore
  }
  reload()
  return true
}
