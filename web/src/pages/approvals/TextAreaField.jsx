import { useId } from 'react'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'

/** A labelled multi-line field (a comment, a reason) in the design system's field frame; `inputClassName` styles the box (a mono formula). */
export function TextAreaField({ label, help, error, required, className, inputClassName, id, rows = 3, ...rest }) {
  const autoId = useId()
  const inputId = id ?? autoId
  return (
    <Field id={inputId} label={label} help={help} error={error} required={required} className={className}>
      <Textarea
        id={inputId}
        rows={rows}
        required={required}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error || help ? `${inputId}-msg` : undefined}
        className={cn(
          'min-h-0 rounded-md border-border-strong bg-surface-200 px-3 py-2 text-body text-ink placeholder:text-ink-muted hover:border-ink-muted',
          'focus-visible:border-focus focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-1 focus-visible:outline-focus',
          'aria-invalid:border-danger aria-invalid:ring-0 md:text-body dark:bg-surface-200',
          inputClassName,
        )}
        {...rest}
      />
    </Field>
  )
}
