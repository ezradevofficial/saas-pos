import { useTranslation } from 'react-i18next'
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'

export function DataTable({ columns = [], rows = [], caption, emptyText, selectedId, onRowClick, className }) {
  const { t } = useTranslation()
  const clickable = typeof onRowClick === 'function'

  return (
    <div className={cn('overflow-hidden rounded-lg border border-border bg-surface-200', className)}>
      <Table className="text-body">
        {caption ? <TableCaption className="sr-only">{caption}</TableCaption> : null}
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            {columns.map((column) => (
              <TableHead
                key={column.key}
                scope="col"
                className={cn('h-auto px-4 py-2 text-caption font-normal text-ink-muted', column.align === 'end' ? 'text-right' : 'text-left')}
              >
                {column.label}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length ? (
            rows.map((row, index) => {
              const selected = selectedId != null && row.id === selectedId
              return (
                <TableRow
                  key={row.id ?? index}
                  data-state={selected ? 'selected' : undefined}
                  aria-selected={selected ? 'true' : undefined}
                  tabIndex={clickable ? 0 : undefined}
                  onClick={clickable ? () => onRowClick(row) : undefined}
                  onKeyDown={
                    clickable
                      ? (event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            onRowClick(row)
                          }
                        }
                      : undefined
                  }
                  className={cn('hover:bg-surface-100 data-[state=selected]:bg-primary-tint', clickable && 'cursor-pointer')}
                >
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      className={cn('px-4 py-3', column.align === 'end' && 'text-right', column.numeric && 'tabular-nums')}
                    >
                      {column.render ? column.render(row) : row[column.key]}
                    </TableCell>
                  ))}
                </TableRow>
              )
            })
          ) : (
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={columns.length || 1} className="p-10 text-center whitespace-normal text-ink-muted">
                {emptyText ?? t('ds.dataTable.empty')}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  )
}
