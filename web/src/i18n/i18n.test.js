import en from '../locales/en.json'
import fr from '../locales/fr.json'
import i18n, { initialLocale, isLocaleLoaded, loadLocale, LOCALE_STORAGE_KEY, moduleDependencies, moduleNames, resolveLocale, setLocale } from './index'

function flatten(object, prefix = '') {
  return Object.entries(object).flatMap(([key, value]) =>
    typeof value === 'object' && value !== null
      ? flatten(value, `${prefix}${key}.`)
      : [`${prefix}${key}`],
  )
}

describe('i18n', () => {
  afterEach(() => setLocale('en'))

  it('has the same keys in en and fr', () => {
    expect(flatten(fr).sort()).toEqual(flatten(en).sort())
  })

  it('has no empty French values', () => {
    const empty = flatten(fr).filter((key) => {
      const value = key.split('.').reduce((node, part) => node[part], fr)
      return String(value).trim() === ''
    })
    expect(empty).toEqual([])
  })

  it('words French terms in French order (Code QR, a space before the colon)', () => {
    expect(fr.documentTemplates.blocks.qr).toBe('Code QR')
    expect(fr.layouts.chart.tooltip).toBe('{{label}} : {{value}}')
  })

  it('returns the app name from VITE_APP_NAME for app.name', () => {
    expect(i18n.t('app.name')).toBe(import.meta.env.VITE_APP_NAME)
  })

  it('returns the app name in French too', () => {
    setLocale('fr')
    expect(i18n.t('app.name')).toBe(import.meta.env.VITE_APP_NAME)
  })

  it('switches language', () => {
    expect(i18n.t('common.cancel')).toBe('Cancel')
    setLocale('fr')
    expect(i18n.t('common.cancel')).toBe('Annuler')
  })

  describe('loading only the active language (NFR-02)', () => {
    beforeEach(async () => {
      await setLocale('en')
      // setup.js loads both for the screen tests; start from the app's state: English only.
      i18n.removeResourceBundle('fr', 'translation')
    })
    afterEach(() => loadLocale('fr'))

    it('fetches French on the switch and stays in English until it has arrived', async () => {
      expect(isLocaleLoaded('en')).toBe(true)
      expect(isLocaleLoaded('fr')).toBe(false)

      const switching = setLocale('fr')
      expect(i18n.language).toBe('en')
      expect(i18n.t('common.cancel')).toBe('Cancel')

      await switching
      expect(isLocaleLoaded('fr')).toBe(true)
      expect(i18n.language).toBe('fr')
      expect(i18n.t('common.cancel')).toBe('Annuler')
      expect(document.documentElement.lang).toBe('fr')
      expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('fr')
    })

    it('switches back at once to a language already loaded', async () => {
      await setLocale('fr')
      setLocale('en')
      expect(i18n.t('common.cancel')).toBe('Cancel')
    })

    it('keeps the last choice when an earlier one arrives later', async () => {
      const french = setLocale('fr')
      setLocale('en')
      await french
      expect(i18n.language).toBe('en')
      expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en')
    })
  })

  it('loads the pharmacy strings with the fuel module, whose pages reuse pharmacy components (L10N-02)', () => {
    expect(moduleDependencies.fuel).toContain('pharmacy')
    expect(Object.entries(moduleDependencies).flatMap(([name, needed]) => [name, ...needed]).every((name) => moduleNames.includes(name))).toBe(true)
  })

  it('resolves a locale from the first supported candidate, else en', () => {
    expect(resolveLocale('fr-CD', 'en')).toBe('fr')
    expect(resolveLocale(undefined, 'de-DE', 'fr')).toBe('fr')
    expect(resolveLocale('de', null)).toBe('en')
  })

  it('paints first in the remembered language, then the browser\'s (L10N-01)', () => {
    setLocale('fr')
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('fr')
    expect(document.documentElement.lang).toBe('fr')
    expect(initialLocale('en-GB')).toBe('fr')
    window.localStorage.removeItem(LOCALE_STORAGE_KEY)
    expect(initialLocale('fr-CD')).toBe('fr')
    expect(initialLocale('sw-KE')).toBe('en')
  })
})
