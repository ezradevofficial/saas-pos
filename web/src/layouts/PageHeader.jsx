import { usePageTitle } from '@/lib/usePageTitle'

/** The page title (h1, 24px), an optional caption above and description below, and actions. */
export function PageHeader({ title, eyebrow, description, actions }) {
  usePageTitle(title)
  return (
    <header className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 flex-col gap-1">
        {eyebrow ? <p className="text-body text-ink-muted">{eyebrow}</p> : null}
        <h1 className="text-h1 text-ink">{title}</h1>
        {description ? <p className="text-body text-ink-muted">{description}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
    </header>
  )
}
