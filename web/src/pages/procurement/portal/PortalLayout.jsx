import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'
import { LanguageSwitch } from '@/layouts/AuthLayout'
import { LogoMark, PoweredBy } from '@/layouts/Sidebar'
import { usePageTitle } from '@/lib/usePageTitle'
import { brandLogo } from '@/theme/brandLogo'
import { useTheme } from '@/theme/ThemeProvider'
import { isDarkTheme } from '@/theme/themes'
import { lockDownPortal } from './portalSecurity'

/**
 * PRC-07: the supplier portal's own frame: no app sidebar and no tenant
 * sign-in, the host's brand (BR-04) and the theme's tokens, one column;
 * no Referer and a strict Content-Security-Policy (portalSecurity).
 */
export function PortalLayout() {
  const { t } = useTranslation()
  const { theme, brand } = useTheme()
  const logo = brandLogo(brand, isDarkTheme(theme))
  usePageTitle(t('procurement.portal.title'))
  // PRC-07 (owner decision 2026-10-14): same origin as the back office, so the portal locks its pages down.
  useEffect(() => lockDownPortal(), [])
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex items-center justify-between gap-4 border-b border-border px-4 py-4 md:px-10">
        <div className="flex min-w-0 items-center gap-3">
          {logo ? (
            <img src={logo} alt={brand?.tenantName ?? t('app.name')} className="max-h-10 max-w-full object-contain object-left" />
          ) : (
            <>
              <LogoMark tone="page" />
              <span className="truncate text-h3 text-ink">{brand?.tenantName ?? t('procurement.portal.title')}</span>
            </>
          )}
        </div>
        <LanguageSwitch />
      </header>
      <main className="flex flex-1 justify-center px-4 py-6 md:py-10">
        <div className="flex w-full max-w-3xl flex-col gap-6">
          <Outlet />
        </div>
      </main>
      <footer className="px-4 py-4 text-center md:px-10">
        <PoweredBy className="text-ink-muted" />
      </footer>
    </div>
  )
}
