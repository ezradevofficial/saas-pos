// A server list's state and data (EXP-01, LAY-04): search, filters, sort,
// page and rows per page live in the URL so a list can be shared or
// bookmarked; the user's column choice lives in localStorage per user and
// list; exports download the same query as a file with the bearer token.
import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { useAuth } from '@/auth/AuthProvider'

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

/** A column's key in the export: `exportKey`, else its own key; null or false leaves it out. */
export const exportKeyOf = (column) => (column.exportKey === undefined ? column.key : column.exportKey || null)

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
 */
export function useTypedText(urlValue, write, delay = 300) {
  // draft: what the box shows; base: the URL value it was typed over; target: what it will write.
  const [entry, setEntry] = useState(null)
  const timer = useRef(null)
  useEffect(() => () => clearTimeout(timer.current), [])
  const value = entry && (urlValue === entry.base || urlValue === entry.target) ? entry.draft : urlValue
  const change = (next) => {
    const target = next.trim()
    setEntry({ draft: next, base: urlValue, target })
    clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      setEntry((current) => (current ? { ...current, base: target } : current))
      write(target)
    }, delay)
  }
  return [value, change]
}

/**
 * @param {object} options
 * @param {string} options.id the list's id: column storage and the fallback file name
 * @param {string} options.endpoint the API path, e.g. "items"
 * @param {Record<string, string>} [options.params] fixed API params not kept in the URL (e.g. a role)
 * @param {Record<string, string>} [options.filters] filter name -> default value (kept in the URL)
 * @param {string} [options.defaultSort] "key" or "-key"; empty leaves the order to the API
 * @param {number} [options.defaultPerPage]
 * @param {Array} [options.columns] DataTable columns plus `sortKey`, `exportKey`, `hideable`, `defaultHidden`
 * @param {Array} [options.queryKey] query key prefix (default [id]); "list" and the query follow
 */
export function useServerList({ id, endpoint, params: fixed = {}, filters: filterDefaults = {}, defaultSort = '', defaultPerPage = 25, columns = [], queryKey }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const defaults = { search: '', sort: defaultSort, page: '1', per_page: String(defaultPerPage), ...filterDefaults }

  const urlSearch = searchParams.get('search') ?? ''
  const sort = searchParams.get('sort') ?? defaultSort
  const page = Math.max(1, Number.parseInt(searchParams.get('page') ?? '1', 10) || 1)
  const askedPerPage = Number.parseInt(searchParams.get('per_page') ?? '', 10)
  const perPage = PER_PAGE_OPTIONS.includes(askedPerPage) ? askedPerPage : defaultPerPage
  const filterNames = Object.keys(filterDefaults)
  const filters = Object.fromEntries(filterNames.map((name) => [name, searchParams.get(name) ?? filterDefaults[name]]))

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
  // old link): say why and fall back to the default order.
  const sortRefused = query.error?.status === 422 && Boolean(query.error.errors?.sort) && sort !== defaultSort
  useEffect(() => {
    if (!sortRefused) return
    toast.error(query.error.errors.sort[0] ?? query.error.message)
    update({ sort: defaultSort }, { replace: true })
  })

  // Columns: hidden keys per user and list (LAY-04, a personal choice).
  const storageKey = columnsStorageKey(user?.id, id)
  const [hiddenState, setHiddenState] = useState(() => ({ key: storageKey, hidden: readHidden(storageKey) }))
  let saved = hiddenState.hidden
  if (hiddenState.key !== storageKey) {
    saved = readHidden(storageKey)
    setHiddenState({ key: storageKey, hidden: saved })
  }
  const hidden = saved ?? columns.filter((column) => column.defaultHidden).map((column) => column.key)

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
    writeHidden(storageKey, known)
    setHiddenState({ key: storageKey, hidden: known })
  }

  // Export (EXP-01): the same query, every matching row, the visible columns in order.
  const [exporting, setExporting] = useState(null)
  const exportTo = async (format) => {
    if (exporting) return
    const params = new URLSearchParams(apiParams)
    params.set('format', format)
    for (const column of visibleColumns) {
      const key = exportKeyOf(column)
      if (key) params.append('columns[]', key)
    }
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

  return {
    search: searchInput,
    setSearch: setSearchInput,
    term: urlSearch,
    filters,
    setFilter: (name, value, options) => update({ [name]: value }, options),
    sort,
    setSort: (next) => update({ sort: next ?? '' }),
    /** Header clicks: ascending, then descending, then the list's default order. */
    toggleSort: (sortKey) => update({ sort: sort === sortKey ? `-${sortKey}` : sort === `-${sortKey}` ? defaultSort : sortKey }),
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
    exportTo,
    exporting,
  }
}

/** The export's failure in words: the API's own sentence for refusals, ours for throttling. */
function exportError(error, t) {
  if (error?.status === 429) return t('ds.listView.exportTooOften')
  if ((error?.status === 422 || error?.status === 403) && error.message) return error.message
  return errorMessage(error) ?? t('errors.generic')
}
