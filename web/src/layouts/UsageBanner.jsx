import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button, StatusBadge } from '@/components/ds'
import { formatDate, formatMoney } from '@/lib/format'
import { unitsOver, USAGE_TONES, useUsage } from '@/lib/usage'
import { useLocale } from '@/lib/useLocale'

/**
 * LIM-02, LIM-03: shown to Owners on every back-office page while the
 * organisation is above its plan: what is over and by how much, the extra
 * monthly cost, the days left before adding more is paused, and Upgrade
 * (the subscription page). Nothing for anyone else, or within the plan.
 */
export function UsageBanner() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { usage } = useUsage()
  const over = unitsOver(usage)

  if (over.length === 0) return null

  // LIM-02, ONB-04: a trial allows the extra units until it ends, so show that
  // end date; otherwise the days left of the metered window (never "0 days"
  // before the meter has opened one).
  const trialEnds = usage.trial?.state === 'trial' ? usage.trial.ends_at : null
  const statusLabel = (unit) => {
    if (unit.state === 'blocked') return t('usage.state.blocked')
    if (trialEnds) return t('usage.trialAllowed', { date: formatDate(trialEnds, locale) })
    if (unit.overage) return t('usage.daysLeft', { count: unit.overage.days_left })
    return t('usage.state.over')
  }

  return (
    <section
      aria-label={t('usage.banner.title')}
      className="flex flex-col gap-3 rounded-md border border-border bg-warning-tint px-4 py-3 text-body text-ink sm:flex-row sm:items-center sm:justify-between"
    >
      <div className="flex min-w-0 flex-col gap-1">
        <p className="font-medium">{t('usage.banner.title')}</p>
        <ul className="flex flex-col gap-1">
          {over.map((unit) => (
            <li key={unit.unit} className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span className="tabular-nums">
                {t('usage.banner.line', { item: t(`usage.units.${unit.unit}`), count: unit.count, allowed: unit.allowed, over: unit.over })}
              </span>
              <StatusBadge tone={USAGE_TONES[unit.state]}>{statusLabel(unit)}</StatusBadge>
            </li>
          ))}
        </ul>
        {usage.extra_cost ? (
          <p className="text-ink-muted">{t('usage.banner.extra', { amount: formatMoney(usage.extra_cost.amount_minor, usage.extra_cost.currency, locale) })}</p>
        ) : null}
        <p className="text-caption text-ink-muted">{t('usage.banner.note')}</p>
      </div>
      <div className="shrink-0">
        <Button variant="primary" onClick={() => navigate(usage.upgrade_url ?? '/settings/subscription')}>
          {t('usage.upgrade')}
        </Button>
      </div>
    </section>
  )
}
