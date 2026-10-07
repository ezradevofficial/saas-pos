import { cn } from '@/lib/utils'
import { Icon } from './Icon'

const TONES = {
  info: { icon: 'info', box: 'border-border bg-surface-200', iconColour: 'text-primary' },
  success: { icon: 'check', box: 'border-transparent bg-success-tint', iconColour: 'text-success' },
  warning: { icon: 'alert', box: 'border-transparent bg-warning-tint', iconColour: 'text-warning' },
  danger: { icon: 'x', box: 'border-transparent bg-danger-tint', iconColour: 'text-danger' },
}

export function Alert({ tone = 'info', title, children, action, className }) {
  const style = TONES[tone] ?? TONES.info
  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={cn('flex items-start gap-3 rounded-md border px-4 py-3 text-body text-ink', style.box, className)}
    >
      <Icon name={style.icon} size={18} className={cn('mt-px', style.iconColour)} />
      <div className="min-w-0 flex-1">
        {title ? <div className="font-medium">{title}</div> : null}
        {children ? <div className="text-ink-muted">{children}</div> : null}
      </div>
      {action ? <div className="shrink-0 self-center">{action}</div> : null}
    </div>
  )
}
