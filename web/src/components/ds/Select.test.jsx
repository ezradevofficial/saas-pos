import { render, screen, within } from '@testing-library/react'
import { chooseOption, openCombobox } from '@/test/combobox'
import { Select } from './Select'

describe('Select', () => {
  it('renders a labelled searchable combobox with string and object options', () => {
    render(<Select label="Currency" options={['KES', { value: 'CDF', label: 'Congolese franc' }]} defaultValue="KES" />)
    const select = screen.getByLabelText('Currency')
    expect(select).toHaveAttribute('role', 'combobox')
    expect(screen.getByRole('combobox', { name: 'Currency' })).toHaveTextContent('KES')
    expect(document.querySelector('select')).toBeNull()
    const list = openCombobox('Currency')
    expect(within(list).getByRole('option', { name: 'Congolese franc' })).toBeInTheDocument()
  })

  it('shows the placeholder when there is no value', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands']} defaultValue="" />)
    expect(screen.getByLabelText('Branch')).toHaveTextContent('Choose a branch')
    expect(screen.getByLabelText('Branch')).toHaveValue('')
  })

  it('reports changes as an event-like object with value and name', () => {
    const onChange = vi.fn()
    render(<Select label="Currency" name="currency" options={['KES', 'USD']} value="KES" onChange={onChange} />)
    chooseOption('Currency', 'USD')
    expect(onChange).toHaveBeenCalledTimes(1)
    const event = onChange.mock.calls[0][0]
    expect(event.target).toEqual({ value: 'USD', name: 'currency' })
    expect(event.currentTarget).toEqual({ value: 'USD', name: 'currency' })
  })

  it('does not report choosing the current value again', () => {
    const onChange = vi.fn()
    render(<Select label="Currency" options={['KES', 'USD']} value="KES" onChange={onChange} />)
    chooseOption('Currency', 'KES')
    expect(onChange).not.toHaveBeenCalled()
  })

  it('keeps its own value when uncontrolled', () => {
    render(<Select label="Currency" options={['KES', 'USD']} defaultValue="KES" />)
    chooseOption('Currency', 'USD')
    expect(screen.getByLabelText('Currency')).toHaveValue('USD')
  })

  it('carries the value in a hidden input when named', () => {
    const { container } = render(<Select label="Currency" name="currency" options={['KES', 'USD']} defaultValue="USD" />)
    expect(container.querySelector('input[type="hidden"][name="currency"]')).toHaveValue('USD')
  })

  it('shows an error', () => {
    render(<Select label="Currency" options={['KES']} error="Choose a currency" />)
    expect(screen.getByLabelText('Currency')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Currency')).toHaveAccessibleDescription('Choose a currency')
  })

  it('marks a required picker', () => {
    render(<Select label="Currency" options={['KES']} required />)
    expect(screen.getByRole('combobox', { name: /Currency/ })).toHaveAttribute('aria-required', 'true')
  })
})

describe('Select placeholder', () => {
  it('starts on the placeholder when no value is given', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands', 'Gombe']} />)
    expect(screen.getByLabelText('Branch')).toHaveValue('')
  })

  it('starts on the first option without a placeholder, like a native select', () => {
    render(<Select label="Branch" options={['Westlands', 'Gombe']} />)
    expect(screen.getByLabelText('Branch')).toHaveValue('Westlands')
  })

  it('keeps a given defaultValue', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands', 'Gombe']} defaultValue="Gombe" />)
    expect(screen.getByLabelText('Branch')).toHaveValue('Gombe')
  })

  it('shows an option whose value is empty instead of the placeholder', () => {
    render(<Select label="Parent" placeholder="Choose" options={[{ value: '', label: 'No parent' }, 'A']} value="" onChange={() => {}} />)
    expect(screen.getByLabelText('Parent')).toHaveTextContent('No parent')
  })

  it('offers a disabled option without greying the whole control', () => {
    render(<Select label="Parent" options={[{ value: 'a', label: 'Old (archived)', disabled: true }, { value: 'b', label: 'New' }]} value="a" onChange={() => {}} />)
    expect(screen.getByLabelText('Parent')).not.toBeDisabled()
    expect(screen.getByLabelText('Parent')).toHaveTextContent('Old (archived)')
    const list = openCombobox('Parent')
    expect(within(list).getByRole('option', { name: 'Old (archived)' })).toHaveAttribute('aria-disabled', 'true')
  })
})
