import { render, screen } from '@testing-library/react'
import { Button } from './Button'
import { Card } from './Card'

describe('Card', () => {
  it('renders a titled card with a hairline border and no shadow', () => {
    const { container } = render(<Card title="Sales by branch" subtitle="Today">Body</Card>)
    expect(screen.getByRole('heading', { level: 3, name: 'Sales by branch' })).toBeInTheDocument()
    expect(screen.getByText('Today')).toHaveClass('text-ink-muted')
    const card = container.firstChild
    expect(card).toHaveAttribute('data-slot', 'card')
    expect(card).toHaveClass('border', 'border-border', 'rounded-lg', 'bg-surface-200')
    expect(card.className).not.toMatch(/shadow-(sm|lg)/)
    expect(screen.getByText('Body')).toBeInTheDocument()
  })

  it('renders actions', () => {
    render(<Card title="Users" actions={<Button variant="ghost">Export</Button>} />)
    expect(screen.getByRole('button', { name: 'Export' })).toBeInTheDocument()
  })

  it('has no header without title and actions', () => {
    const { container } = render(<Card>Only body</Card>)
    expect(container.querySelector('header')).toBeNull()
  })
})
