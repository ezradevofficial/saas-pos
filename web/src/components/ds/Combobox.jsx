import { Command as CommandPrimitive } from 'cmdk'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Command, CommandEmpty, CommandGroup, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { normalizeOptions, normalizeSearch } from '@/lib/options'
import { cn } from '@/lib/utils'
import { Icon } from './Icon'

// A key that is never empty: cmdk falls back to the item's text for an empty value.
const itemKey = (index) => `option-${index}`

/**
 * A searchable picker (owner ruling 2026-10-08: every dropdown searches).
 * Renders only the trigger and its popup; ds Select adds the label, help and
 * error, and the company switcher adds its own sidebar look (BR-01).
 * Keyboard: Enter, Space or the arrow keys open it, typing filters, the arrows
 * move, Enter picks and Escape closes; focus returns to the trigger.
 */
export function Combobox({
  id,
  value,
  onValueChange,
  options = [],
  placeholder,
  disabled,
  required,
  invalid,
  name,
  className,
  iconClassName = 'text-ink-muted',
  contentClassName,
  ...rest
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const items = useMemo(() => normalizeOptions(options), [options])
  const current = value == null ? '' : String(value)
  const selectedIndex = items.findIndex((option) => option.value === current)
  const selected = selectedIndex === -1 ? null : items[selectedIndex]

  const shown = useMemo(() => {
    const query = normalizeSearch(search.trim())
    return items.map((option, index) => ({ option, index })).filter(({ option }) => !query || normalizeSearch(option.label).includes(query))
  }, [items, search])

  const changeOpen = (next) => {
    if (!next) setSearch('')
    setOpen(next)
  }

  const pick = (option) => {
    if (option.disabled) return
    changeOpen(false)
    if (option.value !== current) onValueChange?.(option.value)
  }

  const openFromKeyboard = (event) => {
    if (disabled || open || event.altKey || event.ctrlKey || event.metaKey) return
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      changeOpen(true)
    } else if (event.key.length === 1 && event.key !== ' ') {
      // Typing on the closed picker opens it and starts the search.
      event.preventDefault()
      setSearch(event.key)
      setOpen(true)
    }
  }

  return (
    <Popover open={open} onOpenChange={changeOpen} modal>
      <PopoverTrigger asChild>
        <button
          type="button"
          id={id}
          role="combobox"
          aria-expanded={open}
          aria-required={required ? 'true' : undefined}
          aria-invalid={invalid ? 'true' : undefined}
          disabled={disabled}
          value={current}
          onKeyDown={openFromKeyboard}
          className={cn('flex items-center gap-2 text-left', className)}
          {...rest}
        >
          <span className={cn('min-w-0 flex-1 truncate', !selected && 'text-ink-muted')}>{selected ? selected.label : placeholder}</span>
          <Icon name="chevron" className={iconClassName} />
        </button>
      </PopoverTrigger>
      {name ? <input type="hidden" name={name} value={current} disabled={disabled} /> : null}
      <PopoverContent
        align="start"
        className={cn('w-picker gap-0 rounded-md border border-border bg-surface-200 p-0 text-ink shadow-lg ring-0', contentClassName)}
      >
        <Command
          shouldFilter={false}
          loop
          defaultValue={selected && !selected.disabled ? itemKey(selectedIndex) : undefined}
          className="rounded-md! bg-surface-200 p-0 text-ink"
        >
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
          <CommandList className="max-h-picker">
            <CommandEmpty className="px-3 py-4 text-body text-ink-muted">{t('ds.combobox.noMatches')}</CommandEmpty>
            <CommandGroup className="p-1 text-ink">
              {shown.map(({ option, index }) => (
                <CommandItem
                  key={itemKey(index)}
                  value={itemKey(index)}
                  disabled={option.disabled}
                  data-checked={option.value === current ? 'true' : 'false'}
                  onSelect={() => pick(option)}
                  className="cursor-pointer rounded-sm px-2 py-2 text-body text-ink data-[disabled=true]:text-ink-muted data-[disabled=true]:opacity-100 data-selected:bg-surface-300 data-selected:text-ink"
                >
                  <span className="min-w-0 flex-1 break-words">{option.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
