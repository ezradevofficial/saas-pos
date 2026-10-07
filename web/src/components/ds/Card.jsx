import { Card as CardPrimitive, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

// One topic per card: hairline border, 10px corners, no shadow.
export function Card({ title, subtitle, actions, children, className }) {
  return (
    <CardPrimitive className={cn('gap-0 overflow-visible rounded-lg border border-border bg-surface-200 py-0 text-body text-ink ring-0', className)}>
      {title || actions ? (
        <header className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div className="min-w-0">
            {title ? <h3 className="text-h3 text-ink">{title}</h3> : null}
            {subtitle ? <div className="text-caption text-ink-muted">{subtitle}</div> : null}
          </div>
          {actions ? <div className="flex shrink-0 gap-2">{actions}</div> : null}
        </header>
      ) : null}
      <CardContent className="p-5">{children}</CardContent>
    </CardPrimitive>
  )
}
