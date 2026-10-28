import { useTranslation } from 'react-i18next'
import { Link, useInRouterContext, useLocation } from 'react-router'
import { Icon } from '@/components/ds/Icon'
import { usePageTitle } from '@/lib/usePageTitle'

/**
 * A small "?" beside the title: opens the help page narrowed to the topics
 * written for this route (the topics' `screens`; RBAC-08). It sits on the
 * heading's row and is left out of the help page itself.
 */
function PageHelpLink() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  if (pathname.startsWith('/help')) return null
  return (
    <Link
      to={`/help?screen=${encodeURIComponent(pathname)}`}
      aria-label={t('help.forThisPage')}
      title={t('help.forThisPage')}
      className="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-surface-300 hover:text-ink"
    >
      <Icon name="help" size={16} />
    </Link>
  )
}

/**
 * The page title (h1, 24px), an optional caption above and description
 * below, and actions. `embedded`: the page sits inside another (a setup
 * step), which owns the h1 and the browser title; only the actions show.
 */
export function PageHeader({ title, eyebrow, description, actions, embedded = false }) {
  usePageTitle(title, !embedded)
  const inRouter = useInRouterContext()
  if (embedded) return actions ? <div className="flex flex-wrap justify-end gap-2">{actions}</div> : null
  return (
    <header className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 flex-col gap-1">
        {eyebrow ? <p className="text-body text-ink-muted">{eyebrow}</p> : null}
        <div className="flex items-center gap-2">
          <h1 className="text-h1 text-ink">{title}</h1>
          {inRouter ? <PageHelpLink /> : null}
        </div>
        {description ? <p className="text-body text-ink-muted">{description}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
    </header>
  )
}
