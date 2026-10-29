import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { fromShell } from '@/api/shellBootstrap'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { StatusBadge } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { STATUS_KEY, STATUS_TONES } from '@/pages/settings/subscription/subscriptionData'

// The trial's days left are onboarding's TrialBanner (ONB-04); this one shows dunning.
const SHOWN = ['past_due', 'read_only', 'suspended']

/**
 * SUB-05: the subscription's dunning state across the app, quietly: past
 * due (and when it turns read-only), read-only. A small
 * dot and a word, a sentence, and a link for those who manage billing.
 */
export function SubscriptionBanner() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { token, user, enrolmentRequired } = useAuth()
  const { tenantWide } = usePermissions()
  const status = useQuery({
    queryKey: STATUS_KEY,
    queryFn: () => fromShell('subscription', () => api.get('subscription/status')),
    enabled: Boolean(token && user && !enrolmentRequired),
    staleTime: 60_000,
    retry: false,
  })
  const data = status.data?.data

  if (!data || !SHOWN.includes(data.status)) return null

  const day = (value) => (value ? formatCalendarDate(value, locale) : '')
  const text =
    data.status === 'past_due'
      ? t('subscriptionBanner.pastDue', { date: day(data.read_only_on) })
      : data.status === 'read_only'
        ? t('subscriptionBanner.readOnly', { date: day(data.pos_stops_at) })
        : t('subscriptionBanner.suspended')

  return (
    <div role="status" aria-label={t('subscriptionBanner.label')} className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-border bg-surface-200 px-4 py-2 md:px-10">
      <StatusBadge tone={STATUS_TONES[data.status] ?? 'neutral'}>{t(`subscription.status.${data.status}`)}</StatusBadge>
      <span className="min-w-0 flex-1 text-caption text-ink">{text}</span>
      {tenantWide('core.subscription.view') ? (
        <Link to="/settings/subscription" className="rounded-sm text-caption font-medium text-primary underline-offset-2 hover:underline">
          {t('subscriptionBanner.pay')}
        </Link>
      ) : null}
    </div>
  )
}
