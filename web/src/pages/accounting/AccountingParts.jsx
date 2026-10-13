import { useTranslation } from 'react-i18next'
import { Alert, Money } from '@/components/ds'

/** An API money value (`{amount_minor, currency}`) with its code first; a dash when absent. */
export function Amount({ value, tone }) {
  if (!value) return <span className="text-ink-muted">—</span>
  return <Money amount={value.amount_minor} currency={value.currency} tone={tone} />
}

/** A signed amount in minor units of the report's currency. */
export function Minor({ amount, currency }) {
  if (amount === null || amount === undefined) return <span className="text-ink-muted">—</span>
  return <Money amount={String(amount)} currency={currency} />
}

/** A label above a value, for detail panels. */
export function Detail({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/** Shown while the user has no company to keep accounts for. */
export function NoCompany() {
  const { t } = useTranslation()
  return <Alert tone="info" title={t('accounting.noCompany')} />
}
