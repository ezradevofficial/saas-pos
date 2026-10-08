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
    expect(selected).toHaveAttribute('aria-current', 'true')
    expect(selected).toHaveClass('data-[state=selected]:bg-primary-tint')
    fireEvent.click(screen.getByText('PO-1'))
    expect(onRowClick).toHaveBeenCalledWith(rows[0])
    fireEvent.keyDown(selected, { key: 'Enter' })
    expect(onRowClick).toHaveBeenCalledWith(rows[1])
  })

  it('gives sortable columns a header button with aria-sort when onSort is given (EXP-01)', () => {
    const onSort = vi.fn()
    const sortable = [
      { key: 'number', label: 'Number', sortKey: 'number' },
      { key: 'total', label: 'Total', align: 'end', sortKey: 'total' },
      { key: 'note', label: 'Note' },
    ]
    const { rerender } = render(<DataTable columns={sortable} rows={[]} sort="-total" onSort={onSort} />)
    expect(screen.getByRole('columnheader', { name: 'Number' })).toHaveAttribute('aria-sort', 'none')
    expect(screen.getByRole('columnheader', { name: 'Total' })).toHaveAttribute('aria-sort', 'descending')
    expect(screen.getByRole('columnheader', { name: 'Note' })).not.toHaveAttribute('aria-sort')
    fireEvent.click(screen.getByRole('button', { name: 'Number' }))
    expect(onSort).toHaveBeenCalledWith('number')

    rerender(<DataTable columns={sortable} rows={[]} sort="number" onSort={onSort} />)
    expect(screen.getByRole('columnheader', { name: 'Number' })).toHaveAttribute('aria-sort', 'ascending')

    // Without onSort the headers stay plain text (pages not yet on ListView).
    rerender(<DataTable columns={sortable} rows={[]} sort="number" />)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Number' })).not.toHaveAttribute('aria-sort')
  })

  it('shows Loading while the first page loads and dims rows while the next one does', () => {
    const { rerender } = render(<DataTable columns={columns} rows={[]} loading emptyText="No purchase orders yet." />)
    expect(screen.getByText('Loading')).toBeInTheDocument()
    expect(screen.queryByText('No purchase orders yet.')).not.toBeInTheDocument()

    rerender(<DataTable columns={columns} rows={[{ id: 'a', number: 'PO-1', total: '1' }]} stale />)
    const body = screen.getByText('PO-1').closest('tbody')
    expect(body).toHaveClass('opacity-60')
    expect(body).toHaveAttribute('aria-busy', 'true')
  })
})
