import { useId } from 'react'
import { Label } from '@/components/ui/label'
import { Switch as SwitchPrimitive } from '@/components/ui/switch'
import { cn } from '@/lib/utils'

// A setting that applies immediately. Built on the shadcn (Radix) switch:
// role="switch" with aria-checked.
export function Switch({ checked, onChange, label, disabled, id, className, 'aria-label': ariaLabel }) {
  const autoId = useId()
  const switchId = id ?? autoId
  return (
    <div className={cn('flex items-start gap-3', className)}>
      <span className="flex h-5 items-center">
        <SwitchPrimitive
          id={switchId}
          checked={Boolean(checked)}
          onCheckedChange={(next) => onChange?.(next)}
          disabled={disabled}
          aria-label={label ? undefined : ariaLabel}
          className={cn(
            'border-border-strong data-checked:border-primary data-checked:bg-primary data-unchecked:bg-surface-300 dark:data-unchecked:bg-surface-300',
            'focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
            'disabled:opacity-40 data-disabled:opacity-40',
            '*:ring-1 *:ring-border-strong *:data-checked:bg-on-primary *:data-checked:ring-0',
          )}
        />
      </span>
      {label ? (
        <Label htmlFor={switchId} className="cursor-pointer text-body font-normal text-ink">
          {label}
        </Label>
      ) : null}
    </div>
  )
}
