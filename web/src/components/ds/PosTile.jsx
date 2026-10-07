import { useTranslation } from 'react-i18next'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { StatusBadge } from './StatusBadge'

const DEFAULT_LOW_STOCK = 5

export function PosTile({ name, price, currency, stock, lowStock = DEFAULT_LOW_STOCK, image, color, onSelect, className }) {
  const { t } = useTranslation()
  const lang = useLocale()
  const out = stock === 0
  const priceText = `${currency} ${formatAmount(price, currency, lang)}`
  const label = [name, priceText, out ? t('ds.posTile.outOfStock') : null].filter(Boolean).join(', ')

  return (
    <button
      type="button"
      disabled={out}
      onClick={onSelect}
      aria-label={label}
      className={cn(
        'flex flex-col justify-between gap-3 rounded-md border border-border bg-surface-200 p-4 text-left text-ink transition-colors',
        'hover:border-border-strong active:bg-surface-300 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-border',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
        className,
      )}
    >
      {image ? <img src={image} alt="" className="aspect-video w-full rounded-sm object-cover" /> : null}
      <span className="flex items-start gap-2 text-body-lg font-medium">
        {color ? (
          // Category colour comes from the tenant's saved POS layout at runtime.
          <span aria-hidden="true" className="mt-2 size-dot shrink-0 rounded-pill" style={{ backgroundColor: color }} />
        ) : null}
        {name}
      </span>
      <span className="flex items-center justify-between gap-2">
        <span className="text-label font-normal text-ink-muted tabular-nums">{priceText}</span>
        {out ? (
          <StatusBadge tone="danger">{t('ds.posTile.out')}</StatusBadge>
        ) : stock != null && stock <= lowStock ? (
          <StatusBadge tone="warning">{t('ds.posTile.left', { count: stock })}</StatusBadge>
        ) : null}
      </span>
    </button>
  )
}
