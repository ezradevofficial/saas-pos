// Shared lookups for the approvals pages: document types for the filters
// and delegations, the people an approval may be reassigned or delegated
// to, and times shown in the company's time zone.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { formatCompanyTime } from '@/lib/companyTime'
import { useDebounced } from '@/lib/useDebounced'

/**
 * Document types that can have approvals (GET approvals/document-types,
 * open to every user), plus any type seen in `rows` in case the registry
 * has not loaded yet.
 */
export function useDocumentTypeOptions(rows = []) {
  const query = useQuery({ queryKey: ['approvals', 'document-types'], queryFn: () => api.get('approvals/document-types'), staleTime: 300_000 })
  const options = new Map()
  for (const type of query.data?.data ?? []) options.set(type.key, type.label ?? type.key)
  for (const row of rows) {
    const type = row?.document?.type
    if (type && !options.has(type)) options.set(type, row.document.type_label ?? type)
  }
  return [...options].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Who a request may be reassigned to (APR-06): the API already leaves out
 * the requester and the step's current and past approvers. Asked only when
 * the reassign form opens (the user may reassign).
 */
export function useReassignCandidates(id, { enabled = true } = {}) {
  return useQuery({
    queryKey: ['approvals', 'reassign-candidates', id],
    queryFn: () => api.get(`approvals/${id}/reassign-candidates`),
    select: (response) => response?.data ?? [],
    enabled: enabled && Boolean(id),
  })
}

/** Colleagues the user may delegate to (APR-06), searched on the server as they type (at most 50). */
export function useDelegationCandidates(search, { enabled = true } = {}) {
  const term = useDebounced(search.trim(), 250)
  return useQuery({
    queryKey: ['approvals', 'delegation-candidates', term],
    queryFn: () => api.get(`me/delegation-candidates${term ? `?search=${encodeURIComponent(term)}` : ''}`),
    select: (response) => response?.data ?? [],
    placeholderData: (previous) => previous,
    enabled,
  })
}

/** People-picker options from {id, name} rows, minus `exclude`. */
export function peopleOptions(people, { exclude = [] } = {}) {
  return people.filter((person) => !exclude.includes(person.id)).map((person) => ({ value: person.id, label: person.name ?? person.id }))
}

/** An instant in the request's company time zone (the app's convention, see lib/companyTime). */
export { formatCompanyTime }

/** Each tab and the API parameters it sends (APR-04). */
export const TABS = {
  waiting: { status: 'waiting' },
  decided: { status: 'decided' },
  all: { view: 'all', status: 'all' },
}

/** When a request is due, or when it escalates, in words (company time). */
export function dueHint(item, t, locale) {
  const at = (value) => formatCompanyTime(value, locale, item.company)
  if (item.status !== 'pending') return item.decided_at ? t('approvals.hint.decided', { when: at(item.decided_at) }) : null
  if (item.escalation?.at) {
    const when = at(item.escalation.at)
    return item.escalation.to ? t('approvals.hint.escalatesTo', { to: item.escalation.to, when }) : t('approvals.hint.escalates', { when })
  }
  if (item.due_at) return t('approvals.hint.due', { when: at(item.due_at) })
  return null
}
