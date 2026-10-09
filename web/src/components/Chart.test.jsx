// LAY-01: the SVG chart: bars and lines in token colours, values on hover
// and keys, and a table of the same figures.
import { fireEvent, render, screen, within } from '@testing-library/react'
import { barPath, niceMax } from '@/lib/chartScale'
import { Chart } from './Chart'

const POINTS = [
  { label: '2026-10-07', value: 120000 },
  { label: '2026-10-08', value: null },
  { label: '2026-10-09', value: 337500 },
]
const format = (value) => `KES ${(value / 100).toFixed(2)}`

describe('chart', () => {
  it('rounds the scale up to a readable number', () => {
    expect(niceMax(337500)).toBe(500000)
    expect(niceMax(0)).toBe(1)
    expect(niceMax(80)).toBe(100)
    expect(barPath(0, 10, 20, 0, 4)).toBe('')
  })

  it('draws a bar per figure in the primary token, and a gap for a missing one', () => {
    render(<Chart type="bar" points={POINTS} title="Sales by day" formatValue={format} />)
    const svg = screen.getByRole('img', { name: 'Sales by day' })
    const bars = svg.querySelectorAll('[data-testid="chart-bar"]')
    expect(bars).toHaveLength(2)
    for (const bar of bars) expect(bar).toHaveClass('fill-primary')
    expect(svg.innerHTML).not.toMatch(/#[0-9a-f]{3,6}/i)
  })

  it('draws a 2px line broken where a figure is missing', () => {
    render(<Chart type="line" points={[...POINTS, { label: '2026-10-10', value: 1000 }]} title="Sales" formatValue={format} />)
    const lines = document.querySelectorAll('[data-testid="chart-line"]')
    expect(lines).toHaveLength(2)
    expect(lines[0]).toHaveAttribute('stroke-width', '2')
    expect(lines[0]).toHaveClass('stroke-primary')
  })

  it('shows a value on hover and with the arrow keys', () => {
    render(<Chart type="bar" points={POINTS} title="Sales" formatValue={format} formatLabel={(label) => label.slice(5)} />)
    const svg = screen.getByRole('img', { name: 'Sales' })
    fireEvent.keyDown(svg, { key: 'ArrowRight' })
    expect(within(screen.getByTestId('chart-tooltip')).getByText('10-07: KES 1200.00')).toBeInTheDocument()
    fireEvent.keyDown(svg, { key: 'ArrowRight' })
    expect(within(screen.getByTestId('chart-tooltip')).getByText('10-08: No figure')).toBeInTheDocument()
    fireEvent.keyDown(svg, { key: 'Escape' })
    expect(screen.queryByTestId('chart-tooltip')).toBeNull()
  })

  it('always has a table of the figures, shown on request', () => {
    render(<Chart points={POINTS} title="Sales" valueLabel="Amount (KES)" formatValue={format} />)
    const table = screen.getByRole('table', { name: 'Sales' })
    expect(table).toHaveClass('sr-only')
    expect(within(table).getByRole('columnheader', { name: 'Amount (KES)' })).toBeInTheDocument()
    expect(within(table).getAllByRole('row')).toHaveLength(4)
    expect(within(table).getByText('KES 3375.00')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Show as a table' }))
    expect(table).not.toHaveClass('sr-only')
    expect(screen.getByRole('button', { name: 'Hide the table' })).toHaveAttribute('aria-expanded', 'true')
  })
})
