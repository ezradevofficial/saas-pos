import { fireEvent, render, screen } from '@testing-library/react'
import { Button } from './Button'

describe('Button', () => {
  it('renders a type="button" secondary button by default', () => {
    render(<Button>Save changes</Button>)
    const button = screen.getByRole('button', { name: 'Save changes' })
    expect(button).toHaveAttribute('type', 'button')
    expect(button).toHaveClass('bg-surface-200', 'border-border-strong', 'text-ink', 'h-control', 'rounded-md', 'text-label')
  })

  it('fills the pay variant with accent', () => {
    render(<Button variant="pay">Charge</Button>)
    const button = screen.getByRole('button')
    expect(button).toHaveClass('bg-accent', 'text-on-accent', 'hover:bg-accent-hover')
    expect(button).not.toHaveClass('bg-primary')
  })

  it('fills the primary variant with primary', () => {
    render(<Button variant="primary">Approve</Button>)
    expect(screen.getByRole('button')).toHaveClass('bg-primary', 'text-on-primary', 'hover:bg-primary-hover')
  })

  it('outlines danger and fills it only on hover', () => {
    render(<Button variant="danger">Void sale</Button>)
    const button = screen.getByRole('button')
    expect(button).toHaveClass('text-danger', 'border-border-strong', 'hover:bg-danger')
    expect(button).not.toHaveClass('bg-danger')
  })

  it('is 48px tall at size lg and full width when block', () => {
    render(<Button size="lg" block>Charge</Button>)
    expect(screen.getByRole('button')).toHaveClass('h-12', 'w-full')
  })

  it('shows a focus outline with offset', () => {
    render(<Button>Save</Button>)
    expect(screen.getByRole('button')).toHaveClass('focus-visible:outline-2', 'focus-visible:outline-offset-2', 'focus-visible:outline-focus')
  })

  it('renders an icon hidden from assistive technology', () => {
    const { container } = render(<Button icon="check">Approve</Button>)
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
  })

  it('is busy and disabled while loading', () => {
    const onClick = vi.fn()
    render(<Button loading onClick={onClick}>Save</Button>)
    const button = screen.getByRole('button')
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('aria-busy', 'true')
    fireEvent.click(button)
    expect(onClick).not.toHaveBeenCalled()
  })

  it('calls onClick', () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>Save</Button>)
    fireEvent.click(screen.getByRole('button'))
    expect(onClick).toHaveBeenCalledTimes(1)
  })
})
