// LAY-03: form layouts. A layout is { tabs, sections }: each section has an
// id, a title (the tenant's own words, or null), an optional tab, 1 to 3
// columns and its fields in order; a field entry is { id, hidden, label,
// help, width } with `default_label`, `wide` and `required` from the
// catalogue (the API merges it, LAY-07). Built-in fields are named by id,
// custom fields `custom.<key>`.
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'

export const CUSTOM_PREFIX = 'custom.'
const WIDE_CUSTOM = ['long_text', 'money', 'multi_select', 'file']

/**
 * The layout a form shows when none is resolved yet (loading, offline,
 * refused): the form's own sections, and every custom field the user sees
 * at the end of its `customGroup` section (else the last one).
 */
export function fallbackLayout(sections, customFields = [], customGroup = 'custom') {
  const custom = customFields.map((field) => ({ id: `${CUSTOM_PREFIX}${field.key}`, wide: WIDE_CUSTOM.includes(field.type) }))
  const target = sections.some((section) => section.id === customGroup) ? customGroup : sections.at(-1)?.id
  return {
    tabs: [],
    sections: sections.map((section) => ({
      id: section.id,
      title: section.title ?? null,
      tab: null,
      columns: section.columns ?? 2,
      fields: [...section.fields.map((field) => (typeof field === 'string' ? { id: field } : field)), ...(section.id === target ? custom : [])],
    })),
  }
}

/**
 * The layout of `formKey` for the signed-in user (GET config/form_layout/resolved):
 * the published one for their role or the tenant, else the form's default;
 * `fallback` while it loads or when it can't be read, so a form always renders.
 */
export function useFormLayout(formKey, fallback, { enabled = true } = {}) {
  const query = useQuery({
    queryKey: ['config', 'form_layout', 'resolved', formKey],
    queryFn: () => api.get(`config/form_layout/resolved?key=${encodeURIComponent(formKey)}`),
    enabled: Boolean(formKey) && enabled,
    retry: false,
    staleTime: 60_000,
  })
  const payload = query.data?.data?.payload
  const layout = Array.isArray(payload?.sections) && payload.sections.length ? payload : fallback
  return { ...query, layout }
}

/**
 * The sections to render: hidden entries and entries nothing can render
 * left out, custom fields missing from the layout added to the last section
 * (LAY-07), sections left empty dropped. `canRender(id)` says whether the
 * form renders a built-in field.
 */
export function visibleSections(layout, { customFields = [], canRender = () => true } = {}) {
  const sections = layout?.sections ?? []
  const custom = new Map(customFields.map((field) => [`${CUSTOM_PREFIX}${field.key}`, field]))
  const placed = new Set(sections.flatMap((section) => (section.fields ?? []).map((entry) => entry.id)))
  const missing = [...custom.keys()].filter((id) => !placed.has(id)).map((id) => ({ id, wide: WIDE_CUSTOM.includes(custom.get(id).type) }))
  return sections
    .map((section, index) => ({
      ...section,
      fields: [...(section.fields ?? []), ...(index === sections.length - 1 ? missing : [])].filter(
        (entry) => !entry.hidden && (entry.id.startsWith(CUSTOM_PREFIX) ? custom.has(entry.id) : canRender(entry.id)),
      ),
    }))
    .filter((section) => section.fields.length > 0)
}

/** Grid classes for a section's columns (1 to 3), stacked on phones. */
export function columnClass(columns) {
  if (columns === 1) return 'grid gap-4'
  if (columns === 3) return 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3'
  return 'grid gap-4 sm:grid-cols-2'
}
