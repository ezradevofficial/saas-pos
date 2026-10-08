import i18n from '@/i18n'
import { actionLabel, changedFields, contextFields, formatHistoryValue } from './history'

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
  it('says which price changed: list, unit, start and quantity listed with the amount, money as one value (MD-03)', () => {
    const snapshot = { price_list_id: 'pl-1', item_id: 'i-1', uom_id: 'u-ea', effective_from: '2026-10-08', min_quantity: '1', currency: 'CDF' }
    const entry = { action: 'core.item_price.update', before: { ...snapshot, amount_minor: '1500' }, after: { ...snapshot, amount_minor: '1750' } }
    expect(actionLabel(t, entry.action, entry)).toBe('Price changed')
    expect(changedFields(entry.before, entry.after, contextFields(entry.action))).toEqual(['price_list_id', 'item_id', 'uom_id', 'effective_from', 'min_quantity', 'amount_minor'])
    expect(changedFields(null, { ...snapshot, amount_minor: '1500' }, contextFields('core.item_price.create'))).not.toContain('currency')
    expect(formatHistoryValue('amount_minor', '1750', entry.after, { t, locale: 'en' })).toBe('CDF 1,750')
    expect(formatHistoryValue('min_quantity', '2.5', entry.after, { t, locale: 'fr' })).toBe('2,5')
    expect(contextFields('core.item.update')).toEqual([])
  })
})
