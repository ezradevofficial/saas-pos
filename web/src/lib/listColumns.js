// Column helpers for ListView lists (EXP-01, LAY-04).
import { createElement } from 'react'

/**
 * A list's row actions column (edit, archive, sign out): always shown, never
 * exported, kept out of the Columns menu; `label` is read by screen readers.
 */
export function actionsColumn(label, render, key = 'actions') {
  return { key, label: createElement('span', { className: 'sr-only' }, label), align: 'end', hideable: false, exportKey: null, inMenu: false, render }
}
