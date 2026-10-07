import { fireEvent, render, screen, within } from '@testing-library/react'
import { setLocale } from '@/i18n'
import { DataTable } from './DataTable'

const columns = [
  { key: 'number', label: 'Number' },
  { key: 'total', label: 'Total', align: 'end', numeric: true },
]

describe('DataTable', () => {
  afterEach(() => setLocale('en'))

  it('renders headers and rows, right-aligning numeric end columns', () => {
    render(<DataTable caption="Purchase orders" columns={columns} rows={[{ id: 'a', number: 'PO-1', total: '10.00' }]} />)
    const table = screen.getByRole('table', { name: 'Purchase orders' })
    expect(within(table).getByRole('columnheader', { name: 'Total' })).toHaveClass('text-right')
    const cell = within(table).getByRole('cell', { name: '10.00' })
    expect(cell).toHaveClass('text-right', 'tabular-nums')
  })

  it('shows the translated empty text', () => {
    render(<DataTable columns={columns} rows={[]} />)
    expect(screen.getByText('Nothing here yet.')).toBeInTheDocument()
  })

  it('shows the empty text in French', () => {
    setLocale('fr')
    render(<DataTable columns={columns} rows={[]} />)
    expect(screen.getByText('Rien pour l’instant.')).toBeInTheDocument()
  })

  it('prefers a given empty text', () => {
    render(<DataTable columns={columns} rows={[]} emptyText="No purchase orders yet." />)
    expect(screen.getByText('No purchase orders yet.')).toBeInTheDocument()
  })

  it('uses render, marks the selected row and reports row clicks', () => {
    const onRowClick = vi.fn()
    const rows = [{ id: 'a', number: 'PO-1', total: '1' }, { id: 'b', number: 'PO-2', total: '2' }]
    render(
      <DataTable
        columns={[{ key: 'number', label: 'Number', render: (row) => <strong>{row.number}</strong> }]}
        rows={rows}
        selectedId="b"
        onRowClick={onRowClick}
      />,
    )
    const selected = screen.getByText('PO-2').closest('tr')
    expect(selected).toHaveAttribute('aria-selected', 'true')
    expect(selected).toHaveClass('data-[state=selected]:bg-primary-tint')
    fireEvent.click(screen.getByText('PO-1'))
    expect(onRowClick).toHaveBeenCalledWith(rows[0])
    fireEvent.keyDown(selected, { key: 'Enter' })
    expect(onRowClick).toHaveBeenCalledWith(rows[1])
  })
})
