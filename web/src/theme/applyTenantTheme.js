// Applies a tenant's brand overrides as CSS variables on :root (BR-02).
// Only OVERRIDABLE_TOKENS are accepted; status colours, spacing, type sizes
// and the focus ring are never overridable.
import { OVERRIDABLE_TOKENS } from './themes'

const normalise = (key) => String(key).replace(/^--/, '')

export function applyTenantTheme(overrides = {}, root = document.documentElement) {
  const wanted = new Map(Object.entries(overrides ?? {}).map(([key, value]) => [normalise(key), value]))
  const applied = []
  for (const token of OVERRIDABLE_TOKENS) {
    const value = wanted.get(token)
    if (typeof value === 'string' && value.trim() !== '') {
      root.style.setProperty(`--${token}`, value.trim())
      applied.push(token)
    } else {
      root.style.removeProperty(`--${token}`)
    }
  }
  return applied
}
