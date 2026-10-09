import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useTranslation } from 'react-i18next'
import { dragStyle } from '@/lib/dragStyle'
import { cn } from '@/lib/utils'
import { Button } from './Button'
import { Checkbox } from './Checkbox'
import { Dialog } from './Dialog'
import { Icon } from './Icon'

function Row({ column, list, moveLabel }) {
  const { attributes, listeners, setNodeRef, isDragging, transform, transition } = useSortable({ id: column.key })
  return (
    <li
      ref={setNodeRef}
      // The drag offset is a runtime value from dnd-kit, not a design value.
      style={dragStyle(transform, transition)}
      className={cn('flex items-center gap-3 rounded-md border border-border bg-surface-200 px-3 py-2', isDragging && 'relative z-10 border-primary')}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        aria-label={moveLabel}
        className="flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded-sm text-ink-muted hover:text-ink"
      >
        <Icon name="drag" />
      </button>
      <Checkbox
        label={column.label}
        checked={list.isColumnVisible(column.key)}
        disabled={!list.canToggleColumn(column.key)}
        onChange={() => list.toggleColumn(column.key)}
        className="min-w-0 flex-1"
      />
    </li>
  )
}

/**
 * LAY-04: the order and choice of a list's columns. Rows are dragged by
 * their handle (or, with the handle focused, Space then the arrow keys);
 * the table follows at once. Saving the view keeps the arrangement.
 */
export function ListColumns({ list, open, onClose }) {
  const { t } = useTranslation()
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const columns = list.columns.filter((column) => column.inMenu !== false)
  const keys = columns.map((column) => column.key)

  const onDragEnd = ({ active, over }) => {
    if (!over || active.id === over.id) return
    list.setColumnOrder(arrayMove(keys, keys.indexOf(active.id), keys.indexOf(over.id)))
  }

  return (
    <Dialog
      open={open}
      title={t('ds.listView.arrangeTitle')}
      onClose={onClose}
      footer={
        <Button variant="primary" onClick={onClose}>
          {t('ds.listView.arrangeDone')}
        </Button>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-body text-ink-muted">{t('ds.listView.arrangeBody')}</p>
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={keys} strategy={verticalListSortingStrategy}>
            <ul className="flex flex-col gap-2">
              {columns.map((column) => (
                <Row key={column.key} column={column} list={list} moveLabel={t('ds.listView.moveColumn', { name: typeof column.label === 'string' ? column.label : column.key })} />
              ))}
            </ul>
          </SortableContext>
        </DndContext>
      </div>
    </Dialog>
  )
}
