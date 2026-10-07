import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'

const TONES = { success: 'text-success', danger: 'text-danger' }

/** An amount in minor units with its currency code first, in tabular figures. */
export function Money({ amount, currency, secondary, size = 'md', tone, locale, className }) {
  const lang = useLocale(locale)
  const large = size === 'lg'
  return (
    <span className={cn('inline-flex flex-col tabular-nums', TONES[tone], className)}>
      <span data-slot="money-main" className={cn('whitespace-nowrap', large ? 'text-amount-lg' : 'text-amount')}>
        <span className={cn('font-normal text-ink-muted', large && 'text-body-lg')}>{currency}</span> {formatAmount(amount, currency, lang)}
      </span>
      {secondary ? (
        <span className="text-caption text-ink-muted">{`≈ ${secondary.currency} ${formatAmount(secondary.amount, secondary.currency, lang)}`}</span>
      ) : null}
    </span>
  )
}
