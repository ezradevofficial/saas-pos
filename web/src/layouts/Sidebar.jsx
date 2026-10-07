import { useQueryClient } from '@tanstack/react-query'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink } from 'react-router'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { Icon } from '@/components/ds'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { setLocale } from '@/i18n'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { CompanySwitcher } from './CompanySwitcher'
import { NAV_GROUPS, visibleGroups } from './navigation'

const itemClasses = ({ isActive }) =>
  cn(
    'flex h-nav items-center gap-2 rounded-md px-2 text-body text-sidebar-ink transition-colors',
    'hover:bg-sidebar-active hover:text-sidebar-ink-active',
    isActive && 'bg-sidebar-active font-medium text-sidebar-ink-active shadow-sm',
  )

/**
 * The neutral mark until a logo exists (design system, Logo): an ink square
 * with a small surface square inside. On the sidebar it uses the sidebar's
 * ink and background, so it stays visible on dark sidebars (Executive).
 */
export function LogoMark({ tone = 'sidebar' }) {
  const page = tone === 'page'
  return (
    <span
      aria-hidden="true"
      className={cn('flex size-6 shrink-0 items-center justify-center rounded-sm', page ? 'bg-ink' : 'bg-sidebar-ink-active')}
    >
      <span className={cn('size-2 rounded-sm', page ? 'bg-surface-100' : 'bg-sidebar')} />
    </span>
  )
}

function LogoBlock() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const tenantName = user?.tenant?.name
  return (
    <div className="flex items-center gap-3 border-b border-sidebar-border px-2 pb-4">
      <LogoMark />
      <div className="min-w-0">
        <div className="truncate text-h3 text-sidebar-ink-active">{t('app.name')}</div>
        {tenantName ? <div className="truncate text-caption text-sidebar-ink">{tenantName}</div> : null}
      </div>
    </div>
  )
}

const menuItem = 'gap-2 rounded-md px-2 py-2 text-body'

function AccountMenu() {
  const { t } = useTranslation()
  const { user, signOut } = useAuth()
  const locale = useLocale()
  const queryClient = useQueryClient()

  const changeLanguage = async (next) => {
    setLocale(next)
    try {
      const response = await api.patch('me', { locale: next })
      queryClient.setQueryData(['me'], response.data)
    } catch {
      // The language still changes for this session.
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className={cn(
          'flex w-full min-w-0 items-center gap-2 rounded-md px-2 py-2 text-left text-sidebar-ink transition-colors',
          'hover:bg-sidebar-active hover:text-sidebar-ink-active data-[state=open]:bg-sidebar-active',
        )}
      >
        <span className="min-w-0 flex-1">
          <span className="block truncate text-body font-medium text-sidebar-ink-active">{user?.name}</span>
          <span className="block truncate text-caption">{user?.email ?? user?.phone}</span>
        </span>
        <Icon name="updown" />
        <span className="sr-only">{t('shell.account')}</span>
      </DropdownMenuTrigger>
      <DropdownMenuContent side="top" align="start" className="rounded-md border border-border p-1 shadow-lg ring-0">
        <DropdownMenuLabel className="px-2 py-2 text-caption text-ink-muted">{t('shell.language')}</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={locale} onValueChange={changeLanguage}>
          <DropdownMenuRadioItem value="en" lang="en" className={cn(menuItem, 'pr-6')}>
            {t('shell.languages.en')}
          </DropdownMenuRadioItem>
          <DropdownMenuRadioItem value="fr" lang="fr" className={cn(menuItem, 'pr-6')}>
            {t('shell.languages.fr')}
          </DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator className="mx-0 my-1" />
        <DropdownMenuItem className={menuItem} onSelect={() => signOut()}>
          <Icon name="signOut" />
          {t('shell.signOut')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** Sidebar content, shared by the desktop sidebar and the phone sheet. */
export function Sidebar({ onNavigate, className }) {
  const { t } = useTranslation()
  const permissions = usePermissions()
  const groups = permissions.isLoading ? [] : visibleGroups(NAV_GROUPS, permissions)
  const baseId = useId()

  return (
    <div className={cn('flex h-full min-h-0 flex-col gap-4 bg-sidebar px-3 py-4', className)}>
      <LogoBlock />
      <CompanySwitcher />
      <nav aria-label={t('nav.label')} className="-mt-2 flex min-h-0 flex-1 flex-col overflow-y-auto">
        {groups.map((group) => (
          <section key={group.id} aria-labelledby={`${baseId}-${group.id}`}>
            <h2 id={`${baseId}-${group.id}`} className="px-2 pt-4 pb-2 text-caption font-medium text-sidebar-ink uppercase">
              {group.label(t)}
            </h2>
            <ul className="flex flex-col gap-px">
              {group.items.map((item) => (
                <li key={item.to}>
                  <NavLink to={item.to} end={item.end} className={itemClasses} onClick={onNavigate}>
                    <Icon name={item.icon} />
                    <span className="truncate">{item.label(t)}</span>
                  </NavLink>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </nav>
      <div className="border-t border-sidebar-border pt-3">
        <AccountMenu />
      </div>
    </div>
  )
}
