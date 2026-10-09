import { Command as CommandPrimitive } from 'cmdk'
import { useId, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { normalizeOptions, normalizeSearch } from '@/lib/options'
import { cn } from '@/lib/utils'
import { controlWrapClasses, Field } from './Field'
import { Icon } from './Icon'

const itemKey = (index) => `option-${index}`

/**
 * A labelled, searchable picker of several values (owner ruling
 * 2026-10-08: every dropdown searches). `value` is an array of option
 * values; `onChange(values)` gets the new array. The popup stays open while
 * options are ticked; Escape closes it and focus returns to the trigger.
 * Values no longer offered stay listed (marked by `unknownLabel`) so they
 * can be removed.
 */
export function MultiSelect({ label, help, error, placeholder, options = [], value = [], onChange, disabled, required, id, className, unknownLabel }) {
  const { t } = useTranslation()
  const autoId = useId()
  const pickerId = id ?? autoId
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const chosen = (Array.isArray(value) ? value : []).map(String)
  const items = useMemo(() => {
    const known = normalizeOptions(options)
    const missing = chosen.filter((entry) => !known.some((option) => option.value === entry))
    return [...known, ...missing.map((entry) => ({ value: entry, label: unknownLabel ? unknownLabel(entry) : entry, disabled: false }))]
  }, [options, chosen, unknownLabel])
  const shown = useMemo(() => {
    const query = normalizeSearch(search.trim())
    return items.map((option, index) => ({ option, index })).filter(({ option }) => !query || normalizeSearch(option.label).includes(query))
  }, [items, search])
  const summary = items.filter((option) => chosen.includes(option.value)).map((option) => option.label)

  const toggle = (option) => {
    if (option.disabled) return
    onChange?.(chosen.includes(option.value) ? chosen.filter((entry) => entry !== option.value) : [...chosen, option.value])
  }
  const changeOpen = (next) => {
    if (!next) setSearch('')
    setOpen(next)
  }

  return (
    <Field id={pickerId} label={label} help={help} error={error} required={required} className={className}>
      <div className={cn(controlWrapClasses, error && 'border-danger hover:border-danger')}>
        <Popover open={open} onOpenChange={changeOpen} modal>
          <PopoverTrigger asChild>
            <button
              type="button"
              id={pickerId}
              role="combobox"
              aria-expanded={open}
              aria-required={required ? 'true' : undefined}
              aria-invalid={error ? 'true' : undefined}
              aria-describedby={error || help ? `${pickerId}-msg` : undefined}
              disabled={disabled}
              onKeyDown={(event) => {
                if (disabled || open) return
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                  event.preventDefault()
                  changeOpen(true)
                }
              }}
              className="flex h-control w-full min-w-0 flex-1 cursor-pointer items-center gap-2 bg-transparent px-3 text-left text-body text-ink outline-none disabled:cursor-not-allowed disabled:text-ink-muted"
            >
              <span className={cn('min-w-0 flex-1 truncate', !summary.length && 'text-ink-muted')}>{summary.length ? summary.join(', ') : placeholder}</span>
              <Icon name="chevron" className="text-ink-muted" />
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-picker gap-0 rounded-md border border-border bg-surface-200 p-0 text-ink shadow-lg ring-0">
            <Command shouldFilter={false} loop className="rounded-md! bg-surface-200 p-0 text-ink">
              <div className="flex items-center gap-2 border-b border-border px-3">
                <Icon name="search" className="text-ink-muted" />
                <CommandPrimitive.Input
                  value={search}
                  onValueChange={setSearch}
                  placeholder={t('ds.combobox.search')}
                  aria-label={t('ds.combobox.search')}
                  className="h-control w-full min-w-0 bg-transparent text-body text-ink outline-none placeholder:text-ink-muted"
                />
              </div>
              <CommandList className="max-h-picker" aria-multiselectable="true">
                <CommandEmpty className="px-3 py-4 text-body text-ink-muted">{t('ds.combobox.noMatches')}</CommandEmpty>
                <CommandGroup className="p-1 text-ink">
                  {shown.map(({ option, index }) => {
                    const checked = chosen.includes(option.value)
                    return (
                      <CommandItem
                        key={itemKey(index)}
                        value={itemKey(index)}
                        disabled={option.disabled}
                        data-checked={checked ? 'true' : 'false'}
                        onSelect={() => toggle(option)}
                        className="cursor-pointer rounded-sm px-2 py-2 text-body text-ink data-[disabled=true]:text-ink-muted data-[disabled=true]:opacity-100 data-selected:bg-surface-300 data-selected:text-ink"
                      >
                        <span className="min-w-0 flex-1 break-words">{option.label}</span>
                      </CommandItem>
                    )
                  })}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>
    </Field>
  )
}
