import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

const DOT = {
  done: 'border-ink bg-ink text-surface-200',
  current: 'border-primary bg-surface-200 text-primary ring-3 ring-primary-tint',
  blocked: 'border-danger bg-danger text-on-danger',
  todo: 'border-border-strong bg-surface-200 text-ink-muted',
}

/** Where a document is in its process flow; scrolls sideways on phones. */
export function StageTracker({ stages = [], current, blocked = false, className }) {
  const { t } = useTranslation()
  const currentIndex = stages.indexOf(current)
  const spoken = { done: t('ds.stageTracker.done'), current: t('ds.stageTracker.current'), blocked: t('ds.stageTracker.blocked') }

  return (
    <ol className={cn('flex overflow-x-auto', className)}>
      {stages.map((stage, index) => {
        const state = index < currentIndex ? 'done' : index === currentIndex ? (blocked ? 'blocked' : 'current') : 'todo'
        return (
          <li
            key={stage}
            data-state={state}
            aria-current={index === currentIndex ? 'step' : undefined}
            className="flex grow basis-0 flex-col items-start gap-2 pr-3"
          >
            <div className="flex w-full items-center gap-1">
              <span className={cn('flex size-5 shrink-0 items-center justify-center rounded-pill border text-caption font-medium', DOT[state])}>
                {state === 'done' ? <Icon name="check" size={12} /> : state === 'blocked' ? <Icon name="x" size={12} /> : index + 1}
              </span>
              {index < stages.length - 1 ? (
                <span aria-hidden="true" className={cn('h-px flex-1', state === 'done' ? 'bg-ink' : 'bg-border')} />
              ) : null}
            </div>
            <span className={cn('text-caption whitespace-nowrap', state === 'current' || state === 'blocked' ? 'font-medium text-ink' : 'text-ink-muted')}>
              {stage}
            </span>
            {spoken[state] ? <span className="sr-only">{spoken[state]}</span> : null}
          </li>
        )
      })}
    </ol>
  )
}
