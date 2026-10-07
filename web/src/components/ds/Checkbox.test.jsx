import { fireEvent, render, screen } from '@testing-library/react'
import { Checkbox } from './Checkbox'

describe('Checkbox', () => {
  it('renders a labelled checkbox', () => {
    render(<Checkbox label="Send receipts by SMS" help="Charges apply" />)
    expect(screen.getByRole('checkbox', { name: /Send receipts by SMS/ })).not.toBeChecked()
    expect(screen.getByText('Charges apply')).toBeInTheDocument()
  })

  it('toggles uncontrolled and reports changes', () => {
    const onChange = vi.fn()
    render(<Checkbox label="Track stock" onChange={onChange} />)
    fireEvent.click(screen.getByLabelText('Track stock'))
    expect(screen.getByRole('checkbox')).toBeChecked()
    expect(onChange).toHaveBeenCalled()
  })

  it('supports defaultChecked and disabled', () => {
    render(<Checkbox label="Track stock" defaultChecked disabled />)
    expect(screen.getByRole('checkbox')).toBeChecked()
    expect(screen.getByRole('checkbox')).toBeDisabled()
  })
})

describe('Checkbox look', () => {
  it('draws a border-strong box that fills with primary when checked', () => {
    render(<Checkbox label="Track stock" />)
    expect(screen.getByRole('checkbox')).toHaveClass('appearance-none', 'border-border-strong', 'checked:bg-primary')
  })
})
