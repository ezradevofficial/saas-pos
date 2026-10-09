import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Icon } from '@/components/ds'
import { cn } from '@/lib/utils'
import { blockLabel, moveBlock } from './blocks'

/** One line saying what the block prints, for the canvas. */
function summaryOf(t, block, type) {
  const fieldLabel = (path) => type.fields?.find((field) => field.path === path)?.label ?? path
  const columnLabel = (key) => type.columns?.find((column) => column.key === key)?.label ?? key
  switch (block.type) {
    case 'text':
    case 'terms':
      return block.text || t('documentTemplates.canvas.empty')
    case 'field':
      return fieldLabel(block.field)
    case 'lines':
      return (block.columns ?? []).map(columnLabel).join(', ')
    case 'totals':
      return (block.show ?? []).map((key) => t(`documentTemplates.totals.${key}`)).join(', ')
    case 'qr':
    case 'barcode':
      return block.content
    case 'signature':
      return block.label || t('documentTemplates.canvas.empty')
    case 'spacer':
      return t('documentTemplates.canvas.mm', { value: block.size ?? '' })
    case 'logo':
      return t('documentTemplates.canvas.mm', { value: block.height ?? '' })
    case 'divider':
      return t(`documentTemplates.dividers.${block.style ?? 'solid'}`)
    case 'payments':
      return block.show_change === false ? t('documentTemplates.canvas.noChange') : t('documentTemplates.canvas.withChange')
    case 'fiscal':
      return t(`documentTemplates.authorities.${type.fiscal?.authority ?? 'other'}`)
    case 'row':
      return t('documentTemplates.canvas.row')
    default:
      return ''
  }
}

function SortableBlock({ block, type, selected, onSelect, onRemove, onChangeRow, locked, canEdit, nested }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id, disabled: !canEdit })
  const label = blockLabel(t, block)
  const isLocked = locked.includes(block.type)
  const active = selected === block.id

  return (
    <li
      ref={setNodeRef}
      // The drag offset is a runtime value from dnd-kit, not a design value.
      style={{ transform: transform ? `translate3d(${Math.round(transform.x)}px, ${Math.round(transform.y)}px, 0)` : undefined, transition }}
      data-block={block.type}
      className={cn('rounded-md border bg-surface-200', active ? 'border-primary' : 'border-border', isDragging && 'relative z-10 border-primary')}
    >
      <div className="flex items-center gap-2 px-2 py-2">
        {canEdit ? (
          <button
            type="button"
            {...attributes}
            {...listeners}
            aria-label={t('documentTemplates.canvas.move', { block: label })}
            className="flex size-icon-btn shrink-0 cursor-grab items-center justify-center rounded-sm text-ink-muted hover:bg-surface-300 hover:text-ink"
          >
            <Icon name="grip" />
          </button>
        ) : null}
        <button
          type="button"
          aria-pressed={active}
          onClick={() => onSelect(block.id)}
          className="flex min-w-0 flex-1 flex-col items-start rounded-sm px-1 text-left"
        >
          <span className="text-body text-ink">{label}</span>
          <span className="w-full truncate text-caption text-ink-muted">{summaryOf(t, block, type)}</span>
        </button>
        {isLocked ? (
          <span role="img" aria-label={t('documentTemplates.canvas.locked', { block: label })} title={t('documentTemplates.canvas.locked', { block: label })} className="px-2 text-ink-muted">
            <Icon name="lock" />
          </span>
        ) : canEdit ? (
          <Button variant="ghost" icon="remove" aria-label={t('documentTemplates.canvas.remove', { block: label })} className="size-icon-btn shrink-0 px-0" onClick={() => onRemove(block.id)} />
        ) : null}
      </div>
      {block.type === 'row' && !nested ? (
        <div className="grid gap-3 px-3 pb-3 sm:grid-cols-2">
          {(block.columns ?? [[], []]).map((column, index) => (
            <div key={index} className="flex flex-col gap-2 rounded-md border border-dashed border-border p-2">
              <span className="text-caption text-ink-muted">{t(index === 0 ? 'documentTemplates.canvas.left' : 'documentTemplates.canvas.right')}</span>
              <BlockCanvas
                blocks={column}
                type={type}
                selected={selected}
                onSelect={onSelect}
                onRemove={onRemove}
                locked={locked}
                canEdit={canEdit}
                nested
                label={t(index === 0 ? 'documentTemplates.canvas.left' : 'documentTemplates.canvas.right')}
                onChange={(next) => onChangeRow(block.id, index, next)}
              />
            </div>
          ))}
        </div>
      ) : null}
    </li>
  )
}

/**
 * The template's blocks in print order (TPL-01): drag a block by its handle,
 * or focus the handle and use Space, the arrow keys and Space again (dnd-kit
 * keyboard sensor). Locked blocks (TPL-03) show a lock and cannot be removed.
 */
export function BlockCanvas({ blocks, type, selected, onSelect, onChange, onRemove, onChangeRow, locked = [], canEdit, nested = false, label }) {
  const { t } = useTranslation()
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }))
  const ids = useMemo(() => blocks.map((block) => block.id), [blocks])
  const nameOf = (id) => {
    const block = blocks.find((entry) => entry.id === id)
    return block ? blockLabel(t, block) : String(id)
  }
  const positionOf = (id) => ids.indexOf(id) + 1
  const accessibility = {
    screenReaderInstructions: { draggable: t('documentTemplates.canvas.instructions') },
    announcements: {
      onDragStart: ({ active }) => t('documentTemplates.canvas.picked', { block: nameOf(active.id), position: positionOf(active.id), count: ids.length }),
      onDragOver: ({ active, over }) => (over ? t('documentTemplates.canvas.over', { block: nameOf(active.id), position: positionOf(over.id), count: ids.length }) : undefined),
      onDragEnd: ({ active, over }) => (over ? t('documentTemplates.canvas.dropped', { block: nameOf(active.id), position: positionOf(over.id), count: ids.length }) : undefined),
      onDragCancel: ({ active }) => t('documentTemplates.canvas.cancelled', { block: nameOf(active.id) }),
    },
  }

  const end = ({ active, over }) => {
    if (!over || active.id === over.id) return
    const from = ids.indexOf(active.id)
    const to = ids.indexOf(over.id)
    if (from !== -1 && to !== -1) onChange(moveBlock(blocks, from, to))
  }

  if (blocks.length === 0) return <p className="px-2 py-3 text-caption text-ink-muted">{t(nested ? 'documentTemplates.canvas.emptyColumn' : 'documentTemplates.canvas.none')}</p>

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={end} accessibility={accessibility}>
      <SortableContext items={ids} strategy={verticalListSortingStrategy}>
        <ul aria-label={label ?? t('documentTemplates.canvas.label')} className="flex flex-col gap-2">
          {blocks.map((block) => (
            <SortableBlock
              key={block.id}
              block={block}
              type={type}
              selected={selected}
              onSelect={onSelect}
              onRemove={onRemove}
              onChangeRow={onChangeRow}
              locked={locked}
              canEdit={canEdit}
              nested={nested}
            />
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  )
}
