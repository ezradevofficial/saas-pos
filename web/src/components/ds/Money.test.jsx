import { render, screen } from '@testing-library/react'
import { Money } from './Money'

describe('Money', () => {
  it('renders the currency code first in ink-muted, then the figure', () => {
    const { container } = render(<Money amount={1245000} currency="KES" />)
    const money = container.firstChild
    expect(money).toHaveTextContent(/^KES 12,450\.00$/)
    const code = screen.getByText('KES')
    expect(code).toHaveClass('text-ink-muted', 'font-normal')
    expect(money).toHaveClass('tabular-nums')
  })

  it('shows CDF with no decimals', () => {
    const { container } = render(<Money amount={135000} currency="CDF" />)
    expect(container.firstChild).toHaveTextContent('CDF 135,000')
  })

  it('shows a secondary currency underneath', () => {
    render(<Money amount={4850} currency="USD" secondary={{ amount: 135000, currency: 'CDF' }} />)
    expect(screen.getByText('≈ CDF 135,000')).toBeInTheDocument()
  })

  it('uses the amount-lg style at size lg and a tone colour', () => {
    const { container } = render(<Money amount={4850} currency="USD" size="lg" tone="danger" />)
    expect(container.querySelector('[data-slot="money-main"]')).toHaveClass('text-amount-lg')
    expect(container.firstChild).toHaveClass('text-danger')
  })

  it('formats for the given locale', () => {
    const { container } = render(<Money amount={4850} currency="USD" locale="fr" />)
    expect(container.firstChild).toHaveTextContent('USD 48,50')
  })
})
