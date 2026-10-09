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
