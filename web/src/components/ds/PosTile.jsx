import { useTranslation } from 'react-i18next'
import { formatAmount } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { StatusBadge } from './StatusBadge'

const DEFAULT_LOW_STOCK = 5

/**
 * LAY-05: category tile colours, tokens only (BR-01), as on the POS app
 * (pos/src/pos/layout.js): the tile's tint and the chip's dot.
 */
export const POS_CATEGORY_COLOURS = {
  'primary-tint': { tile: 'bg-primary-tint', dot: 'bg-primary' },
  'surface-300': { tile: 'bg-surface-300', dot: 'bg-ink-muted' },
  'success-tint': { tile: 'bg-success-tint', dot: 'bg-success' },
  'warning-tint': { tile: 'bg-warning-tint', dot: 'bg-warning' },
  'danger-tint': { tile: 'bg-danger-tint', dot: 'bg-danger' },
}

/** LAY-05: tile sizes, as on the POS app. */
export const POS_TILE_SIZES = {
  compact: { tile: 'gap-1 p-2', name: 'text-body' },
  standard: { tile: 'gap-3 p-4', name: 'text-body-lg' },
  large: { tile: 'gap-6 p-6', name: 'text-h3' },
}

/**
 * A product button on the POS grid; price in minor units. `unavailable`
 * (a short reason, e.g. "Rate needed") disables the tile and says why,
 * as on the POS app. `color` is a category colour token from the POS
 * layout (one of POS_CATEGORY_COLOURS, never a typed colour) and `size`
 * the layout's tile size.
 */
export function PosTile({ name, price, currency, stock, lowStock = DEFAULT_LOW_STOCK, image, color, size = 'standard', unavailable, onSelect, className }) {
  const { t } = useTranslation()
  const lang = useLocale()
  const out = stock === 0 || Boolean(unavailable)
  const priceText = price == null ? '' : `${currency} ${formatAmount(price, currency, lang)}`
  const label = [name, priceText, unavailable || (out ? t('ds.posTile.outOfStock') : null)].filter(Boolean).join(', ')
  const tint = POS_CATEGORY_COLOURS[color]?.tile
  const sizing = POS_TILE_SIZES[size] ?? POS_TILE_SIZES.standard

  return (
    <button
      type="button"
      disabled={out}
      onClick={onSelect}
      aria-label={label}
      className={cn(
        'flex min-h-12 flex-col justify-between rounded-md border border-border text-left text-ink transition-colors',
        sizing.tile,
        tint ?? 'bg-surface-200',
        'hover:border-border-strong active:bg-surface-300 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-border',
        'focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
        className,
      )}
    >
      {image ? <img src={image} alt="" className="aspect-video w-full rounded-sm object-cover" /> : null}
      <span className={cn('font-medium', sizing.name)}>{name}</span>
      <span className="flex items-center justify-between gap-2">
        <span className={cn('text-label font-normal tabular-nums', tint ? 'text-ink' : 'text-ink-muted')}>{priceText}</span>
        {unavailable ? (
          <StatusBadge tone="warning">{unavailable}</StatusBadge>
        ) : out ? (
          <StatusBadge tone="danger">{t('ds.posTile.out')}</StatusBadge>
        ) : stock != null && stock <= lowStock ? (
          <StatusBadge tone="warning">{t('ds.posTile.left', { count: stock })}</StatusBadge>
        ) : null}
      </span>
    </button>
  )
}
