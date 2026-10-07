import { useTranslation } from 'react-i18next'
import { Button as ButtonPrimitive } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

// Variant classes replace shadcn's through tailwind-merge; the stock "default"
// variant is used because it has no dark-only classes to fight.
const VARIANTS = {
  primary: 'bg-primary text-on-primary hover:bg-primary-hover',
  secondary: 'border-border-strong bg-surface-200 text-ink hover:bg-surface-300',
  ghost: 'bg-transparent text-ink-muted hover:bg-surface-300 hover:text-ink',
  danger: 'border-border-strong bg-transparent text-danger hover:border-danger hover:bg-danger hover:text-on-danger',
  pay: 'bg-accent text-on-accent hover:bg-accent-hover',
}

const SIZES = {
  md: 'h-control gap-2 px-3 text-label',
  lg: 'h-12 gap-2 px-5 text-body-lg font-medium',
}

function buttonClasses({ variant = 'secondary', size = 'md', block = false, className } = {}) {
  return cn(
    'rounded-md font-medium disabled:cursor-not-allowed disabled:opacity-40',
    'focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
    VARIANTS[variant] ?? VARIANTS.secondary,
    SIZES[size] ?? SIZES.md,
    block && 'w-full',
    className,
  )
}

export function Button({ variant = 'secondary', size = 'md', icon, block = false, loading = false, disabled, className, children, ...rest }) {
  const { t } = useTranslation()
  return (
    <ButtonPrimitive
      type="button"
      {...rest}
      variant="default"
      size="default"
      disabled={disabled || loading}
      aria-busy={loading ? 'true' : undefined}
      data-ds-variant={variant}
      className={cn(buttonClasses({ variant, size, block }), loading && 'cursor-progress', className)}
    >
      {loading ? (
        <>
          <Icon name="sync" className="animate-spin motion-reduce:animate-none" />
          <span className="sr-only">{t('ds.button.loading')}</span>
        </>
      ) : icon ? (
        <Icon name={icon} />
      ) : null}
      {children}
    </ButtonPrimitive>
  )
}
