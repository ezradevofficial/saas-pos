import { useTranslation } from 'react-i18next'
import { Link, NavLink } from 'react-router'
import { PageOutlet } from '@/layouts/PageOutlet'
import { useAuth } from '@/auth/useAuth'
import { LanguageSwitch } from '@/layouts/AuthLayout'
import { LogoMark } from '@/layouts/Sidebar'
import { cn } from '@/lib/utils'
import { GUIDES } from './guides'

function SideLink({ to, end, children }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        cn('rounded-sm px-2 py-1 text-label text-ink-muted hover:bg-surface-300 hover:text-ink', isActive && 'bg-surface-300 font-medium text-ink')
      }
    >
      {children}
    </NavLink>
  )
}

/**
 * API-04: the developer portal, public (no sign-in), in English and French:
 * guides, the API reference and the changelog. Signed-in people get a way
 * back to the app and to their API keys.
 */
export function DevelopersLayout() {
  const { t } = useTranslation()
  const { token } = useAuth()

  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border px-4 py-3 md:px-10">
        <Link to="/developers" className="flex min-w-0 items-center gap-3">
          <LogoMark tone="page" />
          <span className="truncate text-h3 text-ink">{t('developers.brand', { name: t('app.name') })}</span>
        </Link>
        <div className="flex flex-wrap items-center gap-4">
          <LanguageSwitch />
          {token ? (
            <Link to="/settings/api-keys" className="text-label text-primary hover:text-primary-hover">
              {t('developers.nav.keys')}
            </Link>
          ) : (
            <Link to="/sign-in" className="text-label text-primary hover:text-primary-hover">
              {t('developers.nav.signIn')}
            </Link>
          )}
        </div>
      </header>
      <div className="mx-auto flex w-full max-w-content flex-1 flex-col gap-6 px-4 py-6 md:flex-row md:px-10 md:py-10">
        <nav aria-label={t('developers.nav.label')} className="flex shrink-0 flex-col gap-1 md:w-sidebar">
          <SideLink to="/developers" end>
            {t('developers.nav.overview')}
          </SideLink>
          <p className="mt-3 px-2 text-caption text-ink-muted">{t('developers.nav.guides')}</p>
          {GUIDES.map((guide) => (
            <SideLink key={guide.id} to={`/developers/guides/${guide.id}`}>
              {t(`developers.guides.${guide.id}.title`)}
            </SideLink>
          ))}
          <p className="mt-3 px-2 text-caption text-ink-muted">{t('developers.nav.more')}</p>
          <SideLink to="/developers/reference">{t('developers.nav.reference')}</SideLink>
          <SideLink to="/developers/errors">{t('developers.nav.errors')}</SideLink>
          <SideLink to="/developers/changelog">{t('developers.nav.changelog')}</SideLink>
        </nav>
        <main className="flex min-w-0 flex-1 flex-col gap-6">
          <PageOutlet />
        </main>
      </div>
    </div>
  )
}

/** A block of code, selectable, in the code font (technical view). */
export function CodeBlock({ children, label }) {
  return (
    <pre aria-label={label} className="overflow-x-auto rounded-md border border-border bg-surface-200 p-4 font-mono text-caption text-ink">
      <code>{children}</code>
    </pre>
  )
}
