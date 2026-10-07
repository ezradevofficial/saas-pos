import en from '../locales/en.json'
import fr from '../locales/fr.json'
import i18n, { resolveLocale, setLocale } from './index'

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

  it('returns the app name from EXPO_PUBLIC_APP_NAME for app.name', () => {
    expect(i18n.t('app.name')).toBe(process.env.EXPO_PUBLIC_APP_NAME)
  })

  it('returns the app name in French too', () => {
    setLocale('fr')
    expect(i18n.t('app.name')).toBe(process.env.EXPO_PUBLIC_APP_NAME)
  })

  it('switches language', () => {
    expect(i18n.t('common.cancel')).toBe('Cancel')
    setLocale('fr')
    expect(i18n.t('common.cancel')).toBe('Annuler')
  })

  it('resolves a locale from the first supported candidate, else en', () => {
    expect(resolveLocale('fr-CD', 'en')).toBe('fr')
    expect(resolveLocale(undefined, 'de-DE', 'fr')).toBe('fr')
    expect(resolveLocale('de', null)).toBe('en')
  })
})
