/** Whether a topic's `screens` entry (a web route such as `/settings/roles/:roleId`) matches a path. */
export function screenMatches(pattern, path) {
  if (typeof pattern !== 'string' || !pattern.startsWith('/')) return false
  const source = pattern
    .split('/')
    .map((part) => (part.startsWith(':') ? '[^/]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    .join('/')
  return new RegExp(`^${source}/?$`).test(path)
}
