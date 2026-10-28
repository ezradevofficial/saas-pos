import { lazy } from 'react'
import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { appName } from '../config'

export const supportedLocales = ['en', 'fr']

// NFR-02: each catalogue is its own chunk, loaded when its language is first
// used, so the first load carries only the active language (about half of the
// two). Both files stay the source the missing-key check reads (L10N-02,
// scripts/check-i18n.mjs), and web/scripts/bundle-budget.mjs counts the larger
// one in the first load.
const catalogues = {
  en: () => import('../locales/en.json'),
  fr: () => import('../locales/fr.json'),
}

// NFR-02: the strings of the newer vertical modules are chunks of their own
// (src/locales/modules/<locale>/<module>.json, each holding one top-level key),
// loaded when one of the module's routes first loads (lazyModule below) and
// again for a language switched to later. scripts/check-i18n.mjs reads them
// together with the main catalogues, so the missing-key check sees every key.
export const moduleNames = ['restaurant', 'pharmacy', 'hotel', 'fuel', 'vansales', 'salon']
const moduleCatalogues = import.meta.glob('../locales/modules/*/*.json')
const activeModules = new Set()
const loadedModules = new Set()

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

function remember(locale) {
  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Storage may be blocked (a private window): the language still changes for this visit.
  }
}

function setDocumentLanguage(locale) {
  if (typeof document !== 'undefined') document.documentElement.lang = locale
}

const hasMain = (locale) => i18n.hasResourceBundle(locale, 'translation')
const missingModules = (locale) => [...activeModules].filter((name) => !loadedModules.has(`${locale}:${name}`))

export const isLocaleLoaded = (locale) => hasMain(locale) && missingModules(locale).length === 0

const pending = {}

/** Fetches one catalogue chunk once; a failed load (offline) can be retried. */
function fetchOnce(key, load, add) {
  pending[key] ??= load()
    .then((module) => add(module.default))
    .finally(() => {
      delete pending[key]
    })
  return pending[key]
}

/** Loads a language's catalogue, and the strings of the modules already in use, once. */
export function loadLocale(locale) {
  const work = []
  if (!hasMain(locale)) {
    work.push(fetchOnce(locale, catalogues[locale], (tree) => i18n.addResourceBundle(locale, 'translation', tree, true, true)))
  }
  for (const name of missingModules(locale)) {
    const load = moduleCatalogues[`../locales/modules/${locale}/${name}.json`]
    work.push(
      fetchOnce(`${locale}:${name}`, load, (tree) => {
        i18n.addResourceBundle(locale, 'translation', tree, true, true)
        loadedModules.add(`${locale}:${name}`)
      }),
    )
  }
  return Promise.all(work).then(() => undefined)
}

// A module whose pages reuse another module's components (and so its strings): the
// forecourt screens use the pharmacy item picker, document flow and place helpers.
export const moduleDependencies = { fuel: ['pharmacy'] }

/** The strings of a module (and of the modules it reuses), in the current language and in any language switched to later. */
export function loadModule(name) {
  for (const needed of [name, ...(moduleDependencies[name] ?? [])]) activeModules.add(needed)
  return loadLocale(i18n.language ?? 'en')
}

/** A lazy route page that renders only once its module's strings have arrived. */
export function lazyModule(name, importer) {
  return lazy(() => Promise.all([importer(), loadModule(name)]).then(([page]) => page))
}

// The language last asked for: a slower load of an earlier choice never wins over it.
let requested = null

function apply(locale) {
  remember(locale)
  setDocumentLanguage(locale)
  return i18n.changeLanguage(locale)
}

/**
 * Switches the UI language. An already loaded language switches at once; another
 * is fetched first and the screen stays in the current language until it has
 * arrived. If it cannot be fetched (offline), nothing changes.
 */
export function setLocale(...candidates) {
  const locale = resolveLocale(...candidates)
  requested = locale
  if (isLocaleLoaded(locale)) return apply(locale)
  return loadLocale(locale).then(
    () => (requested === locale ? apply(locale) : undefined),
    () => {
      if (requested === locale) requested = i18n.language
    },
  )
}

// Web locale: the remembered one (the last profile or choice), then the browser, then "en";
// the user profile wins once it loads (setLocale).
const browserLocale = typeof navigator === 'undefined' ? undefined : navigator.language

/** L10N-01: the language of the first paint. */
export function initialLocale(browser = browserLocale) {
  return resolveLocale(storedLocale(), browser)
}

const firstLocale = initialLocale()

i18n.use(initReactI18next).init({
  resources: {},
  lng: firstLocale,
  fallbackLng: 'en',
  supportedLngs: supportedLocales,
  initAsync: false,
  interpolation: { escapeValue: false, defaultVariables: { appName } },
})

setDocumentLanguage(firstLocale)

/**
 * Resolves once the first paint's language is loaded; main.jsx renders after it.
 * If that catalogue cannot be fetched, English is tried before rendering anyway.
 */
export const i18nReady = loadLocale(firstLocale)
  .then(
    () => firstLocale,
    () => loadLocale('en').then(() => 'en'),
  )
  .then((locale) => {
    if (requested !== null && requested !== locale) return undefined
    setDocumentLanguage(locale)
    return i18n.changeLanguage(locale)
  })
  .catch(() => undefined)

export default i18n
