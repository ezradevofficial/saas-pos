import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'
import { setLocale } from '@/i18n'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { LogoMark } from './Sidebar'

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

/** Sign-in, sign-up and the other account pages: one centred column. */
export function AuthLayout({ children }) {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="flex items-center justify-between gap-4 px-4 py-4 md:px-10">
        <div className="flex items-center gap-3">
          <LogoMark tone="page" />
          <span className="text-h3 text-ink">{t('app.name')}</span>
        </div>
        <LanguageSwitch />
      </header>
      <main className="flex flex-1 items-start justify-center px-4 py-6 md:py-12">
        <div className="flex w-full max-w-auth flex-col gap-6">{children ?? <Outlet />}</div>
      </main>
    </div>
  )
}
