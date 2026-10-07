import { useId } from 'react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { controlWrapClasses, Field } from './Field'

export function TextField({ label, help, error, prefix, suffix, className, id, required, ...rest }) {
  const autoId = useId()
  const inputId = id ?? autoId
  return (
    <Field id={inputId} label={label} help={help} error={error} required={required} className={className}>
      <div className={cn(controlWrapClasses, error && 'border-danger hover:border-danger')}>
        {prefix ? <span className="pl-3 text-body text-ink-muted">{prefix}</span> : null}
        <Input
          id={inputId}
          required={required}
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={error || help ? `${inputId}-msg` : undefined}
          className={cn(
            'h-control flex-1 rounded-none border-0 bg-transparent px-3 py-0 text-body text-ink placeholder:text-ink-muted',
            'focus-visible:ring-0 aria-invalid:ring-0 disabled:bg-transparent disabled:text-ink-muted disabled:opacity-100',
            'dark:bg-transparent dark:disabled:bg-transparent',
          )}
          {...rest}
        />
        {suffix ? <span className="pr-3 text-body text-ink-muted">{suffix}</span> : null}
      </div>
    </Field>
  )
}
