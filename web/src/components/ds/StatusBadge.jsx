import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

// Status is a small dot and a word, never a filled pill and never colour alone.
const DOT = {
  neutral: 'bg-neutral-dot',
  info: 'bg-primary',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  accent: 'bg-accent-ink',
}

const WORD = {
  danger: 'text-danger',
  accent: 'text-accent-ink',
}

export function StatusBadge({ tone = 'neutral', children, className }) {
  return (
    <Badge
      variant="outline"
      data-tone={tone}
      className={cn(
        'h-auto gap-tight rounded-none border-0 bg-transparent px-0 py-0 text-caption font-medium text-ink',
        WORD[tone],
        className,
      )}
    >
      <span data-slot="status-dot" aria-hidden="true" className={cn('size-dot shrink-0 rounded-pill', DOT[tone] ?? DOT.neutral)} />
      {children}
    </Badge>
  )
}
