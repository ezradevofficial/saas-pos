import { Tabs as TabsPrimitive, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { cn } from '@/lib/utils'

// Views of the same subject: a hairline with an ink underline on the active tab.
export function Tabs({ items = [], value, onChange, className }) {
  return (
    <TabsPrimitive value={value} onValueChange={(next) => onChange?.(next)} className={cn('gap-0', className)}>
      <TabsList variant="line" className="h-auto w-full justify-start gap-5 overflow-x-auto rounded-none border-b border-border p-0">
        {items.map((item) => (
          <TabsTrigger
            key={item.value}
            value={item.value}
            className={cn(
              '-mb-px h-auto flex-none gap-2 rounded-none border-0 border-b-2 border-transparent px-0 py-2 text-label text-ink-muted hover:text-ink after:hidden',
              'data-active:border-b-ink data-active:text-ink dark:data-active:border-transparent dark:data-active:border-b-ink dark:data-active:text-ink',
              // Same specificity as the stock line-variant dark rule, so the ink underline stays in dark mode.
              'dark:group-data-[variant=line]/tabs-list:data-active:border-b-ink',
              'focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
            )}
          >
            {item.label}
            {item.count != null ? <span className="font-normal text-ink-muted">{item.count}</span> : null}
          </TabsTrigger>
        ))}
      </TabsList>
    </TabsPrimitive>
  )
}
