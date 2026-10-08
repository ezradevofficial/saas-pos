import { useTranslation } from 'react-i18next'
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

/** The direction `sort` ("name", "-name" or empty) gives a column's sort key. */
function directionOf(sort, sortKey) {
  if (!sort || !sortKey) return null
  if (sort === sortKey) return 'ascending'
  if (sort === `-${sortKey}`) return 'descending'
  return null
}

/**
 * Records in a table. Columns that carry a `sortKey` get a header button when
 * `onSort` is given (EXP-01 lists): `sort` is the current key, "-key" when
 * descending, and the header carries aria-sort. `loading` shows the loading
 * text in the body while there are no rows yet; `stale` dims the rows shown
 * while the next page loads.
 */
export function DataTable({ columns = [], rows = [], caption, emptyText, selectedId, onRowClick, sort, onSort, loading = false, stale = false, className }) {
  const { t } = useTranslation()
  const clickable = typeof onRowClick === 'function'
  const sortable = typeof onSort === 'function'

  return (
    <div className={cn('overflow-hidden rounded-lg border border-border bg-surface-200', className)}>
      <Table className="text-body">
        {caption ? <TableCaption className="sr-only">{caption}</TableCaption> : null}
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            {columns.map((column) => {
              const canSort = sortable && Boolean(column.sortKey)
              const direction = canSort ? directionOf(sort, column.sortKey) : null
              return (
                <TableHead
                  key={column.key}
                  scope="col"
                  aria-sort={canSort ? (direction ?? 'none') : undefined}
                  className={cn('h-auto px-4 py-2 text-caption font-normal text-ink-muted', column.align === 'end' ? 'text-right' : 'text-left')}
                >
                  {canSort ? (
                    <button
                      type="button"
                      onClick={() => onSort(column.sortKey)}
                      className={cn(
                        '-mx-1 inline-flex items-center gap-1 rounded-sm px-1 text-caption hover:text-ink',
                        column.align === 'end' && 'flex-row-reverse',
                        direction ? 'text-ink' : 'text-ink-muted',
                      )}
                    >
                      {column.label}
                      <Icon
                        name={direction === 'ascending' ? 'chevronUp' : direction === 'descending' ? 'chevron' : 'updown'}
                        size={14}
                        className={direction ? 'text-ink' : 'text-ink-muted'}
                      />
                    </button>
                  ) : (
                    column.label
                  )}
                </TableHead>
              )
            })}
          </TableRow>
        </TableHeader>
        <TableBody aria-busy={stale || loading ? 'true' : undefined} className={cn(stale && rows.length && 'opacity-60 transition-opacity')}>
          {rows.length ? (
            rows.map((row, index) => {
              const selected = selectedId != null && row.id === selectedId
              return (
                <TableRow
                  key={row.id ?? index}
                  data-state={selected ? 'selected' : undefined}
                  aria-current={selected ? 'true' : undefined}
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
                      className={cn('px-4 py-3', column.align === 'end' && 'text-right', column.numeric && 'tabular-nums', column.wrap && 'whitespace-normal')}
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
                {loading ? t('common.loading') : (emptyText ?? t('ds.dataTable.empty'))}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  )
}
