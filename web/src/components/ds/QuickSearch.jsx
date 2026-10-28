import { Command as CommandPrimitive } from 'cmdk'
import { useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/components/ui/command'
import { matchEntries } from '@/lib/quickSearch'
import { Dialog } from './Dialog'
import { Icon } from './Icon'

const ICONS = { page: 'chevronRight', action: 'plus', help: 'help' }

function Row({ entry, onSelect }) {
  return (
    <CommandItem
      value={entry.id}
      onSelect={() => onSelect(entry)}
      className="cursor-pointer gap-2 rounded-sm px-2 py-2 text-body text-ink data-selected:bg-surface-300 data-selected:text-ink"
    >
      <Icon name={ICONS[entry.kind]} className="text-ink-muted" />
      <span className="min-w-0 flex-1 truncate">
        {entry.group ? (
          <>
            <span className="text-ink-muted">{entry.group}</span>
            {' › '}
          </>
        ) : null}
        {entry.label}
      </span>
    </CommandItem>
  )
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
  const sections = useMemo(() => {
    if (!query.trim()) return recents.length > 0 ? [{ id: 'recent', heading: t('quickSearch.recent'), items: recents }] : []
    return [
      { id: 'pages', heading: t('quickSearch.sections.pages'), items: matchEntries(pages, query, 8) },
      { id: 'actions', heading: t('quickSearch.sections.actions'), items: matchEntries(actions, query, 6) },
      { id: 'help', heading: t('quickSearch.sections.help'), items: matchEntries(help, query, 6) },
    ].filter((section) => section.items.length > 0)
  }, [query, pages, actions, help, recents, t])

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
          {searching ? <CommandEmpty className="px-3 py-4 text-body text-ink-muted">{t('quickSearch.noResults')}</CommandEmpty> : null}
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
        </CommandList>
      </Command>
    </>
  )
}
