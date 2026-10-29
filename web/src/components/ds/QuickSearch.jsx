import { useQuery } from '@tanstack/react-query'
import { Command as CommandPrimitive } from 'cmdk'
import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/components/ui/command'
import { matchEntries } from '@/lib/quickSearch'
import { useDebounced } from '@/lib/useDebounced'
import { Dialog } from './Dialog'
import { Icon } from './Icon'

const ICONS = { page: 'chevronRight', action: 'plus', help: 'help', record: 'templates' }

/** Typing this many characters also searches the tenant's records (LAY-01). */
export const RECORD_MIN_LENGTH = 2

/** The wait after the last key press before records are searched. */
export const RECORD_DEBOUNCE_MS = 250

// One literal key per record type the API can return, so the i18n check sees them.
const RECORD_TYPES = {
  customer: (t) => t('quickSearch.records.types.customer'),
  supplier: (t) => t('quickSearch.records.types.supplier'),
  item: (t) => t('quickSearch.records.types.item'),
  billing_invoice: (t) => t('quickSearch.records.types.billing_invoice'),
  sale: (t) => t('quickSearch.records.types.sale'),
  sales_invoice: (t) => t('quickSearch.records.types.sales_invoice'),
  purchase_order: (t) => t('quickSearch.records.types.purchase_order'),
  employee: (t) => t('quickSearch.records.types.employee'),
  reservation: (t) => t('quickSearch.records.types.reservation'),
  salon_client: (t) => t('quickSearch.records.types.salon_client'),
}

function Row({ entry, onSelect }) {
  return (
    <CommandItem
      value={entry.id}
      onSelect={() => onSelect(entry)}
      className="cursor-pointer gap-2 rounded-sm px-2 py-2 text-body text-ink data-selected:bg-surface-300 data-selected:text-ink"
    >
      <Icon name={ICONS[entry.kind]} className="text-ink-muted" />
      {entry.kind === 'record' ? (
        <>
          <span className="min-w-0 flex-1 truncate">
            {entry.label}
            {entry.subtitle ? <span className="text-ink-muted">{`  ${entry.subtitle}`}</span> : null}
          </span>
          <span className="shrink-0 text-caption text-ink-muted">{entry.group}</span>
        </>
      ) : (
        <span className="min-w-0 flex-1 truncate">
          {entry.group ? (
            <>
              <span className="text-ink-muted">{entry.group}</span>
              {' › '}
            </>
          ) : null}
          {entry.label}
        </span>
      )}
    </CommandItem>
  )
}

/**
 * The tenant's own records matching `text` (LAY-01): asked from the API once
 * the text has stopped changing for RECORD_DEBOUNCE_MS and has at least
 * RECORD_MIN_LENGTH characters. The API returns only what the user may see
 * (RBAC-04, RBAC-08). Returns the palette entries, and whether the search is
 * waiting or running, or failed.
 */
function useRecords(text) {
  const { t } = useTranslation()
  const settled = useDebounced(text, RECORD_DEBOUNCE_MS)
  const enabled = text.length >= RECORD_MIN_LENGTH
  const query = useQuery({
    queryKey: ['search', 'records', settled],
    queryFn: () => api.get(`search?q=${encodeURIComponent(settled)}`),
    enabled: enabled && settled.length >= RECORD_MIN_LENGTH,
    staleTime: 30_000,
    retry: false,
  })

  const entries = useMemo(() => {
    if (!enabled || settled !== text) return []
    return (query.data?.data ?? []).flatMap((group) =>
      (group.results ?? []).map((hit) => ({
        id: `record:${group.type}:${hit.id}`,
        kind: 'record',
        label: hit.title,
        subtitle: hit.subtitle ?? '',
        group: RECORD_TYPES[group.type]?.(t) ?? group.type,
        to: hit.path,
      })),
    )
  }, [enabled, settled, text, query.data, t])

  return { entries, waiting: enabled && (settled !== text || query.isFetching), failed: enabled && settled === text && query.isError }
}

/**
 * The quick search (LAY-01): a dialog with one field that finds pages,
 * actions and help topics. It only shows the entries it is given, which the
 * caller has already filtered by permission and module (RBAC-08). Built on
 * the shadcn Command (cmdk): up and down move, Enter opens, Escape closes,
 * and the list is a labelled listbox. With no text it shows `recents`.
 * Entries are { id, kind, label, group, keywords, to }.
 */
export function QuickSearch({ open, onClose, ...rest }) {
  const { t } = useTranslation()
  const inputRef = useRef(null)

  return (
    <Dialog
      open={open}
      title={t('quickSearch.title')}
      onClose={onClose}
      size="lg"
      onOpenAutoFocus={(event) => {
        event.preventDefault()
        inputRef.current?.focus()
      }}
    >
      <Search inputRef={inputRef} {...rest} />
    </Dialog>
  )
}

// Mounted only while the dialog is open, so the text starts empty each time.
function Search({ inputRef, pages = [], actions = [], help = [], recents = [], onSelect }) {
  const { t } = useTranslation()
  const [query, setQuery] = useState('')

  const searching = query.trim() !== ''
  const records = useRecords(query.trim())
  const sections = useMemo(() => {
    if (!query.trim()) return recents.length > 0 ? [{ id: 'recent', heading: t('quickSearch.recent'), items: recents }] : []
    return [
      { id: 'pages', heading: t('quickSearch.sections.pages'), items: matchEntries(pages, query, 8) },
      { id: 'actions', heading: t('quickSearch.sections.actions'), items: matchEntries(actions, query, 6) },
      { id: 'help', heading: t('quickSearch.sections.help'), items: matchEntries(help, query, 6) },
      { id: 'records', heading: t('quickSearch.records.heading'), items: records.entries },
    ].filter((section) => section.items.length > 0)
  }, [query, pages, actions, help, recents, records.entries, t])

  return (
    <>
      <Command shouldFilter={false} loop label={t('quickSearch.placeholder')} className="rounded-md! border border-border bg-surface-200 p-0 text-ink">
        <div className="flex items-center gap-2 border-b border-border px-3">
          <Icon name="search" className="text-ink-muted" />
          <CommandPrimitive.Input
            ref={inputRef}
            value={query}
            onValueChange={setQuery}
            placeholder={t('quickSearch.placeholder')}
            aria-label={t('quickSearch.placeholder')}
            className="h-control w-full min-w-0 bg-transparent text-body text-ink outline-none placeholder:text-ink-muted"
          />
        </div>
        <CommandList label={t('quickSearch.results')} className="max-h-picker">
          {searching && !records.waiting && !records.failed ? <CommandEmpty className="px-3 py-4 text-body text-ink-muted">{t('quickSearch.noResults')}</CommandEmpty> : null}
          {!searching && sections.length === 0 ? <p className="px-3 py-4 text-body text-ink-muted">{t('quickSearch.hint')}</p> : null}
          {sections.map((section) => (
            <CommandGroup
              key={section.id}
              heading={section.heading}
              className="p-1 text-ink **:[[cmdk-group-heading]]:px-2 **:[[cmdk-group-heading]]:py-2 **:[[cmdk-group-heading]]:text-caption **:[[cmdk-group-heading]]:text-ink-muted"
            >
              {section.items.map((entry) => (
                <Row key={entry.id} entry={entry} onSelect={onSelect} />
              ))}
            </CommandGroup>
          ))}
          {records.waiting ? (
            <p role="status" className="px-3 py-3 text-body text-ink-muted">
              {t('quickSearch.records.searching')}
            </p>
          ) : null}
          {records.failed ? (
            <p role="status" className="px-3 py-3 text-body text-ink-muted">
              {t('quickSearch.records.failed')}
            </p>
          ) : null}
        </CommandList>
      </Command>
    </>
  )
}
