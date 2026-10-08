import { fireEvent, render, screen } from '@testing-library/react'
import { Select } from './Select'

describe('Select', () => {
  it('renders a labelled native select with string and object options', () => {
    render(<Select label="Currency" options={['KES', { value: 'CDF', label: 'Congolese franc' }]} defaultValue="KES" />)
    const select = screen.getByLabelText('Currency')
    expect(select.tagName).toBe('SELECT')
    expect(screen.getByRole('option', { name: 'Congolese franc' })).toHaveValue('CDF')
  })

  it('shows a disabled placeholder option', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands']} defaultValue="" />)
    expect(screen.getByRole('option', { name: 'Choose a branch' })).toBeDisabled()
  })

  it('reports changes', () => {
    const onChange = vi.fn()
    render(<Select label="Currency" options={['KES', 'USD']} value="KES" onChange={onChange} />)
    fireEvent.change(screen.getByLabelText('Currency'), { target: { value: 'USD' } })
    expect(onChange).toHaveBeenCalled()
  })

  it('shows an error', () => {
    render(<Select label="Currency" options={['KES']} error="Choose a currency" />)
    expect(screen.getByLabelText('Currency')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Currency')).toHaveAccessibleDescription('Choose a currency')
  })
})

describe('Select placeholder', () => {
  it('starts on the placeholder when no value is given', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands', 'Gombe']} />)
    expect(screen.getByLabelText('Branch')).toHaveValue('')
  })

  it('keeps a given defaultValue', () => {
    render(<Select label="Branch" placeholder="Choose a branch" options={['Westlands', 'Gombe']} defaultValue="Gombe" />)
    expect(screen.getByLabelText('Branch')).toHaveValue('Gombe')
  })

  it('offers a disabled option without greying the whole control', () => {
    render(<Select label="Parent" options={[{ value: 'a', label: 'Old (archived)', disabled: true }, { value: 'b', label: 'New' }]} value="a" onChange={() => {}} />)
    expect(screen.getByRole('option', { name: 'Old (archived)' })).toBeDisabled()
    const wrapper = screen.getByLabelText('Parent').parentElement
    expect(wrapper).toHaveClass('has-disabled:bg-surface-200')
    expect(wrapper).not.toHaveClass('has-disabled:bg-surface-300')
  })
})
