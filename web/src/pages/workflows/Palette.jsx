import { useTranslation } from 'react-i18next'
import { DRAG_TYPE } from './graph'
import { PALETTE } from './workflowData'

/**
 * Building blocks (BoWorkflow design, "Drag onto the flow"): drag one onto
 * the canvas, or press it (click, Enter or Space) to add it below the
 * lowest step, so the palette works without a mouse.
 */
export function Palette({ onAdd, hasStart }) {
  const { t } = useTranslation()
  const kinds = hasStart ? PALETTE : ['start', ...PALETTE]
  return (
    <nav aria-label={t('workflows.palette.label')} className="flex min-w-0 flex-col gap-2">
      <h2 className="pb-1 text-caption font-medium text-ink-muted uppercase">{t('workflows.palette.title')}</h2>
      <ul className="flex flex-wrap gap-2 lg:flex-col lg:flex-nowrap">
        {kinds.map((kind) => (
          <li key={kind} className="min-w-0 flex-1 basis-40 lg:basis-auto">
            <button
              type="button"
              draggable
              onDragStart={(event) => {
                event.dataTransfer.setData(DRAG_TYPE, kind)
                event.dataTransfer.effectAllowed = 'move'
              }}
              onClick={() => onAdd(kind)}
              aria-label={t('workflows.palette.add', { name: t(`workflows.palette.items.${kind}.name`) })}
              className="flex w-full cursor-grab flex-col items-start gap-1 rounded-md border border-dashed border-border-strong bg-surface-100 px-3 py-2 text-left text-ink hover:border-ink-muted active:cursor-grabbing"
            >
              <span className="font-medium">{t(`workflows.palette.items.${kind}.name`)}</span>
              <span className="text-caption text-ink-muted">{t(`workflows.palette.items.${kind}.description`)}</span>
            </button>
          </li>
        ))}
      </ul>
    </nav>
  )
}
