// LAY-07: upgrade safety on the web, the same rules as the API's
// CatalogueMerge (docs/adr/010) for layouts whose catalogue lives in the web
// app (the navigation, a list's columns):
//
// - an entry the layout names that no longer exists is skipped, and so is a
//   second mention of the same id;
// - an entry the layout names keeps its place and settings, over the
//   catalogue's defaults;
// - a catalogue entry the layout does not mention appears after the entry
//   its `after` names (else at the end), hidden when the rule (or its own
//   `whenNew`) says 'hidden'. In grouped layouts it goes to the group its
//   `group` names, else the group of its `after`, else the first.
//
// `after`, `whenNew` and `group` are hints and never reach the result.

const HINTS = ['after', 'whenNew', 'group']

const withoutHints = (entry) => Object.fromEntries(Object.entries(entry).filter(([key]) => !HINTS.includes(key)))

function index(catalogue, id) {
  const known = new Map()
  for (const entry of catalogue) {
    if (entry && entry[id] != null && !known.has(String(entry[id]))) known.set(String(entry[id]), entry)
  }
  return known
}

function keep(stored, known, id, seen = new Set()) {
  const result = []
  for (const entry of stored ?? []) {
    const key = entry && entry[id] != null ? String(entry[id]) : null
    if (key === null || !known.has(key) || seen.has(key)) continue
    seen.add(key)
    result.push({ ...withoutHints(known.get(key)), ...entry })
  }
  return result
}

function place(entries, entry, rule, id, hidden, anchors) {
  const fresh = withoutHints(entry)
  if ((entry.whenNew ?? rule) === 'hidden') fresh[hidden] = true
  const after = entry.after != null ? String(entry.after) : null
  if (after !== null) {
    const behind = anchors.get(after) ?? after
    const at = entries.findIndex((existing) => String(existing[id] ?? '') === behind)
    if (at >= 0) {
      anchors.set(after, String(fresh[id]))
      return [...entries.slice(0, at + 1), fresh, ...entries.slice(at + 1)]
    }
  }
  return [...entries, fresh]
}

/** A flat layout (a list's columns) merged with the catalogue. */
export function mergeEntries(stored, catalogue, { newEntries = 'append', id = 'id', hidden = 'hidden' } = {}) {
  const known = index(catalogue, id)
  let result = keep(stored, known, id)
  const placed = new Set(result.map((entry) => String(entry[id])))
  const anchors = new Map()
  for (const entry of catalogue) {
    if (entry?.[id] == null || placed.has(String(entry[id]))) continue
    placed.add(String(entry[id]))
    result = place(result, entry, newEntries, id, hidden, anchors)
  }
  return result
}

/** A layout of groups (menu groups), each listing entries under `items`, merged with the catalogue. */
export function mergeGrouped(groups, catalogue, { items = 'items', newEntries = 'append', newGroup = { id: 'main' }, id = 'id', hidden = 'hidden' } = {}) {
  const known = index(catalogue, id)
  const seen = new Set()
  let result = (groups ?? []).map((group) => ({ ...group, [items]: keep(group?.[items] ?? [], known, id, seen) }))
  const anchors = new Map()
  for (const entry of catalogue) {
    if (entry?.[id] == null || seen.has(String(entry[id]))) continue
    seen.add(String(entry[id]))
    if (result.length === 0) result = [{ ...newGroup, [items]: [] }]
    let target = result.findIndex((group) => entry.group != null && group[id] === entry.group)
    if (target < 0 && entry.after != null) {
      const anchor = anchors.get(String(entry.after)) ?? String(entry.after)
      target = result.findIndex((group) => group[items].some((existing) => String(existing[id]) === anchor))
    }
    if (target < 0) target = 0
    result = result.map((group, at) => (at === target ? { ...group, [items]: place(group[items], entry, newEntries, id, hidden, anchors) } : group))
  }
  return result
}
