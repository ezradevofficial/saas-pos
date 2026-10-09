import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Tabs } from '@/components/ds'
import { columnClass, CUSTOM_PREFIX, visibleSections } from '@/lib/formLayout'
import { cn } from '@/lib/utils'
import { CustomFieldControl } from './CustomFieldControl'

/**
 * LAY-03: a form laid out as its layout says: tabs (when it has any), then
 * sections as cards with their columns, each field where it was placed,
 * relabelled or with help text when the layout says so.
 *
 * - `fields` renders the form's own fields: `{ [id]: ({ label, help }) => node }`;
 *   `label` and `help` are the layout's overrides (undefined: the form's own
 *   words). A field the form renders as null (hidden by field rules,
 *   RBAC-05, or not asked here) takes no room.
 * - `custom` renders custom fields (`custom.<key>`): `{ entity, fields }` from
 *   useCustomFieldSchema, `values` from useCustomValues, `errors` by key.
 *   Fields the schema leaves out (hidden from the user) are never rendered.
 *
 * Hiding in a layout is presentation only: the API still decides what the
 * user sees and may change.
 */
export function FormLayoutRenderer({ layout, fields = {}, custom = null, readOnly = false, showErrors = false }) {
  const { t } = useTranslation()
  const tabs = layout?.tabs ?? []
  const [tab, setTab] = useState(null)
  const activeTab = tabs.some((entry) => entry.id === tab) ? tab : (tabs[0]?.id ?? null)
  const schema = custom?.fields ?? []
  const byKey = new Map(schema.map((field) => [`${CUSTOM_PREFIX}${field.key}`, field]))

  const renderEntry = (entry) => {
    const label = entry.label?.trim() || undefined
    const help = entry.help?.trim() || undefined
    if (entry.id.startsWith(CUSTOM_PREFIX)) {
      const field = byKey.get(entry.id)
      if (!field || !custom) return null
      const key = field.key
      return (
        <CustomFieldControl
          field={help ? { ...field, help } : field}
          label={label}
          entity={custom.entity}
          value={custom.values.values[key]}
          onChange={(next) => custom.values.set(key, next)}
          disabled={readOnly}
          error={custom.errors?.[key]}
          showErrors={showErrors}
        />
      )
    }
    return fields[entry.id]?.({ label, help }) ?? null
  }

  const sections = visibleSections(layout, { customFields: schema, canRender: (id) => Boolean(fields[id]) })
    .map((section) => ({
      ...section,
      nodes: section.fields
        .map((entry) => ({ entry, node: renderEntry(entry) }))
        .filter(({ node }) => node !== null && node !== undefined && node !== false),
    }))
    .filter((section) => section.nodes.length > 0)

  const usedTabs = tabs.filter((entry) => sections.some((section) => section.tab === entry.id))

  return (
    <div className="flex flex-col gap-5" data-form-layout>
      {usedTabs.length > 1 ? (
        <Tabs items={usedTabs.map((entry) => ({ value: entry.id, label: entry.title }))} value={activeTab} onChange={setTab} />
      ) : null}
      {sections.map((section) => (
        <div key={section.id} hidden={usedTabs.length > 1 && section.tab !== activeTab} data-section={section.id}>
          <Card title={section.title || undefined}>
            <div className={columnClass(section.columns)}>
              {section.nodes.map(({ entry, node }) => (
                <div key={entry.id} className={cn('min-w-0', (entry.width === 'full' || entry.wide) && 'col-span-full')} data-field={entry.id}>
                  {node}
                </div>
              ))}
            </div>
          </Card>
        </div>
      ))}
      {sections.length === 0 ? <p className="text-ink-muted">{t('formLayouts.empty')}</p> : null}
    </div>
  )
}
