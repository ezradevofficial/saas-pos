import { render, screen } from '@testing-library/react'
import { TextField } from './TextField'

describe('TextField', () => {
  it('labels the input', () => {
    render(<TextField label="KRA PIN" />)
    expect(screen.getByLabelText('KRA PIN')).toHaveAttribute('data-slot', 'input')
  })

  it('shows a currency prefix before the input', () => {
    render(<TextField label="Amount" prefix="KES" />)
    expect(screen.getByText('KES')).toHaveClass('text-ink-muted')
  })

  it('describes the input with its help text', () => {
    render(<TextField label="Phone" help="Include the country code" />)
    expect(screen.getByLabelText('Phone')).toHaveAccessibleDescription('Include the country code')
  })

  it('marks the input invalid and shows the error instead of help', () => {
    render(<TextField label="KRA PIN" help="From your certificate" error="A KRA PIN has 11 characters" />)
    const input = screen.getByLabelText('KRA PIN')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('A KRA PIN has 11 characters')
    expect(screen.queryByText('From your certificate')).not.toBeInTheDocument()
  })

  it('passes native attributes through', () => {
    render(<TextField label="Name" required disabled defaultValue="Amina" />)
    const input = screen.getByLabelText(/Name/)
    expect(input).toBeRequired()
    expect(input).toBeDisabled()
    expect(input).toHaveValue('Amina')
  })
})
