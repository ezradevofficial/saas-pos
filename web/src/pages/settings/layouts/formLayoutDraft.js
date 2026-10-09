// LAY-03: the form layout designer's draft: the stored layout over the
// form's catalogue (LAY-07, the API's rules), and back to a payload.
import { mergeGrouped } from '@/lib/catalogueMerge'

const LAYOUT_KEYS = ['id', 'hidden', 'hidden_roles', 'label', 'help', 'width']

/** The draft to edit: `stored` (a payload, or null) laid over the catalogue `fields`; `defaults` when nothing is stored. */
export function editableLayout(stored, fields, defaults) {
  const base = stored?.sections?.length ? stored : defaults
  const sectionIds = (base?.sections ?? []).map((section) => section.id)
  const last = sectionIds.at(-1)
  // A new field whose group the layout lacks goes to the last section.
  const catalogue = (fields ?? []).map((field) => ({ ...field, group: sectionIds.includes(field.group) ? field.group : last }))
  const sections = mergeGrouped(
    (base?.sections ?? []).map((section) => ({ ...section, fields: (section.fields ?? []).map((entry) => pick(entry)) })),
    catalogue,
    { items: 'fields', newGroup: { id: 'main', title: null, tab: null, columns: 2 } },
  )
  return {
    tabs: (base?.tabs ?? []).map((tab) => ({ id: tab.id, title: tab.title ?? '' })),
    sections: sections.map((section) => ({ id: section.id, title: section.title ?? '', tab: section.tab ?? null, columns: section.columns ?? 2, fields: section.fields })),
  }
}

const pick = (entry) => Object.fromEntries(Object.entries(entry ?? {}).filter(([key]) => LAYOUT_KEYS.includes(key)))

/** The payload to store: titles typed once, only what the layout itself sets for each field. */
export function layoutPayload(draft) {
  return {
    tabs: draft.tabs.map((tab) => ({ id: tab.id, title: tab.title.trim() })),
    sections: draft.sections.map((section) => ({
      id: section.id,
      title: section.title?.trim() || null,
      tab: draft.tabs.length ? (section.tab ?? draft.tabs[0]?.id ?? null) : null,
      columns: section.columns,
      fields: section.fields.map((entry) => {
        const out = { id: entry.id }
        if (entry.hidden) out.hidden = true
        if (entry.hidden_roles?.length) out.hidden_roles = entry.hidden_roles
        if (entry.label?.trim()) out.label = entry.label.trim()
        if (entry.help?.trim()) out.help = entry.help.trim()
        if (entry.width === 'full') out.width = 'full'
        return out
      }),
    })),
  }
}

/** Whether a field may be hidden: a required field without a default can't be (the API refuses it too). */
export const canHide = (entry) => !(entry.required && !entry.has_default)

/** The next free id with `prefix` among `taken`. */
export function nextId(prefix, taken) {
  let n = 1
  while (taken.includes(`${prefix}-${n}`)) n += 1
  return `${prefix}-${n}`
}
