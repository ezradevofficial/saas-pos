import { DndContext, PointerSensor, useDraggable, useSensor, useSensors } from '@dnd-kit/core'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Icon } from '@/components/ds'
import { cellsOf, inReadingOrder, nudge, placement, placeWidget } from '@/lib/dashboardGrid'
import { cn } from '@/lib/utils'

const KEYS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }

function Handle({ id, mode, label, className, children }) {
  const { attributes, listeners, setNodeRef } = useDraggable({ id: `${mode}:${id}` })
  return (
    <button
      ref={setNodeRef}
      type="button"
      {...attributes}
      {...listeners}
      // The widget itself takes the keys; the handle is for the pointer.
      tabIndex={-1}
      aria-label={label}
      className={cn('flex touch-none items-center justify-center rounded-sm text-ink-muted hover:text-ink', className)}
    >
      {children}
    </button>
  )
}

/**
 * LAY-01: widgets on a 12-column grid (one column on phones). With
 * `editing`, each widget can be dragged by its handle and resized from its
 * corner; it is also focusable, and the arrow keys move it by one cell
 * (Shift + arrows resize it). Widgets it would cover move down. The change
 * is shown while dragging and handed to `onChange(widgets)` when dropped.
 */
export function DashboardGrid({ widgets, editing = false, selectedId, onSelect, onChange, renderWidget, label }) {
  const { t } = useTranslation()
  const grid = useRef(null)
  const [preview, setPreview] = useState(null)
  const [announcement, setAnnouncement] = useState('')
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }))

  const shown = preview ?? widgets

  // One column and one row in pixels, gaps included, as the browser lays the grid out.
  const cell = () => {
    const node = grid.current
    if (!node) return { column: 0, row: 0 }
    const styles = window.getComputedStyle(node)
    const gap = Number.parseFloat(styles.columnGap) || 0
    const row = Number.parseFloat(styles.gridAutoRows) || 0
    return { column: (node.clientWidth + gap) / 12, row: row + (Number.parseFloat(styles.rowGap) || 0) }
  }

  const changed = (event) => {
    const [mode, id] = String(event.active.id).split(':')
    const widget = widgets.find((entry) => entry.id === id)
    if (!widget) return null
    const size = cell()
    const dx = cellsOf(event.delta.x, size.column)
    const dy = cellsOf(event.delta.y, size.row)
    return mode === 'resize' ? placeWidget(widgets, id, { w: widget.w + dx, h: widget.h + dy }) : placeWidget(widgets, id, { x: widget.x + dx, y: widget.y + dy })
  }

  const describe = (widget) => t('layouts.grid.position', { column: widget.x + 1, row: widget.y + 1, width: widget.w, height: widget.h })

  const onKeyDown = (event, widget) => {
    const step = KEYS[event.key]
    if (!step) return
    event.preventDefault()
    const next = nudge(widgets, widget.id, event.shiftKey ? 'resize' : 'move', step[0], step[1])
    onChange?.(next)
    setAnnouncement(describe(next.find((entry) => entry.id === widget.id)))
  }

  const body = (
    <div ref={grid} role={editing ? 'list' : undefined} aria-label={label} className="grid grid-cols-1 auto-rows-widget gap-4 md:grid-cols-12">
      {inReadingOrder(shown).map((widget) => {
        const selected = editing && widget.id === selectedId
        return (
          <div
            key={widget.id}
            role={editing ? 'listitem' : undefined}
            data-widget-id={widget.id}
            data-grid={`${widget.x},${widget.y},${widget.w},${widget.h}`}
            className={cn('relative min-h-0 min-w-0', placement(widget))}
          >
            {editing ? (
              <div
                tabIndex={0}
                role="button"
                aria-pressed={selected}
                aria-label={`${widget.label ?? widget.id}. ${describe(widget)}`}
                aria-describedby="dashboard-grid-help"
                onClick={() => onSelect?.(widget.id)}
                onFocus={() => onSelect?.(widget.id)}
                onKeyDown={(event) => onKeyDown(event, widget)}
                className={cn(
                  'flex h-full min-h-0 flex-col rounded-lg border bg-surface-200',
                  selected ? 'border-primary' : 'border-dashed border-border-strong',
                )}
              >
                <div className="flex items-center gap-2 border-b border-border px-2 py-1">
                  <Handle id={widget.id} mode="move" label={t('layouts.grid.move')} className="size-6">
                    <Icon name="drag" />
                  </Handle>
                  <span className="min-w-0 flex-1 truncate text-caption text-ink-muted">{widget.label ?? widget.id}</span>
                </div>
                <div className="pointer-events-none min-h-0 flex-1 overflow-hidden p-3">{renderWidget(widget)}</div>
                <Handle id={widget.id} mode="resize" label={t('layouts.grid.resize')} className="absolute right-1 bottom-1 size-6 cursor-se-resize">
                  <Icon name="resize" />
                </Handle>
              </div>
            ) : (
              renderWidget(widget)
            )}
          </div>
        )
      })}
    </div>
  )

  if (!editing) return body

  return (
    <DndContext
      sensors={sensors}
      onDragMove={(event) => setPreview(changed(event))}
      onDragEnd={(event) => {
        const next = changed(event)
        setPreview(null)
        if (next) onChange?.(next)
      }}
      onDragCancel={() => setPreview(null)}
    >
      <p id="dashboard-grid-help" className="sr-only">
        {t('layouts.grid.help')}
      </p>
      <p aria-live="polite" className="sr-only">
        {announcement}
      </p>
      {body}
    </DndContext>
  )
}
