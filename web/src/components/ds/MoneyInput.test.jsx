import { act, fireEvent, render, screen } from '@testing-library/react'
import i18n from '@/i18n'
import { MoneyInput, PercentInput, RateInput } from './MoneyInput'

function type(input, text) {
  fireEvent.change(input, { target: { value: text } })
}

describe('MoneyInput', () => {
  afterEach(async () => {
    await act(() => i18n.changeLanguage('en'))
  })

  it('shows the currency code first and emits minor units as a string', () => {
    const onChange = vi.fn()
    render(<MoneyInput label="Amount" currency="USD" value="" onChange={onChange} />)
    expect(screen.getByText('USD')).toBeInTheDocument()
    const input = screen.getByLabelText('Amount')
    type(input, '12,450.50')
    expect(onChange).toHaveBeenLastCalledWith('1245050')
    // Tidied into the language's format once the field is left.
    fireEvent.blur(input)
    expect(input).toHaveValue('12,450.50')
  })

  it('reads French input with spaces and a decimal comma', async () => {
    await act(() => i18n.changeLanguage('fr'))
    const onChange = vi.fn()
    render(<MoneyInput label="Montant" currency="KES" value="" onChange={onChange} />)
    type(screen.getByLabelText('Montant'), '12 450,50')
    expect(onChange).toHaveBeenLastCalledWith('1245050')
  })

  it('refuses decimals in CDF and says so once the field is left', () => {
    const onChange = vi.fn()
    render(<MoneyInput label="Amount" currency="CDF" value="" onChange={onChange} />)
    const input = screen.getByLabelText('Amount')
    type(input, '135,000')
    expect(onChange).toHaveBeenLastCalledWith('135000')
    type(input, '135000.5')
    expect(onChange).toHaveBeenLastCalledWith(null)
    expect(screen.queryByText(/no decimals/)).not.toBeInTheDocument()
    fireEvent.blur(input)
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText('This currency has no decimals. Enter a whole number.')).toBeInTheDocument()
  })

  it('starts from minor units and reports an empty field as ""', () => {
    const onChange = vi.fn()
    render(<MoneyInput label="Amount" currency="KES" value="5000" onChange={onChange} />)
    const input = screen.getByLabelText('Amount')
    expect(input).toHaveValue('50.00')
    type(input, '')
    expect(onChange).toHaveBeenLastCalledWith('')
  })

  it('names the format when the text is not a number', () => {
    render(<MoneyInput label="Amount" currency="USD" value="" onChange={() => {}} />)
    const input = screen.getByLabelText('Amount')
    type(input, '12,45')
    fireEvent.blur(input)
    expect(screen.getByText('Enter a number such as 12,450.50.')).toBeInTheDocument()
  })
})

describe('RateInput', () => {
  it('takes up to 8 decimals and emits a decimal string', () => {
    const onChange = vi.fn()
    render(<RateInput label="Rate" value="" onChange={onChange} />)
    const input = screen.getByLabelText('Rate')
    type(input, '2,850.12345678')
    expect(onChange).toHaveBeenLastCalledWith('2850.12345678')
    type(input, '0.123456789')
    expect(onChange).toHaveBeenLastCalledWith(null)
    type(input, '0')
    expect(onChange).toHaveBeenLastCalledWith(null)
    fireEvent.blur(input)
    expect(screen.getByText('Enter a number above zero.')).toBeInTheDocument()
  })
})

describe('PercentInput', () => {
  it('takes 0 to 100 with up to 4 decimals', () => {
    const onChange = vi.fn()
    render(<PercentInput label="Rate" value="" onChange={onChange} />)
    const input = screen.getByLabelText('Rate')
    expect(screen.getByText('%')).toBeInTheDocument()
    type(input, '16')
    expect(onChange).toHaveBeenLastCalledWith('16')
    type(input, '12.5555')
    expect(onChange).toHaveBeenLastCalledWith('12.5555')
    type(input, '12.55555')
    expect(onChange).toHaveBeenLastCalledWith(null)
    type(input, '100.5')
    expect(onChange).toHaveBeenLastCalledWith(null)
    fireEvent.blur(input)
    expect(screen.getByText('Enter a number up to 100.')).toBeInTheDocument()
  })
})
