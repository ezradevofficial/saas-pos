import { useTranslation } from 'react-i18next'
import { NavLink, Outlet } from 'react-router'
import { Button } from '@/components/ds'
import { LogoMark } from '@/layouts/Sidebar'
import { cn } from '@/lib/utils'
import { usePlatformAuth } from './usePlatformAuth'

/** ADM-07: each area of the console, shown to the staff whose role allows it. */
const NAV = [
  { to: '/platform/tenants', label: 'platform.nav.tenants', permission: 'platform.tenants.view', end: true },
  { to: '/platform/tenants/new', label: 'platform.nav.newTenant', permission: 'platform.tenants.create' },
  { to: '/platform/catalogue', label: 'platform.nav.catalogue', permission: 'platform.catalogue.view' },
  { to: '/platform/support', label: 'admin.nav.support', permission: 'platform.support.request' },
  { to: '/platform/packs', label: 'admin.nav.packs', permission: 'platform.packs.view' },
  { to: '/platform/reports', label: 'admin.nav.reports', permission: 'platform.reports.view' },
  { to: '/platform/staff', label: 'admin.nav.staff', permission: 'platform.staff.manage' },
]

/**
 * The platform console (ADR 013), outside the tenant app shell: a quiet
 * top bar with the console's areas, the staff member and sign-out. No
 * tenant data is shown here.
 */
export function PlatformLayout() {
  const { t } = useTranslation()
  const { user, signOut } = usePlatformAuth()
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border bg-surface-200 px-4 py-3 md:px-10">
        <div className="flex min-w-0 items-center gap-6">
          <div className="flex items-center gap-3">
            <LogoMark tone="page" />
            <span className="truncate text-label text-ink">{t('platform.console')}</span>
          </div>
          <nav aria-label={t('platform.console')} className="flex flex-wrap items-center gap-4">
            {NAV.filter((item) => !item.permission || user?.abilities?.includes(item.permission)).map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => cn('rounded-sm py-1 text-label hover:text-ink', isActive ? 'text-ink' : 'text-ink-muted')}
              >
                {t(item.label)}
              </NavLink>
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-3">
          {user ? (
            <span className="text-caption text-ink-muted">
              {user.name} · {t(`platform.roles.${user.role}`)}
            </span>
          ) : null}
          <Button variant="ghost" onClick={() => signOut()}>
            {t('shell.signOut')}
          </Button>
        </div>
      </header>
      <main className="min-w-0 flex-1 px-4 py-6 md:p-10">
        <div className="mx-auto flex max-w-content flex-col gap-6">
          <Outlet />
        </div>
      </main>
    </div>
  )
}
