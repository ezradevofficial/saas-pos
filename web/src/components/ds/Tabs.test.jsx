import { fireEvent, render, screen } from '@testing-library/react'
import { Tabs } from './Tabs'

const items = [
  { value: 'waiting', label: 'Waiting', count: 4 },
  { value: 'done', label: 'Done' },
]

describe('Tabs', () => {
  it('renders a tablist with the active tab selected and counts', () => {
    render(<Tabs items={items} value="waiting" />)
    expect(screen.getByRole('tablist')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Waiting/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: 'Done' })).toHaveAttribute('aria-selected', 'false')
    expect(screen.getByText('4')).toHaveClass('text-ink-muted')
  })

  it('reports the chosen tab', () => {
    const onChange = vi.fn()
    render(<Tabs items={items} value="waiting" onChange={onChange} />)
    const done = screen.getByRole('tab', { name: 'Done' })
    fireEvent.mouseDown(done, { button: 0, ctrlKey: false })
    expect(onChange).toHaveBeenCalledWith('done')
  })
})
