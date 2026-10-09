// A server list's state and data (EXP-01, LAY-04): search, filters, sort,
// page and rows per page live in the URL so a list can be shared or
// bookmarked; the user's column choice lives in localStorage per user and
// list; exports download the same query as a file with the bearer token.
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { useAuth } from '@/auth/AuthProvider'
import { applyView, collectViews, defaultViewKey, exportKeyOf, hiddenColumnKeys, withoutHiddenColumns } from './listViews'

export const PER_PAGE_OPTIONS = [10, 25, 50, 100]
export const EXPORT_FORMATS = ['xlsx', 'csv', 'pdf']

/** `app.list.<userId>.<listId>.columns`: the column keys the user hid. */
export const columnsStorageKey = (userId, listId) => `app.list.${userId ?? 'anonymous'}.${listId}.columns`

/** The saved hidden keys, or null when the user never chose (columns' `defaultHidden` then apply). */
function readHidden(key) {
  try {
    const raw = window.localStorage.getItem(key)
    if (raw === null) return null
    const stored = JSON.parse(raw)
    return Array.isArray(stored) ? stored.filter((entry) => typeof entry === 'string') : null
  } catch {
    return null
  }
}

function writeHidden(key, hidden) {
  try {
    window.localStorage.setItem(key, JSON.stringify(hidden))
  } catch {
    // Storage can be blocked (private mode); the choice holds for this visit.
  }
}

export { exportKeyOf }

/** `columns` in the order of `keys` (null: as they are); row actions stay last, unnamed columns after the named. */
function inOrder(columns, keys) {
  if (!keys) return columns
  const rank = (column) => (column.inMenu === false ? keys.length + 1 : keys.includes(column.key) ? keys.indexOf(column.key) : keys.length)
  return [...columns].sort((a, b) => rank(a) - rank(b))
}

/** Query key of a list's saved views (LAY-04). */
export const listViewsKey = (listId) => ['config', 'list_view', 'layers', listId]

const today = () => {
  const now = new Date()
  return [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-')
}

function saveFile(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  // Revoked after the click has handed the file to the browser.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

/**
 * Typed text kept in the URL (a search box, a tag filter): the box shows each
 * keystroke at once and `write` gets the trimmed text once typing stops for
 * `delay` ms. When the URL changes elsewhere (back, forward, a link), the box
 * follows it. Returns `[value, change(text)]`.
 *
 * `flushOnUnmount`: a box that goes away while its owner stays (a filter in
 * the Filters drawer, which unmounts when it closes) writes its pending text
 * at once instead of dropping the last keystrokes. Off for boxes whose page
 * itself goes away, so nothing writes to the URL of the next page.
 */
export function useTypedText(urlValue, write, delay = 300, { flushOnUnmount = false } = {}) {
  // draft: what the box shows; base: the URL value it was typed over; target: what it will write.
  const [entry, setEntry] = useState(null)
  const timer = useRef(null)
  // The text waiting for the timer (null when nothing waits) and the latest writer, for the unmount flush.
  const pending = useRef(null)
  const writer = useRef({ write, flushOnUnmount })
  useEffect(() => {
    writer.current = { write, flushOnUnmount }
  })
  useEffect(
    () => () => {
      clearTimeout(timer.current)
      if (writer.current.flushOnUnmount && pending.current !== null) writer.current.write(pending.current)
      pending.current = null
    },
    [],
  )
  const value = entry && (urlValue === entry.base || urlValue === entry.target) ? entry.draft : urlValue
  const change = (next) => {
    const target = next.trim()
    setEntry({ draft: next, base: urlValue, target })
    clearTimeout(timer.current)
    pending.current = target
    timer.current = setTimeout(() => {
      pending.current = null
      setEntry((current) => (current ? { ...current, base: target } : current))
      write(target)
    }, delay)
  }
  return [value, change]
}

/**
 * Downloads a list as a file (EXP-01) with the bearer token: the endpoint
 * with `params` (filters, search, sort; never a page), `format` and the
 * column keys in order (none: every exportable column). A toast shows
 * while it runs and says why when it fails. For lists that are not a
 * ListView (a tree, grouped cards) as much as for ListView itself.
 *
 * @returns {{ exportTo: (format: string, params?: URLSearchParams|Record<string,string>, columns?: string[]) => Promise<void>, exporting: string|null }}
 */
export function useListExport({ id, endpoint }) {
  const { t } = useTranslation()
  const [exporting, setExporting] = useState(null)
  const exportTo = async (format, base = {}, columns = []) => {
    if (exporting) return
    const params = new URLSearchParams(base)
    params.set('format', format)
    for (const key of columns) params.append('columns[]', key)
    setExporting(format)
    const toastId = toast.loading(t('ds.listView.exporting'))
    try {
      const { blob, filename } = await api.download(`${endpoint}?${params}`)
      saveFile(blob, filename ?? `${id}-${today()}.${format}`)
      toast.success(t('ds.listView.exported'), { id: toastId })
    } catch (error) {
      toast.error(exportError(error, t), { id: toastId })
    } finally {
      setExporting(null)
    }
  }
  return { exportTo, exporting }
}

/**
 * @param {object} options
 * @param {string} options.id the list's id: column storage and the fallback file name
 * @param {string} options.endpoint the API path, e.g. "items"
 * @param {Record<string, string>} [options.params] fixed API params not kept in the URL (e.g. a role)
 * @param {Record<string, string>} [options.filters] filter name -> default value (kept in the URL)
 * @param {string} [options.defaultSort] "key" or "-key"; empty leaves the order to the API
 * @param {number} [options.defaultPerPage]
 * @param {Array} [options.columns] DataTable columns plus `sortKey`, `exportKey`, `hideable`, `defaultHidden`, `inMenu` (false keeps an actions column out of the Columns menu)
 * @param {Array} [options.queryKey] query key prefix (default [id]); "list" and the query follow
 */
export function useServerList({ id, endpoint, params: fixed = {}, filters: filterDefaults = {}, defaultSort = '', defaultPerPage = 25, columns: allColumns = [], queryKey }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const filterNames = Object.keys(filterDefaults)

  // LAY-04: the list's saved views (the user's own, their roles', the
  // organisation's). The one in `?view=`, else the default one, gives the
  // columns and the starting filters, sort and rows per page; the URL still
  // wins, so a link keeps what it shows. A list whose views can't be read
  // works as before.
  const layersQuery = useQuery({
    queryKey: listViewsKey(id),
    queryFn: () => api.get(`config/list_view/layers?key=${encodeURIComponent(id)}`),
    staleTime: 60_000,
    retry: false,
  })
  const layers = layersQuery.data?.data
  const savedViews = collectViews(layers)
  const askedView = searchParams.get('view')
  const activeKey = askedView === 'none' ? null : savedViews.some((view) => view.key === askedView) ? askedView : defaultViewKey(layers)
  const activeView = savedViews.find((view) => view.key === activeKey) ?? null
  const viewSort = activeView?.sort ?? defaultSort
  const viewPerPage = PER_PAGE_OPTIONS.includes(activeView?.per_page) ? activeView.per_page : defaultPerPage
  const viewFilters = Object.fromEntries(filterNames.map((name) => [name, typeof activeView?.filters?.[name] === 'string' ? activeView.filters[name] : filterDefaults[name]]))
  const defaults = { search: '', sort: viewSort, page: '1', per_page: String(viewPerPage), ...viewFilters }

  const urlSearch = searchParams.get('search') ?? ''
  const sort = searchParams.get('sort') ?? viewSort
  const page = Math.max(1, Number.parseInt(searchParams.get('page') ?? '1', 10) || 1)
  const askedPerPage = Number.parseInt(searchParams.get('per_page') ?? '', 10)
  const perPage = PER_PAGE_OPTIONS.includes(askedPerPage) ? askedPerPage : viewPerPage
  const filters = Object.fromEntries(filterNames.map((name) => [name, searchParams.get(name) ?? viewFilters[name]]))

  // Two writes in one tick (the search and a typed filter settling together)
  // must both land: each builds on the previous write, not on the last render.
  const latest = useRef(searchParams)
  useEffect(() => {
    latest.current = searchParams
  }, [searchParams])

  /** Writes changes to the URL; defaults leave it, and the page returns to 1 unless it is the change. */
  const update = (changes, { replace = false } = {}) => {
    const next = new URLSearchParams(latest.current)
    for (const [name, value] of Object.entries(changes)) {
      const text = value == null ? '' : String(value)
      if (text === (defaults[name] ?? '')) next.delete(name)
      else next.set(name, text)
    }
    if (!('page' in changes)) next.delete('page')
    latest.current = next
    setSearchParams(next, { replace })
  }

  // Search: the box answers at once, the URL (and the API) after typing stops;
  // typing replaces the history entry instead of adding one per pause.
  const [searchInput, setSearchInput] = useTypedText(urlSearch, (term) => update({ search: term }, { replace: true }))

  // The API query: fixed params, filters, search and sort (the export's too), then the page.
  const apiParams = new URLSearchParams()
  for (const [name, value] of Object.entries(fixed)) if (value != null && value !== '') apiParams.set(name, String(value))
  for (const name of filterNames) if (filters[name] !== '') apiParams.set(name, filters[name])
  if (urlSearch) apiParams.set('search', urlSearch)
  if (sort) apiParams.set('sort', sort)
  const pageParams = new URLSearchParams(apiParams)
  pageParams.set('per_page', String(perPage))
  pageParams.set('page', String(page))
  const listQuery = pageParams.toString()

  const query = useQuery({
    queryKey: [...(queryKey ?? [id]), 'list', listQuery],
    queryFn: () => api.get(`${endpoint}?${listQuery}`),
    placeholderData: (previous) => previous,
    // A link to a view waits for the views; otherwise the list loads at once and
    // follows a default view's filters and sort when they arrive.
    enabled: !(askedView && askedView !== 'none' && layersQuery.isLoading),
  })
  const meta = query.data?.meta ?? {}
  const rows = query.data?.data ?? []
  const lastPage = Math.max(1, Number(meta.last_page) || 1)

  // A page past the end (rows archived meanwhile, an old link) moves to the last one.
  const pastTheEnd = !query.isPlaceholderData && Boolean(query.data) && page > lastPage
  useEffect(() => {
    if (pastTheEnd) update({ page: lastPage }, { replace: true })
  })

  // A sort the API refuses (a field the user's rules hide, RBAC-05, or an
  // old link): say why and fall back to the default order. The effect runs
  // on every render until the URL changes, so the toast has a fixed id: it
  // is shown once however many renders happen first.
  const sortRefused = query.error?.status === 422 && Boolean(query.error.errors?.sort) && sort !== defaultSort
  useEffect(() => {
    if (!sortRefused) return
    toast.error(query.error.errors.sort[0] ?? query.error.message, { id: `list-sort-refused-${id}` })
    update({ sort: defaultSort }, { replace: true })
  })

  // RBAC-05: a column built from a field the user's rules hide never shows, in any view.
  const fieldHidden = [...hiddenColumnKeys(layers), ...hiddenColumnKeys([{ payload: layersQuery.data?.meta?.defaults }])]
  const allowed = withoutHiddenColumns(allColumns, fieldHidden)

  // Columns without a view: hidden keys per user and list in this browser
  // (the choice made before saved views existed; still read as a fallback
  // and carried into the first view the user saves).
  const storageKey = columnsStorageKey(user?.id, id)
  const [hiddenState, setHiddenState] = useState(() => ({ key: storageKey, hidden: readHidden(storageKey) }))
  let saved = hiddenState.hidden
  if (hiddenState.key !== storageKey) {
    saved = readHidden(storageKey)
    setHiddenState({ key: storageKey, hidden: saved })
  }

  // Columns with a view: its order and choice (LAY-07 merge), plus what the
  // user switched since, kept until they save the view or pick another.
  const viewed = activeView ? applyView(allowed, activeView) : null
  const [override, setOverride] = useState({ key: '', hidden: null, order: null })
  const current = override.key === (activeKey ?? '') ? override : { hidden: null, order: null }
  const unsaved = activeView ? current.hidden : null
  const columns = inOrder(viewed ? viewed.columns : allowed, current.order)
  const hidden = viewed ? (unsaved ?? viewed.hidden) : (saved ?? allowed.filter((column) => column.defaultHidden).map((column) => column.key))

  const hideable = (column) => column.hideable !== false
  const shown = columns.filter((column) => !hideable(column) || !hidden.includes(column.key))
  // At least one column always shows.
  const visibleColumns = shown.length ? shown : columns.slice(0, 1)
  const visibleKeys = visibleColumns.map((column) => column.key)

  const isColumnVisible = (key) => visibleKeys.includes(key)
  /** Whether the user may switch this column (never a fixed one, never the last one showing). */
  const canToggleColumn = (key) => {
    const column = columns.find((entry) => entry.key === key)
    if (!column || !hideable(column)) return false
    return !(isColumnVisible(key) && visibleColumns.length <= 1)
  }
  const toggleColumn = (key) => {
    if (!canToggleColumn(key)) return
    const next = isColumnVisible(key) ? [...hidden, key] : hidden.filter((entry) => entry !== key)
    const known = next.filter((entry, index) => next.indexOf(entry) === index && columns.some((column) => column.key === entry))
    if (activeView) {
      setOverride({ key: activeKey, hidden: known, order: current.order })
      return
    }
    writeHidden(storageKey, known)
    setHiddenState({ key: storageKey, hidden: known })
  }

  /** Opens a saved view (null: the list's own layout): its filters, sort and columns replace what the URL held. */
  const selectView = (key) => {
    const next = new URLSearchParams(latest.current)
    for (const name of ['search', 'sort', 'page', 'per_page', 'view', ...filterNames]) next.delete(name)
    const fallback = defaultViewKey(layers)
    if (key === null && fallback !== null) next.set('view', 'none')
    else if (key !== null && key !== fallback) next.set('view', key)
    latest.current = next
    setOverride({ key: '', hidden: null, order: null })
    setSearchParams(next)
  }
  /** The columns in a new order (their keys); kept until the view is saved or another is opened. */
  const setColumnOrder = (keys) => setOverride({ key: activeKey ?? '', hidden: current.hidden, order: keys })
  const viewChanged = Boolean(activeView) && (unsaved !== null || current.order !== null ||['sort', 'per_page', ...filterNames].some((name) => searchParams.has(name)))

  // Export (EXP-01): the same query, every matching row, the visible columns in order.
  const exporter = useListExport({ id, endpoint })
  const exportTo = (format) => exporter.exportTo(format, apiParams, visibleColumns.map(exportKeyOf).filter(Boolean))

  return {
    search: searchInput,
    setSearch: setSearchInput,
    term: urlSearch,
    filters,
    /** Each filter's default value: a filter is active when its value differs. */
    filterDefaults: { ...filterDefaults },
    setFilter: (name, value, options) => update({ [name]: value }, options),
    /** Several filters in one URL write (Clear filters), e.g. `{ type: '', category: '' }`. */
    setFilters: (changes, options) => update(changes, options),
    sort,
    setSort: (next) => update({ sort: next ?? '' }),
    /** Header clicks: ascending, then descending, then the list's default order. */
    toggleSort: (sortKey) => update({ sort: sort === sortKey ? `-${sortKey}` : sort === `-${sortKey}` ? viewSort : sortKey }),
    page,
    lastPage,
    setPage: (next) => update({ page: Math.min(Math.max(1, next), lastPage) }),
    perPage,
    setPerPage: (next) => update({ per_page: next }),
    query,
    rows,
    meta,
    columns,
    visibleColumns,
    isColumnVisible,
    canToggleColumn,
    toggleColumn,
    setColumnOrder,
    exportTo,
    exporting: exporter.exporting,
    /** LAY-04: the list's id (its views' key), the keys not shown, and its saved views. */
    id,
    hiddenColumns: hidden,
    defaultSort,
    views: {
      all: savedViews,
      active: activeView,
      /** True once the user changed columns, filters, sort or rows per page since opening the view. */
      changed: viewChanged,
      select: selectView,
      /** After saving or deleting: read the views again, then open `key` (null: the list's own layout). */
      refresh: async (key) => {
        await queryClient.invalidateQueries({ queryKey: listViewsKey(id) })
        if (key !== undefined) selectView(key)
      },
    },
  }
}

/** The export's failure in words: the API's own sentence for refusals, ours for throttling. */
function exportError(error, t) {
  if (error?.status === 429) return t('ds.listView.exportTooOften')
  if ((error?.status === 422 || error?.status === 403) && error.message) return error.message
  return errorMessage(error) ?? t('errors.generic')
}
