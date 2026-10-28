import { normalizeSearch } from '@/lib/options'

/** How many recent destinations the palette remembers (per user, on this device). */
export const RECENT_LIMIT = 5

const recentKey = (userId) => `qs.recent.${userId ?? 'anon'}`

/**
 * Entries that match `query`: every word must appear in the label, group or
 * synonyms, ignoring case and accents. Label matches rank first (whole label
 * start, then label contains, then word start, then group or synonyms).
 * An entry is { id, kind, label, group, keywords, to, icon }.
 */
export function matchEntries(entries, query, limit = 8) {
  const words = normalizeSearch(query).split(/\s+/).filter(Boolean)
  if (words.length === 0) return []
  const whole = words.join(' ')
  const ranked = []
  entries.forEach((entry, index) => {
    const label = normalizeSearch(entry.label)
    const haystack = `${label} ${normalizeSearch(entry.group)} ${normalizeSearch(entry.keywords)}`
    if (!words.every((word) => haystack.includes(word))) return
    let rank = 3
    if (label.startsWith(whole)) rank = 0
    else if (label.includes(whole)) rank = 1
    else if (words.every((word) => label.split(/[^a-z0-9]+/).some((part) => part.startsWith(word)))) rank = 2
    ranked.push({ entry, rank, index })
  })
  ranked.sort((a, b) => a.rank - b.rank || a.index - b.index)
  return ranked.slice(0, limit).map(({ entry }) => entry)
}

/** The user's recent destinations as [{ to }], newest first. Storage may be blocked: then none. */
export function loadRecents(userId) {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(recentKey(userId)) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((item) => item && typeof item.to === 'string').slice(0, RECENT_LIMIT) : []
  } catch {
    return []
  }
}

/** Puts a destination first in the user's recents (no duplicates, at most RECENT_LIMIT). Returns the new list. */
export function saveRecent(userId, destination) {
  const next = [destination, ...loadRecents(userId).filter((item) => item.to !== destination.to)].slice(0, RECENT_LIMIT)
  try {
    window.localStorage.setItem(recentKey(userId), JSON.stringify(next))
  } catch {
    // Recents are a convenience; the palette works without them.
  }
  return next
}
