import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { EXPORT_FORMATS, PER_PAGE_OPTIONS } from '@/lib/useServerList'
import { cn } from '@/lib/utils'
import { Alert } from './Alert'
import { Button } from './Button'
import { DataTable } from './DataTable'
import { Icon } from './Icon'
import { Select } from './Select'
import { TextField } from './TextField'

const menuClasses = 'w-max rounded-md border border-border p-1 shadow-lg ring-0'
const menuItemClasses = 'gap-2 rounded-md px-2 py-2 text-body text-ink'

/**
 * A server list (EXP-01, LAY-04): a toolbar with search, the page's filters,
 * a Columns menu and an Export menu; the table with sortable headers; and a
 * footer with the record count, rows per page and first/previous/next/last.
 * State comes from `useServerList`; `filters` are the page's own pickers.
 */
export function ListView({ list, title, searchLabel, searchPlaceholder, filters, emptyText, onRowClick, selectedId, className }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { query, rows, meta, page, lastPage } = list
  const number = (value) => formatInteger(value ?? 0, locale)

  const total = Number(meta.total ?? 0)
  const count = query.isPending
    ? ''
    : total === 0
      ? t('ds.listView.noRecords')
      : t('ds.listView.showing', { from: number(meta.from ?? 1), to: number(meta.to ?? rows.length), total: number(total) })

  return (
    <section aria-label={title} className={cn('flex min-w-0 flex-col gap-3', className)}>
      <div className="flex flex-wrap items-end gap-3">
        <TextField
          type="search"
          label={searchLabel ?? t('ds.listView.search')}
          placeholder={searchPlaceholder}
          prefix={<Icon name="search" className="text-ink-muted" />}
          value={list.search}
          onChange={(event) => list.setSearch(event.target.value)}
          autoComplete="off"
          className="w-full sm:w-auto sm:flex-1"
        />
        {filters ? <div className="flex w-full min-w-0 flex-wrap items-end gap-3 sm:w-auto sm:flex-1">{filters}</div> : null}
        <div className="flex flex-wrap gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button icon="columns">{t('ds.listView.columns')}</Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className={menuClasses}>
              <DropdownMenuLabel className="px-2 py-2 text-caption font-normal text-ink-muted">{t('ds.listView.showColumns')}</DropdownMenuLabel>
              {list.columns.map((column) => (
                <DropdownMenuCheckboxItem
                  key={column.key}
                  checked={list.isColumnVisible(column.key)}
                  disabled={!list.canToggleColumn(column.key)}
                  // The menu stays open so several columns can be switched in a row.
                  onSelect={(event) => event.preventDefault()}
                  onCheckedChange={() => list.toggleColumn(column.key)}
                  className={cn(menuItemClasses, 'pr-8')}
                >
                  {column.label}
                </DropdownMenuCheckboxItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button icon="download" loading={Boolean(list.exporting)}>
                {t('ds.listView.export')}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className={menuClasses}>
              {EXPORT_FORMATS.map((format) => (
                <DropdownMenuItem key={format} onSelect={() => list.exportTo(format)} className={menuItemClasses}>
                  {t(`ds.listView.formats.${format}`)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {query.isError ? (
        <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} />
      ) : null}

      <DataTable
        caption={title}
        columns={list.visibleColumns}
        rows={rows}
        sort={list.sort}
        onSort={list.toggleSort}
        loading={query.isPending}
        stale={query.isPlaceholderData}
        onRowClick={onRowClick}
        selectedId={selectedId}
        emptyText={emptyText}
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <p aria-live="polite" className="text-caption text-ink-muted tabular-nums">
          {count}
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <Select
            label={t('ds.listView.perPage')}
            options={PER_PAGE_OPTIONS.map((value) => ({ value: String(value), label: number(value) }))}
            value={String(list.perPage)}
            onChange={(event) => list.setPerPage(Number(event.target.value))}
            className="flex-row items-center gap-2"
          />
          <nav aria-label={t('ds.listView.pages')} className="flex items-center gap-1">
            <Button variant="ghost" icon="first" className="px-2" aria-label={t('ds.listView.first')} disabled={page <= 1} onClick={() => list.setPage(1)} />
            <Button
              variant="ghost"
              icon="chevronLeft"
              className="px-2"
              aria-label={t('ds.listView.previous')}
              disabled={page <= 1}
              onClick={() => list.setPage(page - 1)}
            />
            <span className="px-2 text-caption text-ink-muted tabular-nums">{t('ds.listView.pageOf', { page: number(page), last: number(lastPage) })}</span>
            <Button
              variant="ghost"
              icon="chevronRight"
              className="px-2"
              aria-label={t('ds.listView.next')}
              disabled={page >= lastPage}
              onClick={() => list.setPage(page + 1)}
            />
            <Button
              variant="ghost"
              icon="last"
              className="px-2"
              aria-label={t('ds.listView.last')}
              disabled={page >= lastPage}
              onClick={() => list.setPage(lastPage)}
            />
          </nav>
        </div>
      </div>
    </section>
  )
}
