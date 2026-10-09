// LAY-01: the dashboard grid: placement, drag and resize (pointer and keyboard), no overlaps.
import { fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { cellsOf, clampWidget, nextFreeRow, nudge, overlaps, placement, placeWidget } from '@/lib/dashboardGrid'
import { DashboardGrid } from './DashboardGrid'

const WIDGETS = [
  { id: 'a', label: 'Sales', x: 0, y: 0, w: 4, h: 2 },
  { id: 'b', label: 'Approvals', x: 4, y: 0, w: 4, h: 2 },
  { id: 'c', label: 'Shortcuts', x: 0, y: 2, w: 6, h: 2 },
]

const noOverlaps = (widgets) => widgets.every((one, i) => widgets.every((two, j) => i === j || !overlaps(one, two)))

function Editable({ onChange }) {
  const [widgets, setWidgets] = useState(WIDGETS)
  return (
    <DashboardGrid
      editing
      label="Dashboard"
      widgets={widgets}
      onChange={(next) => {
        setWidgets(next)
        onChange?.(next)
      }}
      renderWidget={(widget) => <span>{widget.label} body</span>}
    />
  )
}

const grid = (id) => document.querySelector(`[data-widget-id="${id}"]`).dataset.grid

describe('dashboard grid', () => {
  it('keeps widgets on the 12 columns, 48 rows and 8 rows high', () => {
    expect(clampWidget({ x: 10, y: -2, w: 5, h: 12 })).toEqual({ x: 7, y: 0, w: 5, h: 8 })
    expect(clampWidget({ x: 0, y: 60, w: 0, h: 1 })).toEqual({ x: 0, y: 47, w: 1, h: 1 })
    expect(placement({ x: 2, y: 3, w: 4, h: 2 })).toBe('md:col-start-3 md:col-span-4 md:row-start-4 md:row-span-2 row-span-2')
  })

  it('pushes covered widgets down when one moves or grows', () => {
    const moved = placeWidget(WIDGETS, 'c', { x: 2, y: 0 })
    expect(moved.find((widget) => widget.id === 'c')).toMatchObject({ x: 2, y: 0 })
    expect(moved.find((widget) => widget.id === 'a')).toMatchObject({ y: 2 })
    expect(moved.find((widget) => widget.id === 'b')).toMatchObject({ y: 2 })
    expect(noOverlaps(moved)).toBe(true)

    const grown = nudge(WIDGETS, 'a', 'resize', 0, 1)
    expect(grown.find((widget) => widget.id === 'a')).toMatchObject({ h: 3 })
    expect(grown.find((widget) => widget.id === 'c')).toMatchObject({ y: 3 })
    expect(noOverlaps(grown)).toBe(true)
    expect(nextFreeRow(grown)).toBe(5)
  })

  it('turns a pointer drag into whole cells', () => {
    expect(cellsOf(130, 60)).toBe(2)
    expect(cellsOf(-29, 60)).toBe(0)
    expect(cellsOf(-31, 60)).toBe(-1)
    expect(cellsOf(100, 0)).toBe(0)
  })

  it('moves a widget with the arrow keys and resizes it with Shift', () => {
    const onChange = vi.fn()
    render(<Editable onChange={onChange} />)
    const widget = screen.getByRole('button', { name: /^Sales\./ })
    expect(screen.getByRole('list', { name: 'Dashboard' })).toBeInTheDocument()

    fireEvent.keyDown(widget, { key: 'ArrowRight' })
    expect(grid('a')).toBe('1,0,4,2')
    // Its neighbour at columns 5-8 moved down out of the way.
    expect(grid('b')).toBe('4,2,4,2')

    fireEvent.keyDown(screen.getByRole('button', { name: /^Sales\./ }), { key: 'ArrowDown', shiftKey: true })
    expect(grid('a')).toBe('1,0,4,3')
    expect(noOverlaps(onChange.mock.calls.at(-1)[0])).toBe(true)
    // The new place is announced.
    expect(screen.getByText('Column 2, row 1, 4 columns wide, 3 rows high')).toBeInTheDocument()
  })

  it('never leaves the grid from the keyboard', () => {
    render(<Editable />)
    fireEvent.keyDown(screen.getByRole('button', { name: /^Sales\./ }), { key: 'ArrowLeft' })
    fireEvent.keyDown(screen.getByRole('button', { name: /^Sales\./ }), { key: 'ArrowUp' })
    expect(grid('a')).toBe('0,0,4,2')
  })

  it('renders widgets in reading order without edit controls when not editing', () => {
    render(<DashboardGrid widgets={[WIDGETS[2], WIDGETS[1], WIDGETS[0]]} renderWidget={(widget) => <span>{widget.label}</span>} />)
    expect([...document.querySelectorAll('[data-widget-id]')].map((node) => node.dataset.widgetId)).toEqual(['a', 'b', 'c'])
    expect(screen.queryByRole('button')).toBeNull()
  })
})
