import { useTranslation } from 'react-i18next'
import { formatAmount, toMinor } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { Button } from './Button'
import { Money } from './Money'

function Row({ label, value, className, valueClassName = 'text-ink' }) {
  return (
    <div className={cn('flex justify-between gap-4 text-body-lg text-ink-muted', className)}>
      <span>{label}</span>
      <span className={cn('tabular-nums', valueClassName)}>{value}</span>
    </div>
  )
}

/** The POS sale summary with the one decisive Charge button (pay variant). */
export function SaleTotal({ currency, subtotal, tax, total, discount, secondary, onPay, labels, className }) {
  const { t } = useTranslation()
  const lang = useLocale()
  const money = (minor) => `${currency} ${formatAmount(minor, currency, lang)}`
  const hasDiscount = toMinor(discount) !== 0n
  const amount = money(total)

  return (
    <div className={cn('flex flex-col gap-2 rounded-lg border border-border bg-surface-200 p-5 text-ink', className)}>
      <Row label={labels?.subtotal ?? t('ds.saleTotal.subtotal')} value={money(subtotal)} />
      {hasDiscount ? (
        <Row
          label={labels?.discount ?? t('ds.saleTotal.discount')}
          value={money(-toMinor(discount))}
          className="text-accent-ink"
          valueClassName="text-accent-ink"
        />
      ) : null}
      <Row label={labels?.tax ?? t('ds.saleTotal.tax')} value={money(tax)} />
      <div className="mt-1 mb-3 flex items-end justify-between gap-4 border-t border-border pt-3">
        <span className="text-body-lg font-medium">{labels?.total ?? t('ds.saleTotal.total')}</span>
        <Money amount={total} currency={currency} size="lg" secondary={secondary} locale={lang} className="items-end" />
      </div>
      <Button variant="pay" size="lg" block onClick={onPay} disabled={toMinor(total) === 0n}>
        {labels?.pay ? `${labels.pay} ${amount}` : t('ds.saleTotal.pay', { amount })}
      </Button>
    </div>
  )
}
