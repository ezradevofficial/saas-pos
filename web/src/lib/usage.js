import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { fromShell } from '@/api/shellBootstrap'
import { usePermissions } from '@/auth/usePermissions'

export const USAGE_KEY = ['usage']

/** Status dot tone per unit state (LIM-01..LIM-03): a dot and a word, never a filled pill. */
export const USAGE_TONES = { within: 'success', over: 'warning', blocked: 'danger', unlimited: 'neutral' }

/**
 * LIM-01, LIM-02: GET usage — each unit's count against the plan, open
 * overages and the extra monthly cost. Owners only (the API refuses others,
 * so the query does not run for them).
 */
export function useUsage() {
  const { isOwner, isLoading } = usePermissions()
  const query = useQuery({
    queryKey: USAGE_KEY,
    queryFn: () => fromShell('usage', () => api.get('usage')),
    enabled: !isLoading && isOwner,
    staleTime: 60_000,
  })
  return { ...query, usage: query.data?.data ?? null, isOwner }
}

/** The units above the plan (over, or blocked from adding more). */
export function unitsOver(usage) {
  return (usage?.units ?? []).filter((unit) => unit.state === 'over' || unit.state === 'blocked')
}
