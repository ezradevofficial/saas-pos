import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { TextField } from '@/components/ds'
import { cn } from '@/lib/utils'
import { levelOf, withLevel } from './scopeLevels'

const LEVELS = ['none', 'read', 'write']

function LevelSwitch({ resource, label, value, onChange }) {
  const { t } = useTranslation()
  return (
    <div role="group" aria-label={label} className="flex shrink-0 items-center rounded-md border border-border-strong p-px">
      {LEVELS.map((level) => (
        <button
          key={level}
          type="button"
          aria-pressed={value === level}
          data-resource={resource}
          onClick={() => onChange(level)}
          className={cn(
            'rounded-sm px-2 py-1 text-caption text-ink-muted hover:text-ink',
            value === level && 'bg-surface-300 font-medium text-ink',
          )}
        >
          {t(`apiKeys.scopes.levels.${level}`)}
        </button>
      ))}
    </div>
  )
}

/**
 * API-02: a key's scopes, per resource of the permission catalogue (active
 * modules only): no access, read, or read and write. Scopes only narrow
 * what the key's role allows; the API checks both.
 */
export function ScopePicker({ groups = [], value = [], onChange, error }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const needle = search.trim().toLowerCase()
  const shown = useMemo(
    () =>
      groups
        .map((group) => ({
          ...group,
          resources: group.resources.filter(
            (resource) => !needle || resource.label.toLowerCase().includes(needle) || resource.resource.includes(needle) || group.label.toLowerCase().includes(needle),
          ),
        }))
        .filter((group) => group.resources.length > 0),
    [groups, needle],
  )

  return (
    <fieldset className="flex flex-col gap-3">
      <legend className="text-label text-ink">{t('apiKeys.scopes.legend')}</legend>
      <p className="text-caption text-ink-muted">{t('apiKeys.scopes.help', { count: value.length })}</p>
      <TextField label={t('apiKeys.scopes.search')} value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" />
      {error ? <p className="text-caption text-danger">{error}</p> : null}
      <div className="flex max-h-panel flex-col gap-4 overflow-y-auto rounded-md border border-border p-3">
        {shown.length === 0 ? <p className="text-ink-muted">{t('apiKeys.scopes.none')}</p> : null}
        {shown.map((group) => (
          <section key={group.module} className="flex flex-col gap-2">
            <h3 className="text-caption text-ink-muted">{group.label}</h3>
            <ul className="flex flex-col divide-y divide-border">
              {group.resources.map((resource) => (
                <li key={resource.resource} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <div className="flex min-w-0 flex-col">
                    <span className="text-ink">{resource.label}</span>
                    <code className="font-mono text-caption text-ink-muted">{resource.resource}</code>
                  </div>
                  <LevelSwitch
                    resource={resource.resource}
                    label={resource.label}
                    value={levelOf(value, resource.resource)}
                    onChange={(level) => onChange(withLevel(value, resource.resource, level))}
                  />
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </fieldset>
  )
}
