import { fireEvent, render, screen } from '@testing-library/react'
import { PosTile } from './PosTile'

describe('PosTile', () => {
  it('is a button naming the product and price', () => {
    const onSelect = vi.fn()
    render(<PosTile name="Maize flour 2kg" price={18500} currency="KES" onSelect={onSelect} />)
    const tile = screen.getByRole('button', { name: 'Maize flour 2kg, KES 185.00' })
    expect(tile).toHaveClass('rounded-md', 'border-border', 'bg-surface-200')
    fireEvent.click(tile)
    expect(onSelect).toHaveBeenCalled()
  })

  it('shows low stock as a warning badge', () => {
    render(<PosTile name="Milk" price={6000} currency="KES" stock={2} />)
    expect(screen.getByText('2 left')).toBeInTheDocument()
  })

  it('is disabled and says so when out of stock', () => {
    render(<PosTile name="Bread" price={6500} currency="KES" stock={0} />)
    const tile = screen.getByRole('button', { name: 'Bread, KES 65.00, out of stock' })
    expect(tile).toBeDisabled()
    expect(screen.getByText('Out')).toBeInTheDocument()
  })

  it('is disabled with its reason when unavailable (e.g. a tax rate is needed)', () => {
    const onSelect = vi.fn()
    render(<PosTile name="Gin 750ml" price={250000} currency="KES" unavailable="Rate needed" onSelect={onSelect} />)
    const tile = screen.getByRole('button', { name: 'Gin 750ml, KES 2,500.00, Rate needed' })
    expect(tile).toBeDisabled()
    expect(screen.getByText('Rate needed')).toBeInTheDocument()
    fireEvent.click(tile)
    expect(onSelect).not.toHaveBeenCalled()
  })
})
