// LAY-01: the dashboard's 12-column grid. Widgets sit at column `x` (0-11)
// and row `y`, `w` columns wide and `h` rows high. Moving or resizing one
// pushes the widgets it would cover further down, so a layout never has
// overlaps (the API refuses them when publishing).

export const COLUMNS = 12
export const MAX_ROWS = 48
export const MAX_HEIGHT = 8

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

/** The widget kept on the grid: at least 1x1, never past column 12, row 48 or 8 rows high. */
export function clampWidget(widget) {
  const w = clamp(Math.round(widget.w), 1, COLUMNS)
  const h = clamp(Math.round(widget.h), 1, MAX_HEIGHT)
  return { ...widget, w, h, x: clamp(Math.round(widget.x), 0, COLUMNS - w), y: clamp(Math.round(widget.y), 0, MAX_ROWS - h) }
}

export const overlaps = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h

/** Widgets in reading order: by row, then column (also the order on phones). */
export const inReadingOrder = (widgets) => [...widgets].sort((a, b) => a.y - b.y || a.x - b.x)

/**
 * `widgets` with the one of `id` changed by `changes` (x, y, w, h), kept on
 * the grid; every widget it then covers moves down below it, and so on.
 */
export function placeWidget(widgets, id, changes) {
  const moved = widgets.find((widget) => widget.id === id)
  if (!moved) return widgets
  const placed = [clampWidget({ ...moved, ...changes })]
  const rest = inReadingOrder(widgets.filter((widget) => widget.id !== id))
  for (const widget of rest) {
    let next = { ...widget }
    while (placed.some((other) => overlaps(other, next))) {
      const below = Math.max(...placed.filter((other) => overlaps(other, next)).map((other) => other.y + other.h))
      next = { ...next, y: below }
    }
    placed.push(next)
  }
  return widgets.map((widget) => placed.find((entry) => entry.id === widget.id))
}

/** The first row below every widget: where a new widget goes. */
export const nextFreeRow = (widgets) => widgets.reduce((row, widget) => Math.max(row, widget.y + widget.h), 0)

/** Moves (`move`) or resizes (`resize`) a widget by whole cells, as the keyboard does. */
export function nudge(widgets, id, mode, dx, dy) {
  const widget = widgets.find((entry) => entry.id === id)
  if (!widget) return widgets
  return mode === 'resize' ? placeWidget(widgets, id, { w: widget.w + dx, h: widget.h + dy }) : placeWidget(widgets, id, { x: widget.x + dx, y: widget.y + dy })
}

/** A pixel drag turned into whole cells, given the size of one column and one row (gaps included). */
export const cellsOf = (pixels, cell) => (cell > 0 ? Math.round(pixels / cell) : 0)
