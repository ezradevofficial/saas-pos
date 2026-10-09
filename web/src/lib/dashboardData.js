// LAY-01: the dashboard's data: the resolved dashboard, its default, and each widget's source.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

export const WIDGET_TYPES = ['kpi', 'chart', 'list', 'shortcut', 'approval_count']

export const DASHBOARD_KEY = ['config', 'dashboard', 'resolved']

/** The dashboard when none is published (as the API's default): getting started and the user's approvals. */
export const DEFAULT_DASHBOARD = {
  title: null,
  widgets: [
    { id: 'start', type: 'shortcut', source: 'shortcuts', params: { links: ['/settings/organisation', '/settings/users', '/settings/appearance'] }, title: null, x: 0, y: 0, w: 6, h: 4 },
    { id: 'waiting', type: 'approval_count', source: 'approvals.waiting', params: {}, title: null, x: 6, y: 0, w: 6, h: 1 },
    { id: 'mine', type: 'list', source: 'approvals.mine', params: { limit: 5 }, title: null, x: 6, y: 1, w: 6, h: 3 },
  ],
}

/** `source` params as a query string (`links[]=` for lists). */
export function sourceQuery(params = {}) {
  const query = new URLSearchParams()
  for (const [name, value] of Object.entries(params ?? {})) {
    if (Array.isArray(value)) for (const entry of value) query.append(`${name}[]`, String(entry))
    else if (value !== null && value !== undefined && value !== '') query.set(name, String(value))
  }
  return query.toString()
}

/** A widget's data from its source; only what the reader reaches (the API decides). */
export function useWidgetData(widget, { enabled = true } = {}) {
  const query = sourceQuery(widget.params)
  return useQuery({
    queryKey: ['dashboard', 'source', widget.source, query],
    queryFn: () => api.get(`dashboard/sources/${widget.source}${query ? `?${query}` : ''}`),
    select: (response) => response?.data ?? null,
    enabled: enabled && Boolean(widget.source),
    staleTime: 30_000,
    retry: false,
  })
}

/** The title a widget shows: its own (typed once), else its source's name. */
export function widgetTitle(widget, t) {
  return widget.title || t(`layouts.sources.${String(widget.source).replace(/\./g, '_')}`, { defaultValue: t('layouts.widgets.untitled') })
}

/** A new widget's id, unique on the dashboard. */
export function newWidgetId(widgets) {
  let n = widgets.length + 1
  while (widgets.some((widget) => widget.id === `w${n}`)) n += 1
  return `w${n}`
}

/**
 * The dashboard that applies to the signed-in user (their own copy, else
 * their role's, else the organisation's, else the default one), with
 * widgets they may not open already removed by the API. An unreadable
 * dashboard falls back to the default (LAY-07).
 */
export function useDashboard() {
  const query = useQuery({ queryKey: DASHBOARD_KEY, queryFn: () => api.get('config/dashboard/resolved'), retry: false, staleTime: 30_000 })
  const payload = query.data?.data?.payload
  return {
    dashboard: payload?.widgets ? payload : query.isPending ? null : DEFAULT_DASHBOARD,
    source: query.data?.data?.source ?? null,
    isLoading: query.isPending,
  }
}
