// LAY-04: saved list views. A list's views come in layers, most specific
// first: the user's own, those shared with their roles, the organisation's
// (GET config/list_view/layers?key=<list id>). A view names its columns in
// order (shown or not), its default filters, sort and rows per page.
import { mergeEntries } from './catalogueMerge'

/** A column's key in the export: `exportKey`, else its own key; null or false leaves it out. */
export const exportKeyOf = (column) => (column.exportKey === undefined ? column.key : column.exportKey || null)

const isLayer = (layer) => Array.isArray(layer?.payload?.views) && Boolean(layer?.source?.scope?.type)

/** Every view of the layers, each with a key unique across layers and where it lives. */
export function collectViews(layers) {
  return (Array.isArray(layers) ? layers : []).filter(isLayer).flatMap((layer) =>
    layer.payload.views
      .filter((view) => view && typeof view.id === 'string' && typeof view.name === 'string')
      .map((view) => ({
        ...view,
        key: `${layer.source.scope.type}.${view.id}`,
        scope: { type: layer.source.scope.type, id: layer.source.scope.id ?? null },
        personal: layer.source.scope.type === 'user',
      })),
  )
}

/** The view a reader starts with: the default of the most specific layer that names one. */
export function defaultViewKey(layers) {
  for (const layer of (Array.isArray(layers) ? layers : []).filter(isLayer)) {
    const id = layer.payload.default_view
    if (id && layer.payload.views.some((view) => view?.id === id)) return `${layer.source.scope.type}.${id}`
  }
  return null
}

/** RBAC-05: export keys of the columns the reader's field rules hide, from every layer and the defaults. */
export function hiddenColumnKeys(layers) {
  const keys = new Set()
  for (const layer of Array.isArray(layers) ? layers : []) for (const key of layer?.payload?.hidden_columns ?? []) keys.add(key)
  return [...keys]
}

/** RBAC-05: the columns without those the reader may not see (matched by key or export key). */
export function withoutHiddenColumns(columns, hiddenKeys) {
  if (!hiddenKeys?.length) return columns
  return columns.filter((column) => !hiddenKeys.includes(column.key) && !hiddenKeys.includes(exportKeyOf(column)))
}

/**
 * A view laid over the list's own columns (LAY-07): the view's columns in
 * its order, each shown or not; a column the view does not name (added by
 * a platform update, or a new custom field) comes at the end, shown unless
 * it is `defaultHidden`; a column that no longer exists is skipped. Columns
 * that can't be hidden (`hideable: false`) always show.
 *
 * @returns {{ columns: Array, hidden: string[] }} the columns in order and the keys not shown
 */
export function applyView(columns, view) {
  const catalogue = columns.map((column) => ({ id: column.key, visible: !column.defaultHidden }))
  const merged = mergeEntries(view?.columns ?? [], catalogue)
  const byKey = new Map(columns.map((column) => [column.key, column]))
  // Row actions (kept out of the Columns menu) stay where the page put them: last.
  const placed = merged.map((entry) => byKey.get(entry.id))
  const ordered = [...placed.filter((column) => column.inMenu !== false), ...placed.filter((column) => column.inMenu === false)]
  const hidden = merged.filter((entry) => entry.visible === false && byKey.get(entry.id).hideable !== false).map((entry) => entry.id)
  return { columns: ordered, hidden }
}

/** The list's current state as a view to save. */
export function viewFromState({ id, name }, { columns, hidden, filters, filterDefaults, sort, defaultSort, perPage }) {
  const active = Object.fromEntries(Object.entries(filters ?? {}).filter(([key, value]) => value !== '' && value !== (filterDefaults?.[key] ?? '')))
  return {
    id,
    name: name.trim(),
    columns: columns.filter((column) => column.inMenu !== false).map((column) => ({ id: column.key, visible: !hidden.includes(column.key) })),
    filters: active,
    sort: sort && sort !== defaultSort ? sort : null,
    per_page: perPage ?? null,
  }
}

/** An id for a new view, unique among `taken`: the name in lower case with dashes. */
export function viewId(name, taken = []) {
  const base =
    name
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 30) || 'view'
  let id = base
  for (let n = 2; taken.includes(id); n += 1) id = `${base}-${n}`
  return id
}

/** `payload` with `view` added or replaced; `makeDefault` true or false sets or clears it as the default. */
export function upsertView(payload, view, { makeDefault } = {}) {
  const views = Array.isArray(payload?.views) ? payload.views : []
  const next = views.some((entry) => entry.id === view.id) ? views.map((entry) => (entry.id === view.id ? view : entry)) : [...views, view]
  let defaultView = payload?.default_view ?? null
  if (makeDefault === true) defaultView = view.id
  if (makeDefault === false && defaultView === view.id) defaultView = null
  return { views: next, default_view: defaultView }
}

export function removeView(payload, id) {
  const views = (Array.isArray(payload?.views) ? payload.views : []).filter((entry) => entry.id !== id)
  return { views, default_view: payload?.default_view === id ? null : (payload?.default_view ?? null) }
}
