import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { useCompanySelection } from '@/layouts/companySelection'

/**
 * Where the theme is resolved for a signed-in user (BR-08): the company
 * chosen in the switcher when one of their roles covers it, else the first
 * branch or location they work at, else nowhere (the tenant's theme). The
 * API checks the place again.
 */
export function themePlace(permissions, companyId) {
  const scopes = permissions.flatMap((permission) => permission.scopes ?? [])
  const has = (type, id) => scopes.some((scope) => scope.type === type && (id === undefined || scope.id === id))
  if (companyId && (has('tenant') || has('company', companyId))) return { company: companyId }
  for (const type of ['branch', 'location']) {
    const scope = scopes.find((one) => one.type === type)
    if (scope) return { [type]: scope.id }
  }
  return {}
}

const fromResolved = (payload, user) => ({
  theme: payload ?? null,
  assets: payload?.asset_urls ?? {},
  welcome: null,
  tenantName: user?.tenant?.name ?? null,
  hidePlatform: Boolean(user?.tenant?.hide_platform),
})

const fromPublic = (data) =>
  data
    ? {
        theme: data.theme ?? null,
        assets: { logo_light: data.logo_light_url, logo_dark: data.logo_dark_url, favicon: data.favicon_url, background: data.background_url },
        welcome: data.welcome ?? null,
        tenantName: data.tenant_name ?? null,
        hidePlatform: Boolean(data.hide_platform),
      }
    : null

/**
 * The brand to show (BR-02, BR-04, BR-07, BR-08):
 * - signed in: the published theme resolved for the user's company and
 *   branch (GET config/theme/resolved), fetched at sign-in and again when
 *   the company changes;
 * - signed out: the public branding of the host the page is served on
 *   (GET public/branding?host=), or null for the platform default.
 * Returns { theme, assets, welcome, tenantName, hidePlatform } or null.
 */
export function useBrand() {
  const { token, user, enrolmentRequired } = useAuth()
  const signedIn = Boolean(token && user && !enrolmentRequired)
  const { permissions, isLoading } = usePermissions()
  const { value: companyValue } = useCompanySelection()
  const companyId = companyValue && companyValue !== 'all' ? companyValue : null
  const place = useMemo(() => themePlace(permissions, companyId), [permissions, companyId])

  const resolved = useQuery({
    queryKey: ['config', 'theme', 'resolved', user?.id, place],
    queryFn: async () => {
      const query = new URLSearchParams(place).toString()
      try {
        return await api.get(`config/theme/resolved${query ? `?${query}` : ''}`)
      } catch (error) {
        // A place the user no longer works at: the tenant's theme.
        if (error?.status === 422 && query) return api.get('config/theme/resolved')
        throw error
      }
    },
    enabled: signedIn && !isLoading,
    staleTime: 60_000,
  })

  const host = typeof window === 'undefined' ? '' : window.location.host
  const open = useQuery({
    queryKey: ['public', 'branding', host],
    queryFn: () => api.get(`public/branding?host=${encodeURIComponent(host)}`),
    enabled: !token && Boolean(host),
    staleTime: 300_000,
    retry: false,
  })

  const payload = resolved.data?.data?.payload
  const publicData = open.data?.data
  return useMemo(() => {
    if (signedIn) return payload ? fromResolved(payload, user) : user?.tenant ? fromResolved(null, user) : null
    return token ? null : fromPublic(publicData)
  }, [signedIn, payload, user, token, publicData])
}
