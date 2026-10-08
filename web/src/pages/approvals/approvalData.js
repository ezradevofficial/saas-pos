// Shared lookups for the approvals pages: document types for the filters
// and delegations, and the active people for the reassign and delegate pickers.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { WORKFLOW_VIEW } from '@/layouts/navigation'
import { formatDateTime } from '@/lib/dates'

/**
 * Document types to choose from: the registry (workflow/document-types,
 * readable with a workflow permission) plus any type seen in `rows`, so a
 * user without workflow access can still filter by what they receive.
 */
export function useDocumentTypeOptions(rows = []) {
  const { can, isLoading } = usePermissions()
  const enabled = !isLoading && can(WORKFLOW_VIEW)
  const query = useQuery({ queryKey: ['workflow', 'document-types'], queryFn: () => api.get('workflow/document-types'), staleTime: 300_000, enabled })
  const options = new Map()
  for (const type of query.data?.data ?? []) options.set(type.key, type.label ?? type.key)
  for (const row of rows) {
    const type = row?.document?.type
    if (type && !options.has(type)) options.set(type, row.document.type_label ?? type)
  }
  return [...options].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label))
}

/** Active users of the tenant (GET users?status=active&per_page=200), for people pickers. */
export function useActiveUsers({ enabled = true } = {}) {
  return useQuery({
    queryKey: ['users', 'options', 'active'],
    queryFn: () => api.get('users?status=active&per_page=200'),
    select: (response) => response?.data ?? [],
    enabled,
  })
}

/** People-picker options: name, with the email or phone to tell namesakes apart. */
export function peopleOptions(users, { exclude = [] } = {}) {
  return users
    .filter((user) => !exclude.includes(user.id))
    .map((user) => ({ value: user.id, label: user.email || user.phone ? `${user.name} · ${user.email ?? user.phone}` : user.name }))
}

/** Each tab and the API parameters it sends (APR-04). */
export const TABS = {
  waiting: { status: 'waiting' },
  decided: { status: 'decided' },
  all: { view: 'all', status: 'all' },
}

/** When a request is due, or when it escalates, in words. */
export function dueHint(item, t, locale) {
  if (item.status !== 'pending') return item.decided_at ? t('approvals.hint.decided', { when: formatDateTime(item.decided_at, locale) }) : null
  if (item.escalation?.at) {
    const when = formatDateTime(item.escalation.at, locale)
    return item.escalation.to ? t('approvals.hint.escalatesTo', { to: item.escalation.to, when }) : t('approvals.hint.escalates', { when })
  }
  if (item.due_at) return t('approvals.hint.due', { when: formatDateTime(item.due_at, locale) })
  return null
}
