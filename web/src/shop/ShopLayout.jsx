import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Icon } from '@/components/ds'
import { LanguageSwitch } from '@/layouts/AuthLayout'
import { PoweredBy } from '@/layouts/Sidebar'
import { usePageTitle } from '@/lib/usePageTitle'
import { lockDownPortal } from '@/pages/procurement/portal/portalSecurity'
import { brandLogo } from '@/theme/brandLogo'
import { useTheme } from '@/theme/ThemeProvider'
import { isDarkTheme } from '@/theme/themes'
import { useCart } from './cart'
import { useShop } from './useShop'

/**
 * ECOM-05, ECOM-07: the storefront's own frame: no app sidebar and no
 * tenant sign-in, the host's brand and theme tokens (BR-01, BR-04), the
 * cart and the account; no Referer and a strict Content-Security-Policy
 * (as the supplier portal), and never a back-office token (shopClient).
 */
export function ShopLayout() {
  const { t } = useTranslation()
  const { handle, store, query } = useShop()
  const { theme, brand } = useTheme()
  const { count } = useCart(handle)
  const logo = brandLogo(brand, isDarkTheme(theme))
  usePageTitle(store?.name ?? t('shop.title'))
  useEffect(() => lockDownPortal(), [])
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="border-b border-border bg-surface-200">
        <div className="mx-auto flex w-full max-w-content items-center justify-between gap-4 px-4 py-3 md:px-6">
          <Link to={`/shop/${handle}`} className="flex min-w-0 items-center gap-3">
            {logo ? <img src={logo} alt={store?.name ?? t('shop.title')} className="max-h-10 max-w-full object-contain object-left" /> : null}
            <span className="truncate text-h3 text-ink">{store?.name ?? t('shop.title')}</span>
          </Link>
          <nav aria-label={t('shop.nav')} className="flex items-center gap-4">
            <Link to={`/shop/${handle}/account`} className="flex items-center gap-1 text-ink-muted hover:text-ink">
              <Icon name="users" size={18} />
              <span className="hidden sm:inline">{t('shop.account.title')}</span>
            </Link>
            <Link to={`/shop/${handle}/cart`} className="flex items-center gap-1 text-ink hover:text-primary" aria-label={t('shop.cart.linkCount', { count })}>
              <Icon name="sales" size={18} />
              <span>{t('shop.cart.title')}</span>
              {count ? <span className="rounded-pill bg-primary px-2 text-caption text-on-primary tabular-nums">{count}</span> : null}
            </Link>
            <LanguageSwitch />
          </nav>
        </div>
      </header>
      <main className="mx-auto flex w-full max-w-content flex-1 flex-col gap-6 px-4 py-6 md:px-6">
        {query.isError ? <Alert tone="danger" title={query.error?.status === 404 ? t('shop.notFound') : errorMessage(query.error)} /> : null}
        {store ? <Outlet /> : null}
      </main>
      <footer className="border-t border-border px-4 py-4 text-center text-caption text-ink-muted md:px-6">
        {store?.contact_phone ? <span>{t('shop.contact', { phone: store.contact_phone })}{store.whatsapp ? ' · ' : ''}</span> : null}
        {store?.whatsapp ? <a className="text-primary hover:underline" href={store.whatsapp} target="_blank" rel="noreferrer noopener">{t('shop.whatsappUs')}</a> : null}
        <PoweredBy className="mt-2 text-ink-muted" />
      </footer>
    </div>
  )
}
