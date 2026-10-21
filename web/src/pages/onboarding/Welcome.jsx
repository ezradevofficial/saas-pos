import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { Button } from '@/components/ds'
import { resolveLocale, setLocale } from '@/i18n'
import { cn } from '@/lib/utils'
import { AuthPage, TextLink } from '../auth/AuthPage'

const COUNTRIES = ['KE', 'CD']
const LANGUAGES = ['en', 'fr']

function Choice({ pressed, onClick, title, detail, lang }) {
  return (
    <button
      type="button"
      lang={lang}
      aria-pressed={pressed}
      onClick={onClick}
      className={cn(
        'flex min-h-12 flex-col items-start gap-1 rounded-lg border px-4 py-3 text-left text-ink',
        pressed ? 'border-primary bg-primary-tint' : 'border-border bg-surface-200 hover:bg-surface-300',
      )}
    >
      <span className="text-body-lg font-medium">{title}</span>
      {detail ? <span className="text-caption text-ink-muted">{detail}</span> : null}
    </button>
  )
}

/**
 * ONB-01: the first screen of sign-up. The country (which sets the
 * currency, time zone, taxes and e-invoicing) and the language come
 * first; the account form follows on /sign-up.
 */
export default function Welcome() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  // API-05: a developer sandbox, asked for from the developer portal, passes through to sign-up.
  const sandbox = useSearchParams()[0].get('sandbox') === '1'
  const [locale, setChosenLocale] = useState(() => resolveLocale(typeof navigator === 'undefined' ? undefined : navigator.language))
  const [country, setCountry] = useState(() => (locale === 'fr' ? 'CD' : 'KE'))

  const chooseLocale = (next) => {
    setChosenLocale(next)
    setLocale(next)
  }

  return (
    <AuthPage
      title={t('onboarding.welcome.title')}
      intro={t('onboarding.welcome.intro')}
      footer={
        <p>
          {t('auth.signUp.haveAccount')} <TextLink to="/sign-in">{t('auth.signUp.signIn')}</TextLink>
        </p>
      }
    >
      <div className="flex flex-col gap-5">
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-2 text-label text-ink">{t('onboarding.welcome.country')}</legend>
          {COUNTRIES.map((code) => (
            <Choice
              key={code}
              pressed={country === code}
              onClick={() => setCountry(code)}
              title={t(`auth.countries.${code}`)}
              detail={t(`onboarding.welcome.countryDetail.${code}`)}
            />
          ))}
        </fieldset>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-2 text-label text-ink">{t('onboarding.welcome.language')}</legend>
          <div className="grid grid-cols-2 gap-2">
            {LANGUAGES.map((code) => (
              <Choice key={code} lang={code} pressed={locale === code} onClick={() => chooseLocale(code)} title={t(`shell.languages.${code}`)} />
            ))}
          </div>
        </fieldset>
        <p className="text-caption text-ink-muted">{t('onboarding.welcome.trial')}</p>
        <Button variant="primary" block size="lg" onClick={() => navigate(`/sign-up?country=${country}&locale=${locale}${sandbox ? '&sandbox=1' : ''}`)}>
          {t('onboarding.welcome.continue')}
        </Button>
      </div>
    </AuthPage>
  )
}
