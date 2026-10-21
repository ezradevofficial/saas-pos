import i18n from '@/i18n'
import { readFragment, ssoReason } from './sso'

// AUTH-11, ADR 023: failure codes from the callback read as sentences.
describe('ssoReason', () => {
  const t = (key) => i18n.t(key)

  it('reads a spent or expired ticket as such, in English and French', async () => {
    await i18n.changeLanguage('en')
    expect(ssoReason(t, 'ticket_invalid')).toBe('This sign-in has expired or was already used. Start again.')
    await i18n.changeLanguage('fr')
    expect(ssoReason(t, 'ticket_invalid')).toBe('Cette connexion a expiré ou a déjà été utilisée. Recommencez.')
    await i18n.changeLanguage('en')
  })

  it('reads the review fixes’ codes and falls back for unknown ones', () => {
    expect(ssoReason(t, 'owner_link_required')).toMatch(/Owner/)
    expect(ssoReason(t, 'name_id_transient')).toMatch(/identity provider/)
    expect(ssoReason(t, 'made_up')).toBe(ssoReason(t, 'idp_error'))
  })

  it('reads the fragment', () => {
    expect(readFragment('#sso_link=failed&reason=link_email_mismatch')).toMatchObject({ error: null, ticket: null })
    expect(readFragment('#ticket=abc')).toMatchObject({ ticket: 'abc' })
  })
})
