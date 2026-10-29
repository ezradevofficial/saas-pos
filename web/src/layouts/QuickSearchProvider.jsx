import { useQuery } from '@tanstack/react-query'
import { createContext, lazy, Suspense, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { useAuth } from '@/auth/useAuth'
import { usePermissions } from '@/auth/usePermissions'
import { loadRecents, saveRecent } from '@/lib/quickSearch'
import { useLocale } from '@/lib/useLocale'
import { ACTIONS, EXTRA_PAGES, PAGE_KEYWORDS } from '@/searchRegistry'
import { useCompanies } from './companySelection'
import { NAV_GROUPS, visibleGroups } from './navigation'
import { useNavigation } from './useNavigation'

// NFR-02: the palette (cmdk and its lists) loads the first time it opens, not with the entry bundle.
const QuickSearch = lazy(() => import('@/components/ds/QuickSearch').then((m) => ({ default: m.QuickSearch })))

const QuickSearchContext = createContext({ openSearch: () => {} })

/** Opens the quick search (the sidebar box and the phone top bar use it). */
export function useQuickSearch() {
  return useContext(QuickSearchContext)
}

/** True for a key press that happens while the user types in a field. */
function typingInField(target) {
  const tag = target?.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target?.isContentEditable === true
}

/**
 * What the palette can offer this user (LAY-01, RBAC-08): the pages of their
 * resolved sidebar, the registry's extra pages and "new" actions, all through
 * the same `visibleGroups` filter as the sidebar, so permissions and active
 * modules apply identically. Help topics come once the palette has opened.
 */
function useEntries(helpEnabled) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { groups } = useNavigation()
  const permissions = usePermissions()
  const { companies, ready } = useCompanies()
  const hasCompany = ready && companies.length > 0

  const topics = useQuery({
    queryKey: ['manual', 'topics', locale],
    queryFn: () => api.get(`manual/topics?locale=${locale}`),
    enabled: helpEnabled && !permissions.isLoading,
    staleTime: 5 * 60_000,
    retry: false,
  })

  return useMemo(() => {
    const groupLabel = (id) => (groups.find((group) => group.id === id) ?? NAV_GROUPS.find((group) => group.id === id))?.label(t) ?? ''
    const context = { ...permissions, hasCompany }
    const allowed = (items) => visibleGroups([{ id: 'registry', label: () => '', items }], context)[0]?.items ?? []

    const pages = groups.flatMap((group) =>
      group.items.map((item) => ({ id: `page:${item.to}`, kind: 'page', label: item.label(t), group: group.label(t), keywords: PAGE_KEYWORDS[item.to] ?? '', to: item.to })),
    )
    const known = new Set(pages.map((page) => page.to))
    const extras = allowed(EXTRA_PAGES)
      .filter((item) => !known.has(item.to))
      .map((item) => ({ id: `page:${item.to}`, kind: 'page', label: item.label(t), group: groupLabel(item.group), keywords: item.keywords, to: item.to }))
    const actions = allowed(ACTIONS).map((item) => ({ id: `action:${item.to}`, kind: 'action', label: item.label(t), group: groupLabel(item.group), keywords: item.keywords, to: item.to }))
    const help = (topics.data?.data ?? []).map((topic) => ({
      id: `help:${topic.id}`,
      kind: 'help',
      label: topic.title,
      group: topic.module_label ?? '',
      keywords: '',
      to: `/help?topic=${encodeURIComponent(topic.id)}`,
    }))
    return { pages: [...pages, ...extras], actions, help }
  }, [groups, permissions, hasCompany, topics.data, t])
}

/**
 * Wraps the back office with the quick search: Ctrl+K or ⌘K opens it from
 * anywhere, "/" when focus is not in a field. Remembers the user's last five
 * destinations on this device (storage errors are ignored).
 */
export function QuickSearchProvider({ children }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [everOpened, setEverOpened] = useState(false)
  const [stored, setStored] = useState(() => loadRecents(user?.id))
  const { pages, actions, help } = useEntries(everOpened)

  const openSearch = useCallback(() => {
    setEverOpened(true)
    setOpen(true)
  }, [])

  useEffect(() => {
    const onKeyDown = (event) => {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setEverOpened(true)
        setOpen((current) => !current)
      } else if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !typingInField(event.target) && !event.defaultPrevented) {
        event.preventDefault()
        openSearch()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [openSearch])

  const all = useMemo(() => [...pages, ...actions, ...help], [pages, actions, help])

  // The destinations the user visited, by any route into them, are the recents.
  const here = `${location.pathname}${location.search}`
  useEffect(() => {
    const match = all.find((entry) => entry.kind !== 'help' && entry.to === location.pathname) ?? all.find((entry) => entry.kind === 'help' && entry.to === here)
    if (match && match.to !== '/') setStored((current) => (current[0]?.to === match.to ? current : saveRecent(user?.id, { to: match.to })))
  }, [all, here, location.pathname, user?.id])

  // Only destinations the user can still open are shown, with today's label.
  const recents = useMemo(() => stored.map((item) => all.find((entry) => entry.to === item.to)).filter(Boolean), [stored, all])

  const select = (entry) => {
    setOpen(false)
    navigate(entry.to)
  }

  const value = useMemo(() => ({ openSearch }), [openSearch])

  return (
    <QuickSearchContext.Provider value={value}>
      {children}
      {open ? (
        <Suspense fallback={null}>
          <QuickSearch open={open} onClose={() => setOpen(false)} pages={pages} actions={actions} help={help} recents={recents} onSelect={select} />
        </Suspense>
      ) : null}
    </QuickSearchContext.Provider>
  )
}
