import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { api } from '@/api/client'
import { fromShell } from '@/api/shellBootstrap'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { useLocale } from '@/lib/useLocale'

export const ONBOARDING_KEY = ['onboarding']
export const CHECKLIST_KEY = ['onboarding', 'checklist']
export const TRIAL_KEY = ['onboarding', 'trial']

/** ONB-05: the setup wizard's steps, in order (the API's Onboarding::STEPS). */
export const SETUP_STEPS = ['company', 'currencies', 'branches', 'taxes', 'payment_methods', 'items', 'users', 'devices']

/** The screens of onboarding, as the stage tracker names them. */
export const STAGES = ['business', 'modules', 'setup']

/**
 * ONB-02..ONB-05: the tenant's onboarding (needs core.onboarding.view). The
 * answer holds words in the request's language (business type names), so a
 * language switch fetches it again (L10N-01).
 */
export function useOnboarding({ enabled = true } = {}) {
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const locale = useLocale()
  const shown = useRef(locale)
  useEffect(() => {
    if (shown.current === locale) return
    shown.current = locale
    queryClient.invalidateQueries({ queryKey: ONBOARDING_KEY, exact: true })
  }, [locale, queryClient])
  return useQuery({
    queryKey: ONBOARDING_KEY,
    queryFn: async () => (await api.get('onboarding')).data,
    enabled: enabled && can('core.onboarding.view'),
  })
}

/** A write that answers the new onboarding state; the cache takes it. */
export function useOnboardingWrite(mutationFn, options = {}) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    ...options,
    onSuccess: (response, ...rest) => {
      if (response?.data) queryClient.setQueryData(ONBOARDING_KEY, response.data)
      queryClient.invalidateQueries({ queryKey: CHECKLIST_KEY })
      queryClient.invalidateQueries({ queryKey: TRIAL_KEY })
      // Modules may have been switched on (RBAC-08): menus and permissions follow.
      queryClient.invalidateQueries({ queryKey: ['me', 'permissions'] })
      options.onSuccess?.(response, ...rest)
    },
  })
}

/**
 * ONB-04: the trial state and days left, for every signed-in user. Billing
 * answers the same endpoint (GET onboarding/trial) once it implements the
 * trial provider, so the banner needs no change.
 */
export function useTrialStatus() {
  const { token, user } = useAuth()
  return useQuery({
    queryKey: TRIAL_KEY,
    queryFn: async () => (await fromShell('trial', () => api.get('onboarding/trial'))).data,
    enabled: Boolean(token && user),
    staleTime: 5 * 60_000,
  })
}

/** ONB-07: the getting-started checklist. */
export function useChecklist() {
  const { can } = usePermissions()
  return useQuery({
    queryKey: CHECKLIST_KEY,
    queryFn: async () => (await api.get('onboarding/checklist')).data,
    enabled: can('core.onboarding.view'),
  })
}

/** Where an unfinished onboarding resumes, or null once the owner reached setup. */
export function resumePath(state) {
  if (!state) return null
  if (state.stage === 'business') return '/onboarding/business'
  if (state.stage === 'modules') return '/onboarding/modules'
  return null
}
