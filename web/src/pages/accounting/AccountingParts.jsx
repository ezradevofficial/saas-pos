import { useTranslation } from 'react-i18next'
import { Alert, Money } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

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
  const { ready } = useCompanies()
  // While the companies load, say so rather than asking to choose one.
  if (!ready) return <p className="text-ink-muted">{t('accounting.loading')}</p>
  return <Alert tone="info" title={t('accounting.noCompany')} />
}

/** A calendar date ("2026-10-14") in the UI language, never shifted by the browser's time zone (L10N-03). */
export function CalendarDate({ value }) {
  const locale = useLocale()
  return value ? <span className="tabular-nums">{formatCalendarDate(value, locale)}</span> : <span className="text-ink-muted">—</span>
}
