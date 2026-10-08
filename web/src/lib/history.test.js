import i18n from '@/i18n'
import { actionLabel, changedFields } from './history'

describe('history labels', () => {
  const t = i18n.t.bind(i18n)

  it('names the credit limit change request that changed a limit, and does not list its number as a field (WF-01, MD-07)', () => {
    const entry = {
      action: 'core.party.credit_limit_apply',
      before: { credit_limit_minor: 15000000, credit_limit_currency: 'KES' },
      after: { credit_limit_minor: 25000000, credit_limit_currency: 'KES', credit_limit_change: 'CLC-000123' },
    }
    expect(actionLabel(t, entry.action, entry)).toBe('Credit limit changed via CLC-000123')
    expect(changedFields(entry.before, entry.after)).toEqual(['credit_limit_minor'])
    expect(actionLabel(t, 'core.party.update')).toBe('Updated')
  })
})
