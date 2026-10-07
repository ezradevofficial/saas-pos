import { render, screen } from '@testing-library/react'
import { Alert } from './Alert'
import { Button } from './Button'

describe('Alert', () => {
  it('is a status by default with a title and text', () => {
    render(<Alert title="Sync paused">Sales upload when you are back online.</Alert>)
    const alert = screen.getByRole('status')
    expect(alert).toHaveTextContent('Sync paused')
    expect(alert).toHaveTextContent('Sales upload when you are back online.')
  })

  it('uses role alert and the danger tint for danger', () => {
    render(<Alert tone="danger" title="Payment failed" />)
    expect(screen.getByRole('alert')).toHaveClass('bg-danger-tint', 'text-ink')
  })

  it('renders one action', () => {
    render(<Alert tone="warning" title="Plan limit" action={<Button>Upgrade plan</Button>} />)
    expect(screen.getByRole('button', { name: 'Upgrade plan' })).toBeInTheDocument()
  })
})
