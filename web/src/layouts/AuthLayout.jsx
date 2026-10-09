import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'
import { setLocale } from '@/i18n'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { brandLogo } from '@/theme/brandLogo'
import { useTheme } from '@/theme/ThemeProvider'
import { isDarkTheme } from '@/theme/themes'
import { LogoMark, PoweredBy } from './Sidebar'

function LanguageSwitch() {
  const { t } = useTranslation()
  const locale = useLocale()
  const names = { en: t('shell.languages.en'), fr: t('shell.languages.fr') }
  return (
    <div role="group" aria-label={t('shell.language')} className="flex items-center gap-1 text-caption">
      {['en', 'fr'].map((code) => (
        <button
          key={code}
          type="button"
          lang={code}
          aria-pressed={locale === code}
          onClick={() => setLocale(code)}
          className={cn(
            'rounded-sm px-2 py-1 text-ink-muted hover:text-ink',
            locale === code && 'font-medium text-ink',
          )}
        >
          {names[code]}
        </button>
      ))}
    </div>
  )
}

/**
 * Sign-in, sign-up and the other account pages: one centred column. On a
 * tenant's host (BR-04) the header shows that tenant's logo, and the page
 * its colours; "Powered by" stays unless the platform hid it (BR-07).
 */
export function AuthLayout({ children }) {
  const { t } = useTranslation()
  const { theme, brand } = useTheme()
  const logo = brandLogo(brand, isDarkTheme(theme))
  const background = brand?.assets?.background ?? null
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex items-center justify-between gap-4 px-4 py-4 md:px-10">
        <div className="flex min-w-0 items-center gap-3">
          {logo ? (
            <img src={logo} alt={brand?.tenantName ?? t('app.name')} className="max-h-10 max-w-full object-contain object-left" />
          ) : (
            <>
              <LogoMark tone="page" />
              <span className="truncate text-h3 text-ink">{brand?.tenantName ?? t('app.name')}</span>
            </>
          )}
        </div>
        <LanguageSwitch />
      </header>
      <div className="flex flex-1">
        {background ? (
          // BR-04: the tenant's sign-in picture, decorative, beside the form on wide screens.
          <div className="relative hidden flex-1 lg:block">
            <img src={background} alt="" data-testid="auth-background" className="absolute inset-0 size-full object-cover" />
          </div>
        ) : null}
        <main className="flex flex-1 items-start justify-center px-4 py-6 md:py-12">
          <div className="flex w-full max-w-auth flex-col gap-6">{children ?? <Outlet />}</div>
        </main>
      </div>
      {brand ? (
        <footer className="px-4 py-4 text-center md:px-10">
          <PoweredBy className="text-ink-muted" />
        </footer>
      ) : null}
    </div>
  )
}
