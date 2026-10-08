// The approvals inbox (APR-03, APR-04, APR-06): the waiting count the
// sidebar shows, one request's detail, the actions on it and the user's
// delegations. Every query key starts with "approvals", so one invalidation
// refreshes the count, the list and the detail together; actions refresh
// the notification bell too.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'
import { NOTIFICATIONS_KEY } from './notifications'

export const APPROVALS_KEY = ['approvals']
export const WAITING_COUNT_KEY = ['approvals', 'waiting-count']
export const DELEGATIONS_KEY = ['approvals', 'delegations']
export const detailKey = (id) => ['approvals', 'detail', id]

/** Who may see every request in the organisation (APR-04) and reassign one (APR-06). */
export const VIEW_ALL = 'core.approval.view_all'
export const REASSIGN = 'core.approval.reassign'

/** How often the sidebar asks how many approvals wait. */
export const WAITING_POLL_MS = 60_000

/** The number of requests waiting for the user (the list's total, one row fetched). */
export function useWaitingCount({ enabled = true } = {}) {
  return useQuery({
    queryKey: WAITING_COUNT_KEY,
    queryFn: () => api.get('approvals?status=waiting&per_page=1&page=1'),
    select: (response) => Math.max(0, Number(response?.meta?.total) || 0),
    enabled,
    refetchInterval: WAITING_POLL_MS,
    refetchOnWindowFocus: true,
  })
}

export function useApprovalDetail(id) {
  return useQuery({ queryKey: detailKey(id), queryFn: () => api.get(`approvals/${id}`), enabled: Boolean(id), select: (response) => response?.data ?? null })
}

/**
 * The actions on one request. Each answers the request's detail as the
 * user now sees it: the detail is replaced, the list, count and bell refresh.
 */
export function useApprovalActions(id) {
  const queryClient = useQueryClient()
  const refresh = (response) => {
    if (response?.data) queryClient.setQueryData(detailKey(id), { data: response.data })
    queryClient.invalidateQueries({ queryKey: APPROVALS_KEY, predicate: (query) => query.queryKey[1] !== 'detail' || query.queryKey[2] !== id })
    queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY })
  }
  const options = (action) => ({ mutationFn: (body) => api.post(`approvals/${id}/${action}`, body), onSuccess: refresh })
  const approve = useMutation(options('approve'))
  const reject = useMutation(options('reject'))
  const sendBack = useMutation(options('return'))
  const requestInfo = useMutation(options('request-info'))
  const comment = useMutation(options('comment'))
  const reassign = useMutation(options('reassign'))
  return { approve, reject, return: sendBack, requestInfo, comment, reassign, refresh }
}

/** Approves several requests; each succeeds or fails on its own (APR-04). */
export function useBulkApprove() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ ids, comment }) => api.post('approvals/bulk-approve', comment ? { ids, comment } : { ids }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: APPROVALS_KEY })
      queryClient.invalidateQueries({ queryKey: NOTIFICATIONS_KEY })
    },
  })
}

/** The user's delegations, given and received (APR-06). */
export function useDelegations({ enabled = true } = {}) {
  return useQuery({ queryKey: DELEGATIONS_KEY, queryFn: () => api.get('me/delegations'), select: (response) => response?.data ?? [], enabled })
}

/**
 * A request's state for the viewer, as a StatusBadge tone and the words
 * to show: blocked, overdue, delegated, waiting for the viewer, or the
 * outcome once decided.
 */
export function approvalStatus(item, t) {
  if (!item) return null
  if (item.status === 'pending') {
    if (item.blocked_reason) return { tone: 'danger', label: t('approvals.state.blocked', { reason: blockedWord(item, t) }) }
    if (item.overdue) return { tone: 'danger', label: t('approvals.state.overdue') }
    const from = item.my_assignment?.delegated_from
    if (from && item.can?.approve) return { tone: 'warning', label: t('approvals.state.delegatedFrom', { name: from.name ?? t('approvals.someone') }) }
    if (item.can?.approve) return { tone: 'warning', label: t('approvals.state.waitingForYou') }
    return { tone: 'neutral', label: t('approvals.state.waiting') }
  }
  const tones = { approved: 'success', rejected: 'danger', returned: 'warning' }
  const known = ['approved', 'rejected', 'returned', 'cancelled', 'expired']
  return { tone: tones[item.status] ?? 'neutral', label: t(`approvals.state.${known.includes(item.status) ? item.status : 'expired'}`) }
}

function blockedWord(item, t) {
  return item.blocked_reason === 'no_approver' ? t('approvals.blocked.noApprover') : t('approvals.blocked.other')
}

/** A delegation's state as a StatusBadge tone. */
export const DELEGATION_TONES = { active: 'success', scheduled: 'info', ended: 'neutral', revoked: 'neutral' }
