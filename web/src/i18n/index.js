import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { appName } from '../config'
import en from '../locales/en.json'
import fr from '../locales/fr.json'

export const supportedLocales = ['en', 'fr']

/** First supported language among the candidates ("fr-CD" counts as "fr"), else "en". */
export function resolveLocale(...candidates) {
  for (const candidate of candidates) {
    const language = String(candidate ?? '').toLowerCase().split(/[-_]/)[0]
    if (supportedLocales.includes(language)) return language
  }
  return 'en'
}

/** Where the browser remembers the last language used, so the first paint (before the profile loads) is in it. */
export const LOCALE_STORAGE_KEY = 'app.locale'

function storedLocale() {
  try {
    return window.localStorage.getItem(LOCALE_STORAGE_KEY)
  } catch {
    return null
  }
}

export function setLocale(...candidates) {
  const locale = resolveLocale(...candidates)
  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Storage may be blocked (a private window): the language still changes for this visit.
  }
  if (typeof document !== 'undefined') document.documentElement.lang = locale
  return i18n.changeLanguage(locale)
}

// Web locale: the remembered one (the last profile or choice), then the browser, then "en";
// the user profile wins once it loads (setLocale).
const browserLocale = typeof navigator === 'undefined' ? undefined : navigator.language

/** L10N-01: the language of the first paint. */
export function initialLocale(browser = browserLocale) {
  return resolveLocale(storedLocale(), browser)
}

i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, fr: { translation: fr } },
  lng: initialLocale(),
  fallbackLng: 'en',
  initAsync: false,
  interpolation: { escapeValue: false, defaultVariables: { appName } },
})

if (typeof document !== 'undefined') document.documentElement.lang = i18n.language

export default i18n
