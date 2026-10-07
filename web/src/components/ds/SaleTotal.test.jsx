import { fireEvent, render, screen } from '@testing-library/react'
import { SaleTotal } from './SaleTotal'

describe('SaleTotal', () => {
  it('labels the pay button "Charge KES 48.50" and fills it with accent', () => {
    const onPay = vi.fn()
    render(<SaleTotal currency="KES" subtotal={4181} tax={669} total={4850} onPay={onPay} />)
    const pay = screen.getByRole('button', { name: 'Charge KES 48.50' })
    expect(pay).toHaveClass('bg-accent', 'h-12', 'w-full')
    fireEvent.click(pay)
    expect(onPay).toHaveBeenCalled()
  })

  it('disables the pay button when the total is 0', () => {
    render(<SaleTotal currency="KES" subtotal={0} tax={0} total={0} />)
    expect(screen.getByRole('button', { name: 'Charge KES 0.00' })).toBeDisabled()
  })

  it('shows subtotal, VAT, total and a discount in accent ink', () => {
    render(<SaleTotal currency="KES" subtotal={5000} discount={500} tax={720} total={5220} />)
    expect(screen.getByText('Subtotal')).toBeInTheDocument()
    expect(screen.getByText('VAT')).toBeInTheDocument()
    expect(screen.getByText('Total')).toBeInTheDocument()
    expect(screen.getByText('Discount').parentElement).toHaveClass('text-accent-ink')
  })

  it('shows the second currency', () => {
    render(<SaleTotal currency="USD" subtotal={4850} tax={0} total={4850} secondary={{ amount: 135000, currency: 'CDF' }} />)
    expect(screen.getByText('≈ CDF 135,000')).toBeInTheDocument()
  })

  it('accepts label overrides', () => {
    render(<SaleTotal currency="KES" subtotal={100} tax={0} total={100} labels={{ pay: 'Take payment' }} />)
    expect(screen.getByRole('button', { name: 'Take payment KES 1.00' })).toBeInTheDocument()
  })
})
