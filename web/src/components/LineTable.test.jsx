import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { linesBody, lineTotals, newLine, orderedLineFields } from '@/lib/customForms'
import { LineTable } from './LineTable'

const base = { help: null, default: null, required: false, unique: false, min: null, max: null, pattern: null, options: [], lookup_target: null, formula_type: null, readonly: false }
const FIELDS = [
  { ...base, key: 'description', type: 'text', label: 'Description' },
  { ...base, key: 'quantity', type: 'number', label: 'Quantity' },
  { ...base, key: 'amount', type: 'money', label: 'Amount' },
]
const line = (description, quantity, minor, currency = 'KES') => ({ ...newLine(FIELDS), values: { description, quantity, amount: { amount_minor: minor, currency } } })

function Harness({ initial = [] }) {
  const [lines, setLines] = useState(initial)
  return <LineTable entity="custom_form_line:petty_cash" fields={FIELDS} lines={lines} onChange={setLines} />
}

describe('Line totals (CF-05)', () => {
  it('sums numbers as decimals and money in minor units, never as floats', () => {
    const totals = lineTotals(FIELDS, [line('Fuel', '0.1', '250000'), line('Water', '0.2', '30050'), line('Tea', '', '')])
    expect(totals.quantity).toBe('0.3')
    expect(totals.amount).toEqual({ amount_minor: '280050', currency: 'KES' })
    expect(lineTotals(FIELDS, [line('A', '1', '100', 'KES'), line('B', '1', '100', 'USD')]).amount).toBeNull()
    expect(lineTotals(FIELDS, [line('A', '-1.25', '1'), line('B', '1', '1')]).quantity).toBe('-0.25')
  })

  it('sends only the values a line has, in the API’s shapes', () => {
    expect(linesBody(FIELDS, [line('Fuel', '', '')])).toEqual([{ custom: { description: 'Fuel' } }])
    expect(linesBody(FIELDS, [line('Fuel', '2', '500')])).toEqual([{ custom: { description: 'Fuel', quantity: '2', amount: { amount_minor: '500', currency: 'KES' } } }])
  })

  it('orders the columns as the type names them', () => {
    expect(orderedLineFields(FIELDS, ['amount', 'description']).map((field) => field.key)).toEqual(['amount', 'description', 'quantity'])
  })
})

describe('LineTable (CF-05)', () => {
  it('adds and removes lines and shows the totals', () => {
    render(<Harness initial={[line('Fuel', '1.5', '250000')]} />)
    expect(document.querySelector('[data-total="amount"]')).toHaveTextContent('2,500.00')
    fireEvent.click(screen.getByRole('button', { name: 'Add line' }))
    fireEvent.change(screen.getByLabelText('Quantity, line 2'), { target: { value: '2' } })
    expect(document.querySelector('[data-total="quantity"]')).toHaveTextContent('3.5')
    fireEvent.click(screen.getByRole('button', { name: 'Remove line 1' }))
    expect(document.querySelector('[data-total="quantity"]')).toHaveTextContent('2')
    expect(screen.queryByLabelText('Description, line 2')).not.toBeInTheDocument()
  })
})
