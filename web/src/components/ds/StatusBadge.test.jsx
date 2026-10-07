import { render, screen } from '@testing-library/react'
import { StatusBadge } from './StatusBadge'

describe('StatusBadge', () => {
  it('renders a success dot and the word, with no fill on the badge', () => {
    render(<StatusBadge tone="success">Paid</StatusBadge>)
    const badge = screen.getByText('Paid')
    const dot = badge.querySelector('[data-slot="status-dot"]')
    expect(dot).toHaveClass('bg-success', 'rounded-pill', 'size-dot')
    expect(dot).toHaveAttribute('aria-hidden', 'true')
    expect(badge).not.toHaveClass('bg-success')
    expect(badge).toHaveClass('bg-transparent')
  })

  it('defaults to the neutral tone', () => {
    render(<StatusBadge>Draft</StatusBadge>)
    expect(screen.getByText('Draft').querySelector('[data-slot="status-dot"]')).toHaveClass('bg-neutral-dot')
  })

  it.each([
    ['info', 'bg-primary'],
    ['warning', 'bg-warning'],
    ['danger', 'bg-danger'],
    ['accent', 'bg-accent-ink'],
  ])('maps %s to its dot colour', (tone, dotClass) => {
    render(<StatusBadge tone={tone}>Word</StatusBadge>)
    expect(screen.getByText('Word').querySelector('[data-slot="status-dot"]')).toHaveClass(dotClass)
  })

  it('colours the word only for danger and accent', () => {
    render(<StatusBadge tone="danger">Overdue</StatusBadge>)
    expect(screen.getByText('Overdue')).toHaveClass('text-danger')
  })
})
