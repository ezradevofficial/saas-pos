import { invalidLineFields, linesBody, newLine } from './customForms'

const base = { help: null, default: null, required: false, options: [], readonly: false }
const FIELDS = [
  { ...base, key: 'description', type: 'text', label: 'Description' },
  { ...base, key: 'quantity', type: 'number', label: 'Quantity' },
  { ...base, key: 'code', type: 'text', label: 'Code', readonly: true },
]

describe('Custom form lines (CF-05)', () => {
  it('keys a saved line by its id, and a new line by a key of its own', () => {
    const saved = newLine(FIELDS, { id: 'l-1', custom: { description: 'Fuel', quantity: '2' } })
    expect([saved.key, saved.id]).toEqual(['l-1', 'l-1'])
    expect(newLine(FIELDS, { id: 'l-1', custom: {} }).key).toBe('l-1')
    const fresh = newLine(FIELDS)
    expect(fresh.id).toBeNull()
    expect(fresh.key).not.toBe(newLine(FIELDS).key)
  })

  it('sends saved lines by id with every editable field, new lines with their values only', () => {
    const saved = { ...newLine(FIELDS, { id: 'l-1', custom: { code: 'C-1' } }), values: { description: 'Fuel', quantity: '', code: 'C-1' } }
    const fresh = { ...newLine(FIELDS), values: { description: 'Water', quantity: '', code: '' } }
    expect(linesBody(FIELDS, [saved, fresh])).toEqual([
      { id: 'l-1', custom: { description: 'Fuel', quantity: null } },
      { custom: { description: 'Water' } },
    ])
  })

  it('finds line values that can’t be read, so nothing typed is dropped', () => {
    const lines = [
      { ...newLine(FIELDS), values: { description: 'Fuel', quantity: '1', code: '' } },
      { ...newLine(FIELDS), values: { description: 'Water', quantity: null, code: '' } },
    ]
    expect(invalidLineFields(FIELDS, lines)).toEqual(['lines.1.custom.quantity'])
  })
})
