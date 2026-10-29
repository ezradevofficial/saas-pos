import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { fromShell } from '@/api/shellBootstrap'
import { useAuth } from '@/auth/AuthProvider'
import { StatusBadge } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTrialStatus } from '@/pages/onboarding/useOnboarding'
import { STATUS_KEY } from '@/pages/settings/subscription/subscriptionData'

/** ONB-04: "Trial · 13 days left" (or ended), wherever it is shown. */
export function TrialBadge() {
  const { t } = useTranslation()
  const { data: trial } = useTrialStatus()
  if (!trial || trial.state === 'none') return null
  if (trial.state === 'ended') return <StatusBadge tone="warning">{t('onboarding.trial.ended')}</StatusBadge>
  return <StatusBadge tone="info">{t('onboarding.trial.daysLeft', { count: trial.days_left })}</StatusBadge>
}

/**
 * ONB-04: the trial status and days left, always visible in the back
 * office (a thin bar above every page). Hidden when there is no trial.
 */
export function TrialBanner() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { token, user, enrolmentRequired } = useAuth()
  const { data: trial } = useTrialStatus()
  // SUB-03: subscribed during the trial. The subscription banner's query: one request for both.
  const status = useQuery({ queryKey: STATUS_KEY, queryFn: () => fromShell('subscription', () => api.get('subscription/status')), enabled: Boolean(token && user && !enrolmentRequired), staleTime: 60_000, retry: false })
  const firstPeriod = status.data?.data?.first_period_start
  if (!trial || trial.state === 'none') return null
  const help =
    trial.state === 'ended' ? t('onboarding.trial.endedHelp') : firstPeriod ? t('onboarding.trial.subscribedHelp', { date: formatCalendarDate(firstPeriod, locale) }) : t('onboarding.trial.help')
  return (
    <div role="status" className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-200 px-4 py-2 md:px-10">
      <TrialBadge />
      <span className="text-caption text-ink-muted">{help}</span>
    </div>
  )
}
