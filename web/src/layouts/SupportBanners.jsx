import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api, clearToken } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { Button, StatusBadge } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

import { ACTIVE_SUPPORT_KEY } from './supportKeys'

/**
 * RBAC-13c: the two bars of support access, quietly, at the top of the app.
 * - In a support session (platform staff acting under the tenant's grant):
 *   "Support session", the mode, when it ends, and "End session".
 * - For the tenant's people who see support access: "Support access active"
 *   while a session may run, with a link to revoke it.
 */
export function SupportBanners() {
  const { user } = useAuth()
  if (!user) return null
  return user.support_session ? <SessionBar session={user.support_session} /> : <ActiveBar />
}

function SessionBar({ session }) {
  const { t } = useTranslation()
  const locale = useLocale()
  // The session's token is deleted with it: drop it here and leave the back office.
  const end = useMutation({
    mutationFn: () => api.post('support-session/end'),
    onSettled: () => {
      clearToken()
      window.location.assign('/sign-in')
    },
  })
  return (
    <div role="status" aria-label={t('supportAccess.session.label')} className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-surface-200 px-4 py-2 md:px-10">
      <StatusBadge tone="accent">{t('supportAccess.session.label')}</StatusBadge>
      <span className="min-w-0 flex-1 text-caption text-ink">
        {t('supportAccess.session.text', {
          mode: t(`supportAccess.modes.${session.mode}`),
          until: formatDateTime(session.expires_at, locale),
        })}
      </span>
      <Button variant="ghost" loading={end.isPending} onClick={() => end.mutate()}>
        {t('supportAccess.session.end')}
      </Button>
    </div>
  )
}

function ActiveBar() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { tenantWide } = usePermissions()
  const visible = tenantWide('core.support_access.view')
  const active = useQuery({ queryKey: ACTIVE_SUPPORT_KEY, queryFn: () => api.get('support-access/active'), enabled: visible, staleTime: 30_000, refetchInterval: 60_000, retry: false })
  const grants = active.data?.data ?? []
  if (!visible || grants.length === 0) return null
  return (
    <div role="status" aria-label={t('supportAccess.active.label')} className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-surface-200 px-4 py-2 md:px-10">
      <StatusBadge tone="warning">{t('supportAccess.active.label')}</StatusBadge>
      <span className="min-w-0 flex-1 text-caption text-ink">
        {t('supportAccess.active.text', { names: grants.map((grant) => grant.staff_name).join(', '), until: formatDateTime(grants[grants.length - 1].expires_at, locale) })}
      </span>
      <Link to="/settings/security/support-access" className="rounded-sm text-caption font-medium text-primary underline-offset-2 hover:underline">
        {t('supportAccess.active.manage')}
      </Link>
    </div>
  )
}
