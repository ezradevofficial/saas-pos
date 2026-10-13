import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/ds'
import { useTrialStatus } from '@/pages/onboarding/useOnboarding'

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
  const { data: trial } = useTrialStatus()
  if (!trial || trial.state === 'none') return null
  return (
    <div role="status" className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-200 px-4 py-2 md:px-10">
      <TrialBadge />
      <span className="text-caption text-ink-muted">{trial.state === 'ended' ? t('onboarding.trial.endedHelp') : t('onboarding.trial.help')}</span>
    </div>
  )
}
