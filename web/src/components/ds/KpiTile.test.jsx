import { render, screen } from '@testing-library/react'
import { KpiTile } from './KpiTile'

describe('KpiTile', () => {
  it('shows label, value and a positive delta as good', () => {
    const { container } = render(<KpiTile label="Sales today" value="KES 84,200" delta={12} />)
    expect(screen.getByText('Sales today')).toBeInTheDocument()
    expect(screen.getByText('KES 84,200')).toBeInTheDocument()
    const delta = container.querySelector('[data-slot="kpi-delta"]')
    expect(delta).toHaveClass('text-success')
    expect(delta).toHaveTextContent('Up')
    expect(delta).toHaveTextContent('12%')
    expect(delta).toHaveTextContent('vs last week')
  })

  it('shows a rise as bad when lower is better', () => {
    const { container } = render(<KpiTile label="Stock-outs" value="7" delta={4} lowerIsBetter period="vs yesterday" />)
    const delta = container.querySelector('[data-slot="kpi-delta"]')
    expect(delta).toHaveClass('text-danger')
    expect(delta).toHaveTextContent('vs yesterday')
  })

  it('shows a fall as down', () => {
    const { container } = render(<KpiTile label="Sales" value="1" delta={-3.5} />)
    expect(container.querySelector('[data-slot="kpi-delta"]')).toHaveTextContent('Down')
    expect(container.querySelector('[data-slot="kpi-delta"]')).toHaveTextContent('3.5%')
  })

  it('has no delta when none is given', () => {
    const { container } = render(<KpiTile label="Sales" value="1" />)
    expect(container.querySelector('[data-slot="kpi-delta"]')).toBeNull()
  })
})
