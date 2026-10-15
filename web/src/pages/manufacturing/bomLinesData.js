// MFG-04: a BOM version's lines in the editor and as the API takes them.

let nextKey = 0
const key = () => `bom-line-${(nextKey += 1)}`

/** A blank line of a version (a key keeps React's rows apart). */
export const blankLine = (kind = 'component') => ({ key: key(), kind, item: null, uom: null, uom_id: '', qty: '', yield_percent: '', wastage_percent: '', explode: false, cost_share_percent: '', note: '' })

/** A version's line as the API sends it, for the editor. */
export const lineFromApi = (line) => ({
  key: key(),
  kind: line.kind,
  item: line.item,
  uom: line.uom,
  uom_id: line.uom?.id ?? '',
  qty: line.qty ?? '',
  yield_percent: line.yield_percent ?? '',
  wastage_percent: line.wastage_percent ?? '',
  explode: Boolean(line.explode),
  cost_share_percent: line.cost_share_percent ?? '',
  note: line.note ?? '',
})

export const filled = (value) => value !== '' && value !== null && value !== undefined

/**
 * MFG-04: a line as the API takes it: a component with its yield, wastage
 * and explode; a by-product with its cost share; empty values left out
 * (the API's defaults apply).
 */
export function lineBody(line) {
  const component = line.kind === 'component'
  return {
    kind: line.kind,
    item_id: line.item?.id,
    ...(line.uom_id ? { uom_id: line.uom_id } : {}),
    qty: line.qty,
    ...(component && filled(line.yield_percent) ? { yield_percent: line.yield_percent } : {}),
    ...(component && filled(line.wastage_percent) ? { wastage_percent: line.wastage_percent } : {}),
    ...(component ? { explode: Boolean(line.explode) } : {}),
    ...(!component && filled(line.cost_share_percent) ? { cost_share_percent: line.cost_share_percent } : {}),
    ...(line.note.trim() ? { note: line.note.trim() } : {}),
  }
}

/** Whether every line is filled enough to send. */
export const linesReady = (lines) => lines.every((line) => line.item && line.qty)

/** Field names of every line, so `formErrors` puts the API's messages under their fields. */
export const lineFieldNames = (prefix, count) =>
  Array.from({ length: count }, (_, index) => ['kind', 'item_id', 'uom_id', 'qty', 'yield_percent', 'wastage_percent', 'explode', 'cost_share_percent', 'note'].map((name) => `${prefix}lines.${index}.${name}`)).flat()

/** A version's header as the API takes it. */
export function versionBody(values, lines) {
  return {
    output_qty: values.output_qty,
    ...(values.output_uom_id ? { output_uom_id: values.output_uom_id } : {}),
    ...(filled(values.labour_minutes) ? { labour_minutes: Number(values.labour_minutes) } : {}),
    note: values.note.trim() || null,
    lines: lines.map(lineBody),
  }
}
