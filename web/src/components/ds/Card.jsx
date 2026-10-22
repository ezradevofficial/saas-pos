import { Card as CardPrimitive, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

// One topic per card: hairline border, 10px corners, no shadow. A card with
// only a header (a title and a link) has no empty body under it, and no
// divider either.
export function Card({ title, subtitle, actions, children, className }) {
  const hasBody = children !== undefined && children !== null && children !== false
  return (
    <CardPrimitive className={cn('gap-0 overflow-visible rounded-lg border border-border bg-surface-200 py-0 text-body text-ink ring-0', className)}>
      {title || actions ? (
        <header className={cn('flex items-start justify-between gap-4 px-5 py-4', hasBody && 'border-b border-border')}>
          <div className="min-w-0">
            {title ? <h3 className="text-h3 text-ink">{title}</h3> : null}
            {subtitle ? <div className="text-caption text-ink-muted">{subtitle}</div> : null}
          </div>
          {actions ? <div className="flex shrink-0 gap-2">{actions}</div> : null}
        </header>
      ) : null}
      {hasBody ? <CardContent className="p-5">{children}</CardContent> : null}
    </CardPrimitive>
  )
}
