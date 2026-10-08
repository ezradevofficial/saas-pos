import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Sheet, SheetClose, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { formatInteger } from '@/lib/format'
import { normalizeOptions } from '@/lib/options'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { Button } from './Button'
import { Icon } from './Icon'
import { Select } from './Select'

/**
 * A list's filters declared as data (EXP-01, LAY-04), so the drawer can draw
 * them and the chips can name them:
 *
 *   { name: 'type', label: 'Type', options: [{ value: '', label: 'All types' }, …] }
 *   { name: 'tag', label: 'Tag', render: ({ value, onChange, label }) => <TagField … />, valueLabel: (value) => value }
 *
 * `name` is a filter of useServerList; a field is active when its value
 * differs from that filter's default. `valueLabel(value)` words the chip;
 * without it the matching option's label is used.
 */

const defaultOf = (list, name) => list.filterDefaults?.[name] ?? ''

function valueText(field, value) {
  if (field.valueLabel) return field.valueLabel(value) ?? value
  return normalizeOptions(field.options ?? []).find((option) => option.value === value)?.label ?? value
}

/** The declared fields whose value differs from their default, with the chip's words. */
function activeFilters(list, fields = []) {
  return fields.flatMap((field) => {
    const value = list.filters?.[field.name] ?? ''
    return value === defaultOf(list, field.name) ? [] : [{ field, value, text: valueText(field, value) }]
  })
}

/** Puts every declared field back to its default in one URL write. */
function clearAll(list, fields) {
  list.setFilters(Object.fromEntries(fields.map((field) => [field.name, defaultOf(list, field.name)])))
}

function FilterField({ list, field }) {
  const value = list.filters?.[field.name] ?? ''
  const onChange = (next) => list.setFilter(field.name, next)
  if (field.render) return field.render({ value, onChange, label: field.label })
  return <Select label={field.label} options={field.options ?? []} value={value} onChange={(event) => onChange(event.target.value)} className="w-full" />
}

/**
 * The Filters button and the drawer it opens from the right (shadcn Sheet,
 * a Radix dialog: it traps focus, Escape closes it and focus returns to the
 * button). Filters apply as they change; "Show results" only closes.
 * `children` are extra controls below the declared fields (a page's own
 * filters, a sort picker).
 */
export function FilterDrawer({ list, fields = [], children, triggerRef }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [open, setOpen] = useState(false)
  const count = activeFilters(list, fields).length
  const known = list.query && !list.query.isPending && !list.query.isPlaceholderData && list.meta?.total != null
  const total = Number(list.meta?.total ?? 0)

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button
          ref={triggerRef}
          icon="filter"
          aria-label={count ? t('ds.listView.filtersActive', { count, formatted: formatInteger(count, locale) }) : undefined}
        >
          {t('ds.listView.filters')}
          {count ? (
            <span aria-hidden="true" className="rounded-pill bg-surface-300 px-2 text-caption text-ink tabular-nums">
              {formatInteger(count, locale)}
            </span>
          ) : null}
        </Button>
      </SheetTrigger>
      <SheetContent
        side="right"
        showCloseButton={false}
        aria-describedby={undefined}
        className={cn(
          'gap-0 border-border bg-surface-200 p-0 text-body text-ink shadow-lg',
          'data-[side=right]:w-full data-[side=right]:sm:w-drawer data-[side=right]:sm:max-w-none',
        )}
      >
        <header className="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
          <SheetTitle className="text-h2 text-ink">{t('ds.listView.filters')}</SheetTitle>
          <SheetClose asChild>
            <Button variant="ghost" aria-label={t('ds.dialog.close')} className="size-icon-btn shrink-0 px-0">
              <Icon name="x" size={18} />
            </Button>
          </SheetClose>
        </header>
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-5">
          {fields.map((field) => (
            <FilterField key={field.name} list={list} field={field} />
          ))}
          {children}
        </div>
        <footer className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3">
          <Button variant="ghost" disabled={count === 0} onClick={() => clearAll(list, fields)}>
            {t('ds.listView.clearFilters')}
          </Button>
          <SheetClose asChild>
            <Button variant="primary">
              {known ? t('ds.listView.showCount', { count: total, formatted: formatInteger(total, locale) }) : t('ds.listView.showResults')}
            </Button>
          </SheetClose>
        </footer>
      </SheetContent>
    </Sheet>
  )
}

/**
 * The active filters under the toolbar as removable chips, so the table
 * never hides what is filtering it, and "Clear all" at the end. Focus moves
 * to `focusRef` (the Filters button) when the chip that had it goes away.
 */
export function FilterChips({ list, fields = [], focusRef }) {
  const { t } = useTranslation()
  const active = activeFilters(list, fields)
  if (!active.length) return null

  const after = (action) => () => {
    action()
    focusRef?.current?.focus()
  }

  return (
    <ul aria-label={t('ds.listView.activeFilters')} className="flex flex-wrap items-center gap-2">
      {active.map(({ field, text }) => {
        const words = t('ds.listView.chip', { label: field.label, value: text })
        return (
          <li key={field.name} className="flex items-center gap-1 rounded-md border border-border bg-surface-200 py-1 pr-1 pl-2 text-caption text-ink">
            <span>{words}</span>
            <button
              type="button"
              aria-label={t('ds.listView.removeFilter', { filter: words })}
              onClick={after(() => list.setFilter(field.name, defaultOf(list, field.name)))}
              className="flex items-center rounded-md p-1 text-ink-muted hover:bg-surface-300 hover:text-ink"
            >
              <Icon name="x" size={14} />
            </button>
          </li>
        )
      })}
      <li>
        <button
          type="button"
          onClick={after(() => clearAll(list, fields))}
          className="rounded-md px-1 text-caption text-ink-muted underline hover:text-ink"
        >
          {t('ds.listView.clearAll')}
        </button>
      </li>
    </ul>
  )
}
