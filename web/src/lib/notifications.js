// In-app notifications (NOT-01): the unread count the bell polls, the
// latest few for its popover, and the read/archive actions the bell and the
// inbox share. Every query key starts with "notifications", so one
// invalidation refreshes the count, the popover and the inbox together.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from '@/api/client'

export const NOTIFICATIONS_KEY = ['notifications']
export const UNREAD_COUNT_KEY = ['notifications', 'unread-count']
export const LATEST_KEY = ['notifications', 'latest']

/** How often the bell asks for the unread count. */
export const UNREAD_POLL_MS = 60_000
/** How many notifications the bell's popover shows. */
export const LATEST_LIMIT = 8

/** The count from GET notifications/unread-count (`{data: {unread}}`; `{count}` accepted too). */
export function unreadOf(response) {
  const value = response?.data?.unread ?? response?.data?.count ?? response?.count ?? response?.unread ?? 0
  return Math.max(0, Number(value) || 0)
}

/**
 * The signed-in user's unread count: polled every minute and again when
 * the window regains focus (NOT-01).
 */
export function useUnreadCount({ enabled = true } = {}) {
  const query = useQuery({
    queryKey: UNREAD_COUNT_KEY,
    queryFn: () => api.get('notifications/unread-count'),
    select: unreadOf,
    enabled,
    refetchInterval: UNREAD_POLL_MS,
    refetchOnWindowFocus: true,
    staleTime: 0,
  })
  const { refetch } = query
  useEffect(() => {
    if (!enabled) return undefined
    const onFocus = () => refetch()
    window.addEventListener('focus', onFocus)
    return () => window.removeEventListener('focus', onFocus)
  }, [enabled, refetch])
  return query
}

/** The latest notifications for the popover: unread first, then the newest read ones. */
export async function fetchLatest() {
  const unread = await api.get(`notifications?status=unread&per_page=${LATEST_LIMIT}&page=1`)
  const rows = [...(unread?.data ?? [])]
  if (rows.length < LATEST_LIMIT) {
    const recent = await api.get(`notifications?status=active&per_page=${LATEST_LIMIT}&page=1`)
    const seen = new Set(rows.map((row) => row.id))
    rows.push(...(recent?.data ?? []).filter((row) => row.read_at && !seen.has(row.id)))
  }
  return rows.slice(0, LATEST_LIMIT)
}

export function useLatestNotifications({ enabled }) {
  return useQuery({ queryKey: LATEST_KEY, queryFn: fetchLatest, enabled, staleTime: 0 })
}

/** Mark read, mark all read and archive; each refreshes every notification query. */
export function useNotificationActions() {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY })
  const markRead = useMutation({ mutationFn: (notification) => api.post(`notifications/${notification.id}/read`), onSuccess: refresh })
  const markAllRead = useMutation({
    mutationFn: () => api.post('notifications/read-all'),
    onSuccess: () => {
      queryClient.setQueryData(UNREAD_COUNT_KEY, { data: { unread: 0 } })
      return refresh()
    },
  })
  const archive = useMutation({ mutationFn: (notification) => api.post(`notifications/${notification.id}/archive`), onSuccess: refresh })
  return { markRead, markAllRead, archive }
}

/**
 * The app path a notification opens, or null. Only paths inside the app
 * ("/settings/users/…") are followed; anything else is ignored, so a link
 * can never take the user to another site.
 */
export function notificationPath(notification) {
  const link = notification?.link
  if (typeof link !== 'string') return null
  return link.startsWith('/') && !link.startsWith('//') ? link : null
}
