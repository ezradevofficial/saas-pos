import { useId, useState } from 'react'
import { cn } from '@/lib/utils'
import { normalizeOptions } from '@/lib/options'
import { Combobox } from './Combobox'
import { controlWrapClasses, Field } from './Field'

// A labelled, searchable picker (owner ruling 2026-10-08: no native <select>).
// Same props as before; onChange still receives an event-like object, so call
// sites keep reading `event.target.value`. With `name`, a hidden input carries
// the value in forms.
export function Select({
  label,
  help,
  error,
  placeholder,
  options = [],
  value,
  defaultValue,
  onChange,
  disabled,
  required,
  name,
  id,
  className,
  ...rest
}) {
  const autoId = useId()
  const selectId = id ?? autoId
  // Uncontrolled: like a native select, start on the placeholder, else the first option.
  const [inner, setInner] = useState(() => defaultValue ?? (placeholder ? '' : (normalizeOptions(options)[0]?.value ?? '')))
  const current = value !== undefined ? value : inner

  const change = (next) => {
    if (value === undefined) setInner(next)
    const target = { value: next, name }
    onChange?.({ target, currentTarget: target })
  }

  return (
    <Field id={selectId} label={label} help={help} error={error} required={required} className={className}>
      <div className={cn(controlWrapClasses, error && 'border-danger hover:border-danger')}>
        <Combobox
          id={selectId}
          value={current}
          onValueChange={change}
          options={options}
          placeholder={placeholder}
          disabled={disabled}
          required={required}
          invalid={Boolean(error)}
          name={name}
          aria-describedby={error || help ? `${selectId}-msg` : undefined}
          className="h-control w-full min-w-0 flex-1 cursor-pointer bg-transparent px-3 text-body text-ink outline-none disabled:cursor-not-allowed disabled:text-ink-muted"
          {...rest}
        />
      </div>
    </Field>
  )
}
