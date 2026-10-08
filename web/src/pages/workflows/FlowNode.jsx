import { Handle, Position } from '@xyflow/react'
import { memo } from 'react'
import { useTranslation } from 'react-i18next'
import { StatusBadge } from '@/components/ds'
import { cn } from '@/lib/utils'
import { IN } from './graph'

const PILL = new Set(['start', 'end'])

function cardClasses(node, { selected, problem, path, dimmed }) {
  const pill = PILL.has(node.type)
  return cn(
    'relative flex h-full w-full flex-col gap-1 border text-body text-ink transition-colors',
    pill ? 'items-center justify-center rounded-pill px-4 py-3 text-center' : 'rounded-lg px-4 py-3',
    'border-border-strong bg-surface-200',
    node.type === 'condition' && 'border-warning bg-warning-tint',
    node.type === 'end' && node.outcome === 'approved' && 'border-success bg-success-tint',
    path && 'border-success ring-2 ring-success',
    problem && 'border-danger ring-1 ring-danger',
    selected && 'border-primary bg-primary-tint ring-2 ring-primary',
    dimmed && 'opacity-40',
  )
}

/**
 * One step on the canvas (BoWorkflow design): its kind as a caption
 * ("APPROVAL"), its name and a one-line summary. Branch handles (Yes/No,
 * Approved/Rejected, each branch) sit along the bottom with their labels.
 * `data`: { node, kind, title, summary, outputs: [{ id, label }], problem,
 * path (the dry run's result here), dimmed }.
 */
function FlowNodeComponent({ data, selected, isConnectable }) {
  const { t } = useTranslation()
  const { node, kind, title, summary, outputs, problem, path, dimmed } = data
  const pill = PILL.has(node.type)
  const labelled = outputs.length > 1

  return (
    <div
      data-node-type={node.type}
      data-selected={selected ? 'true' : undefined}
      data-problem={problem ? 'true' : undefined}
      data-path={path ?? undefined}
      className={cardClasses(node, { selected, problem, path, dimmed })}
    >
      {node.type !== 'start' ? <Handle type="target" id={IN} position={Position.Top} isConnectable={isConnectable} aria-label={t('workflows.canvas.input')} /> : null}

      {pill ? (
        <span className="truncate font-medium">{title}</span>
      ) : (
        <>
          <span className={cn('text-caption font-medium uppercase', selected ? 'text-primary' : node.type === 'condition' ? 'text-warning' : 'text-ink-muted')}>
            {kind}
          </span>
          <span className="truncate font-medium">{title}</span>
          {summary ? <span className="line-clamp-2 text-caption text-ink-muted">{summary}</span> : null}
        </>
      )}

      {problem ? (
        <StatusBadge tone="danger" className="self-start">
          {t('workflows.canvas.needsAttention')}
        </StatusBadge>
      ) : path ? (
        <StatusBadge tone="success" className={pill ? 'self-center' : 'self-start'}>
          {t(`workflows.test.results.${path}`, { defaultValue: path })}
        </StatusBadge>
      ) : null}

      {labelled ? (
        <div aria-hidden="true" className="mt-1 flex border-t border-border pt-1">
          {outputs.map((output) => (
            <span key={output.id} className="flex-1 truncate text-center text-caption font-medium text-ink-muted">
              {output.label}
            </span>
          ))}
        </div>
      ) : null}

      {outputs.length > 0 ? (
        <div className="absolute inset-x-0 bottom-0 flex">
          {outputs.map((output) => (
            <span key={output.id} className="relative flex-1">
              <Handle
                type="source"
                id={output.id}
                position={Position.Bottom}
                isConnectable={isConnectable}
                aria-label={output.label ? t('workflows.canvas.outputNamed', { branch: output.label }) : t('workflows.canvas.output')}
              />
            </span>
          ))}
        </div>
      ) : null}
    </div>
  )
}

export const FlowNode = memo(FlowNodeComponent)
