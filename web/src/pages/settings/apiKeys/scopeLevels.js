// API-02: a key's access per resource, from and to its scope list.

/** The access a scope list gives one resource: none, read or write (write includes read). */
export function levelOf(scopes, resource) {
  if (scopes.includes(`${resource}.write`)) return 'write'
  if (scopes.includes(`${resource}.read`)) return 'read'
  return 'none'
}

/** `scopes` with `resource` set to `level` (none removes it). */
export function withLevel(scopes, resource, level) {
  const others = scopes.filter((scope) => scope !== `${resource}.read` && scope !== `${resource}.write`)
  return level === 'none' ? others : [...others, `${resource}.${level}`].sort()
}
