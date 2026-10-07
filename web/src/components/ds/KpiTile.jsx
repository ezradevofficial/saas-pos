import { useTranslation } from 'react-i18next'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

export function KpiTile({ label, value, delta, period, lowerIsBetter = false, className }) {
  const { t } = useTranslation()
  const lang = useLocale()
  const direction = delta == null ? null : delta >= 0 ? 'up' : 'down'
  const good = direction == null ? null : (direction === 'up') !== lowerIsBetter
  const percent = delta == null ? '' : new Intl.NumberFormat(lang, { style: 'percent', maximumFractionDigits: 1 }).format(Math.abs(delta) / 100)

  return (
    <div className={cn('flex flex-col gap-tight rounded-lg border border-border bg-surface-200 px-5 py-4', className)}>
      <div className="text-body text-ink-muted">{label}</div>
      <div className="text-amount-lg tabular-nums">{value}</div>
      {direction ? (
        <div data-slot="kpi-delta" className={cn('inline-flex items-center gap-1 text-caption font-medium', good ? 'text-success' : 'text-danger')}>
          <Icon name={direction} size={12} />
          <span className="sr-only">{direction === 'up' ? t('ds.kpi.up') : t('ds.kpi.down')}</span>
          <span className="tabular-nums">{percent}</span>
          <span className="font-normal text-ink-muted">{period ?? t('ds.kpi.period')}</span>
        </div>
      ) : null}
    </div>
  )
}
