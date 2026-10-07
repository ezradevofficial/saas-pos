import { useId } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

// A native checkbox keeps the InputHTMLAttributes contract (checked/onChange,
// defaultChecked, forms). Use Switch for settings that apply immediately.
export function Checkbox({ label, help, className, id, ...rest }) {
  const autoId = useId()
  const inputId = id ?? autoId
  return (
    <div className={cn('flex items-start gap-2', className)}>
      <span className="flex h-5 items-center">
        <input
          type="checkbox"
          id={inputId}
          className="size-4 shrink-0 cursor-pointer rounded-sm accent-primary disabled:cursor-not-allowed disabled:opacity-40"
          {...rest}
        />
      </span>
      <Label htmlFor={inputId} className="cursor-pointer flex-col items-start gap-0 text-body font-normal text-ink">
        {label}
        {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
      </Label>
    </div>
  )
}
