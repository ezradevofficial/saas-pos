import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

// Shared by TextField and Select: label, control, then help or error.
export const controlWrapClasses =
  'relative flex items-center rounded-md border border-border-strong bg-surface-200 transition-colors hover:border-ink-muted ' +
  'focus-within:border-focus focus-within:outline-2 focus-within:outline-solid focus-within:outline-offset-1 focus-within:outline-focus ' +
  'has-disabled:border-border has-disabled:bg-surface-300 has-disabled:hover:border-border'

export function Field({ id, label, help, error, required, className, children }) {
  return (
    <div className={cn('flex min-w-0 flex-col gap-tight', className)}>
      {label ? (
        <Label htmlFor={id} className="gap-0 text-label text-ink">
          {label}
          {required ? (
            <span aria-hidden="true" className="text-ink-muted">
              &nbsp;*
            </span>
          ) : null}
        </Label>
      ) : null}
      {children}
      {error ? (
        <div id={`${id}-msg`} className="flex items-center gap-1 text-caption text-danger">
          <Icon name="alert" size={14} />
          {error}
        </div>
      ) : help ? (
        <div id={`${id}-msg`} className="text-caption text-ink-muted">
          {help}
        </div>
      ) : null}
    </div>
  )
}
