import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Alert, Button, Card, Money, StatusBadge } from '@/components/ds'
import { errorMessage } from '@/api/errorMessage'
import { PageHeader } from '@/layouts/PageHeader'
import { formatInteger, formatWhen } from '@/lib/format'
import { USAGE_TONES, useUsage } from '@/lib/usage'
import { useLocale } from '@/lib/useLocale'

function UsageRow({ unit }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const count = formatInteger(unit.count, locale)

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-3">
      <div className="flex min-w-0 flex-col gap-1">
        <span className="font-medium text-ink">{t(`usage.units.${unit.unit}`)}</span>
        <span className="text-caption text-ink-muted tabular-nums">
          {[
            unit.allowed === null ? t('usage.noLimit', { count }) : t('usage.of', { count, allowed: formatInteger(unit.allowed, locale) }),
            unit.over > 0 ? t('usage.overBy', { over: formatInteger(unit.over, locale) }) : null,
            unit.state === 'over' ? t('usage.daysLeft', { count: unit.overage?.days_left ?? 0 }) : null,
            unit.state === 'blocked' ? t('usage.paused') : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </span>
      </div>
      <div className="flex items-center gap-4">
        {unit.extra_cost ? (
          <span className="flex items-baseline gap-1">
            <Money amount={unit.extra_cost.amount_minor} currency={unit.extra_cost.currency} />
            <span className="text-caption text-ink-muted">{t('usage.perMonth')}</span>
          </span>
        ) : null}
        <StatusBadge tone={USAGE_TONES[unit.state] ?? 'neutral'}>{t(`usage.state.${unit.state}`)}</StatusBadge>
      </div>
    </li>
  )
}

/**
 * LIM-01, LIM-02: Settings → Usage (Owners only). Each unit counted today
 * against the plan, its state (within, over with the days left, adding
 * paused, no limit), the extra monthly cost from the catalogue, and Upgrade.
 */
export function UsageCard() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { usage, isPending, isError, error } = useUsage()

  return (
    <Card
      title={t('usage.card.title')}
      subtitle={usage ? (usage.metered_at ? t('usage.card.counted', { when: formatWhen(usage.metered_at, locale) }) : t('usage.card.notCounted')) : null}
      actions={
        usage ? (
          <Button onClick={() => navigate(usage.upgrade_url ?? '/settings/subscription')}>
            {t('usage.upgrade')}
          </Button>
        ) : null
      }
    >
      {isError ? <Alert tone="danger" title={errorMessage(error) || t('usage.error')} /> : null}
      {isPending && !isError ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {usage ? (
        <div className="flex flex-col gap-3">
          <ul className="divide-y divide-border">
            {usage.units.map((unit) => (
              <UsageRow key={unit.unit} unit={unit} />
            ))}
          </ul>
          {usage.extra_cost ? (
            <div className="flex flex-wrap items-baseline justify-between gap-3 border-t border-border pt-3">
              <span className="font-medium text-ink">{t('usage.extraCost')}</span>
              <span className="flex items-baseline gap-1">
                <Money amount={usage.extra_cost.amount_minor} currency={usage.extra_cost.currency} />
                <span className="text-caption text-ink-muted">{t('usage.perMonth')}</span>
              </span>
            </div>
          ) : null}
        </div>
      ) : null}
    </Card>
  )
}

export default function Usage() {
  const { t } = useTranslation()

  return (
    <>
      <PageHeader title={t('usage.title')} description={t('usage.description')} />
      <UsageCard />
    </>
  )
}
