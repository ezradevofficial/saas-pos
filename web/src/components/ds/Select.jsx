import { useId } from 'react'
import { cn } from '@/lib/utils'
import { controlWrapClasses, Field } from './Field'
import { Icon } from './Icon'

// A labelled native select (short lists). Native on purpose: it keeps the
// SelectHTMLAttributes contract (value/onChange events, forms) and the
// platform picker on phones. shadcn's Select is for custom menus.
export function Select({ label, help, error, placeholder, options = [], className, id, required, ...rest }) {
  const autoId = useId()
  const selectId = id ?? autoId
  // With a placeholder and no value given, start on the placeholder instead of the first option.
  const startOnPlaceholder = placeholder && rest.value === undefined && rest.defaultValue === undefined
  return (
    <Field id={selectId} label={label} help={help} error={error} required={required} className={className}>
      <div className={cn(controlWrapClasses, error && 'border-danger hover:border-danger')}>
        <select
          id={selectId}
          required={required}
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={error || help ? `${selectId}-msg` : undefined}
          className="h-control w-full min-w-0 flex-1 cursor-pointer appearance-none bg-transparent pr-10 pl-3 text-body text-ink outline-none disabled:cursor-not-allowed disabled:text-ink-muted"
          {...(startOnPlaceholder ? { defaultValue: '' } : {})}
          {...rest}
        >
          {placeholder ? (
            <option value="" disabled>
              {placeholder}
            </option>
          ) : null}
          {options.map((option) => {
            const value = typeof option === 'string' ? option : option.value
            const text = typeof option === 'string' ? option : option.label
            return (
              <option key={value} value={value}>
                {text}
              </option>
            )
          })}
        </select>
        <Icon name="chevron" className="pointer-events-none absolute right-3 text-ink-muted" />
      </div>
    </Field>
  )
}
