import { useTranslation } from 'react-i18next'
import { PosTile } from '@/components/ds/PosTile'
import { POS_CATEGORY_COLOURS } from '@/components/ds/posTileStyles'
import { cn } from '@/lib/utils'
import { previewTiles } from './layout'

// Static class names, so Tailwind finds them (the column count is layout data).
const COLUMNS = { 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-4', 5: 'grid-cols-5', 6: 'grid-cols-6' }

function QuickBar({ buttons, labelOf }) {
  if (!buttons.length) return null
  return (
    <div className="flex flex-wrap gap-2" data-preview="quick">
      {buttons.map((button) => (
        <span key={`${button.type}:${button.id ?? button.action}`} className="rounded-md border border-border-strong bg-surface-200 px-3 py-1 text-caption text-ink">
          {labelOf(button)}
        </span>
      ))}
    </div>
  )
}

function Chips({ categories, names }) {
  const { t } = useTranslation()
  const shown = categories.filter((category) => !category.hidden)
  return (
    <div className="flex flex-wrap gap-2" data-preview="chips">
      <span className="rounded-pill border border-primary bg-primary px-3 py-1 text-caption text-on-primary">{t('posLayout.preview.all')}</span>
      {shown.map((category) => {
        const colours = POS_CATEGORY_COLOURS[category.color]
        return (
          <span key={category.id} className={cn('flex items-center gap-1 rounded-pill border border-border-strong px-3 py-1 text-caption text-ink', colours?.tile ?? 'bg-surface-200')}>
            {colours ? <span aria-hidden="true" className={cn('size-dot rounded-pill', colours.dot)} /> : null}
            {names.get(category.id) ?? ''}
          </span>
        )
      })}
    </div>
  )
}

function SalePanel({ compact }) {
  const { t } = useTranslation()
  return (
    <div data-preview="sale-panel" className={cn('flex flex-col gap-2 rounded-lg border border-border bg-surface-200 p-3', compact ? 'w-full' : 'w-1/3 shrink-0')}>
      <span className="text-label font-medium text-ink">{t('posLayout.preview.sale')}</span>
      {!compact ? <span className="flex-1 text-caption text-ink-muted">{t('posLayout.preview.lines')}</span> : null}
      <span className="rounded-md bg-accent px-3 py-2 text-center text-caption font-medium text-on-accent">{t('posLayout.preview.charge')}</span>
    </div>
  )
}

/**
 * LAY-05: the sell screen as the till will draw it, tablet or phone, with
 * the real tile look (the shared PosTile): quick buttons, category chips
 * with their token colours, the grid in the layout's columns, size and
 * order, and the sale panel (the keypad side) right or left on a tablet,
 * at the bottom on a phone. Items are the tenant's first ones, without
 * prices.
 */
export function PosLayoutPreview({ layout, device, items, names, labelOf }) {
  const { t } = useTranslation()
  const tablet = device === 'tablet'
  const tiles = previewTiles(items, layout).slice(0, tablet ? 12 : 6)
  const columns = tablet ? layout.grid.tablet_columns : layout.grid.phone_columns
  const grid = (
    <div className="flex min-w-0 flex-1 flex-col gap-3">
      <QuickBar buttons={layout.quick_buttons} labelOf={labelOf} />
      <Chips categories={layout.categories} names={names} />
      <div data-preview="grid" data-columns={columns} className={cn('grid gap-2', COLUMNS[columns])}>
        {tiles.map((item) => (
          <PosTile key={item.id} name={item.name} color={item.color} size={layout.grid.tile_size} />
        ))}
      </div>
      {tiles.length === 0 ? <p className="text-caption text-ink-muted">{t('posLayout.preview.noItems')}</p> : null}
    </div>
  )

  return (
    <div
      role="group"
      aria-label={tablet ? t('posLayout.preview.tablet') : t('posLayout.preview.phone')}
      className={cn('rounded-lg border border-border-strong bg-surface-100 p-3', tablet ? 'w-full' : 'mx-auto w-full max-w-field')}
    >
      {tablet ? (
        <div data-preview="tablet" className={cn('flex gap-3', layout.keypad === 'left' ? 'flex-row-reverse' : 'flex-row')}>
          {grid}
          <SalePanel />
        </div>
      ) : (
        <div data-preview="phone" className="flex flex-col gap-3">
          {grid}
          <SalePanel compact />
        </div>
      )}
    </div>
  )
}
