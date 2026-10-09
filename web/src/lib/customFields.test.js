import { slugify, toApiValue, toFormValue } from './customFields'

describe('custom field values (CF-01, CF-02)', () => {
  it('suggests a key from a label', () => {
    expect(slugify('Shelf life (days)')).toBe('shelf_life_days')
    expect(slugify('Durée de vie')).toBe('duree_de_vie')
    expect(slugify('2nd colour')).toBe('nd_colour')
    expect(slugify('x'.repeat(50))).toHaveLength(40)
  })

  it('writes each type in the API’s shape, null to clear', () => {
    expect(toApiValue({ type: 'text' }, '  Fragile ')).toBe('Fragile')
    expect(toApiValue({ type: 'text' }, '')).toBeNull()
    expect(toApiValue({ type: 'number' }, '1.50')).toBe('1.5')
    expect(toApiValue({ type: 'number' }, null)).toBeUndefined()
    expect(toApiValue({ type: 'money' }, { amount_minor: '5000', currency: 'KES' })).toEqual({ amount_minor: '5000', currency: 'KES' })
    expect(toApiValue({ type: 'money' }, { amount_minor: '', currency: 'KES' })).toBeNull()
    expect(toApiValue({ type: 'multi_select' }, ['a', 'b'])).toEqual(['a', 'b'])
    expect(toApiValue({ type: 'file' }, { id: 'f-1', name: 'a.pdf' })).toBe('f-1')
    expect(toApiValue({ type: 'lookup' }, { id: 'p-1', label: 'Bidco' })).toBe('p-1')
    expect(toApiValue({ type: 'datetime' }, '2026-10-09T14:05', 'Africa/Kinshasa')).toBe('2026-10-09T13:05:00.000Z')
  })

  it('reads a datetime in the company’s time zone', () => {
    expect(toFormValue({ type: 'datetime' }, '2026-10-09T13:05:00Z', 'Africa/Nairobi')).toBe('2026-10-09T16:05')
    expect(toFormValue({ type: 'boolean' }, null)).toBe(false)
    expect(toFormValue({ type: 'select' }, null)).toBe('')
  })
})
