import { useId } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

// A native checkbox keeps the InputHTMLAttributes contract (checked/onChange,
// defaultChecked, forms). appearance-none lets tokens draw it: a border-strong
// outline, filled with primary when checked. Use Switch for settings that apply immediately.
export function Checkbox({ label, help, className, id, ...rest }) {
  const autoId = useId()
  const inputId = id ?? autoId
  return (
    <div className={cn('flex items-start gap-2', className)}>
      <span className="relative flex h-5 shrink-0 items-center">
        <input
          type="checkbox"
          id={inputId}
          className={cn(
            'peer size-4 shrink-0 cursor-pointer appearance-none rounded-sm border border-border-strong bg-surface-200 transition-colors',
            'checked:border-primary checked:bg-primary hover:border-ink-muted checked:hover:border-primary',
            'disabled:cursor-not-allowed disabled:border-border disabled:bg-surface-300 disabled:checked:border-border-strong disabled:checked:bg-border-strong',
            'focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
          )}
          {...rest}
        />
        <Icon
          name="check"
          size={12}
          className="pointer-events-none absolute inset-x-0 mx-auto hidden text-on-primary peer-checked:block"
        />
      </span>
      <Label htmlFor={inputId} className="cursor-pointer flex-col items-start gap-0 text-body font-normal text-ink">
        {label}
        {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
      </Label>
    </div>
  )
}
