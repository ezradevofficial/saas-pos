// TPL-01..TPL-03, TPL-05: the template payload the designer edits, and pure
// helpers over its blocks (a row block holds two columns of blocks, one level deep).

export const PAPERS = ['58mm', '80mm', 'A4', 'A5']
export const THERMAL = ['58mm', '80mm']
export const LANGUAGES = ['en', 'fr', 'both']
export const ALIGNS = ['left', 'center', 'right']
export const TOTALS = ['subtotal', 'discount', 'tax', 'total', 'dual']
export const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'empty', 'not_empty']
/** Operators that take no value. */
export const VALUELESS = ['empty', 'not_empty']

/** Integer ranges the API accepts (TemplateSchema). */
export const RANGES = {
  margin: [0, 30],
  logoHeight: [5, 60],
  qrSize: [15, 60],
  barcodeHeight: [5, 30],
  spacer: [1, 40],
}

export const isThermal = (paper) => THERMAL.includes(paper)

/** The block's type in words ("Text", "Tax authority"). */
export const blockLabel = (t, block) => t(`documentTemplates.blocks.${block.type}`, { defaultValue: block.type })

/** "Company · Legal name": the merge fields grouped by record, for the pickers. */
export function fieldOptions(t, fields = []) {
  return fields.map((field) => ({
    value: field.path,
    label: `${t(`documentTemplates.groups.${field.group}`, { defaultValue: field.group })} · ${field.label}`,
  }))
}

/** The blocks of each block type when added from the palette. */
export function blockDefaults(type, { columns = [], fields = [] } = {}) {
  switch (type) {
    case 'text':
      return { text: '', align: 'left', size: 'normal', weight: 'regular' }
    case 'field':
      return { field: fields[0]?.path ?? 'document.number', label: true, align: 'left' }
    case 'logo':
      return { align: 'center', height: 15 }
    case 'lines':
      return { columns: columns.slice(0, 4).map((column) => column.key) }
    case 'totals':
      return { show: ['subtotal', 'tax', 'total'], tax_lines: true }
    case 'payments':
      return { show_change: true }
    case 'qr':
      return { content: '{{document.number}}', size: 25, align: 'center' }
    case 'barcode':
      return { content: '{{document.number}}', height: 10, align: 'center' }
    case 'signature':
      return { label: '' }
    case 'terms':
      return { text: '' }
    case 'spacer':
      return { size: 4 }
    case 'divider':
      return { style: 'solid' }
    case 'row':
      return { columns: [[], []] }
    default:
      return {}
  }
}

/** Every block of a layout, row children included. */
export function flatten(blocks = []) {
  return blocks.flatMap((block) => (block.type === 'row' ? [block, ...(block.columns ?? []).flat()] : [block]))
}

export const hasBlock = (blocks, type) => flatten(blocks).some((block) => block.type === type)

/** An id of the form `<type>-<n>` not yet used in the layout ([A-Za-z0-9_-]{1,40}). */
export function nextId(blocks, type) {
  const used = new Set(flatten(blocks).map((block) => block.id))
  let n = 1
  while (used.has(`${type}-${n}`)) n += 1
  return `${type}-${n}`
}

export function newBlock(type, blocks, context) {
  return { id: nextId(blocks, type), type, ...blockDefaults(type, context) }
}

export function findBlock(blocks, id) {
  return flatten(blocks).find((block) => block.id === id) ?? null
}

/** The blocks with `id` changed by `change(block)`; a row's children too. */
export function updateBlock(blocks, id, change) {
  return blocks.map((block) => {
    if (block.id === id) return change(block)
    if (block.type === 'row') return { ...block, columns: block.columns.map((column) => updateBlock(column, id, change)) }
    return block
  })
}

export function removeBlock(blocks, id) {
  return blocks
    .filter((block) => block.id !== id)
    .map((block) => (block.type === 'row' ? { ...block, columns: block.columns.map((column) => removeBlock(column, id)) } : block))
}

export function moveBlock(blocks, from, to) {
  const next = [...blocks]
  const [moved] = next.splice(from, 1)
  next.splice(to, 0, moved)
  return next
}

/** Where the variant (or the main template, `variant` null) takes each setting from. */
export function layoutOf(payload, variantId) {
  const variant = variantId ? (payload.variants ?? []).find((entry) => entry.id === variantId) : null
  if (!variant) return { paper: payload.paper, margins: payload.margins, language: payload.language, blocks: payload.blocks ?? [], variant: null }
  return {
    paper: variant.paper ?? payload.paper,
    margins: variant.margins ?? payload.margins,
    language: variant.language ?? payload.language,
    blocks: variant.blocks ?? [],
    variant,
  }
}

/** The payload with the edited layout (main or a variant) changed by `change(layout)`. */
export function changeLayout(payload, variantId, change) {
  if (!variantId) return { ...payload, ...change(payload) }
  return {
    ...payload,
    variants: (payload.variants ?? []).map((variant) => (variant.id === variantId ? { ...variant, ...change(variant) } : variant)),
  }
}

/** Every layout of the payload (main, then each variant) with its blocks. */
export const layouts = (payload) => [payload.blocks ?? [], ...(payload.variants ?? []).map((variant) => variant.blocks ?? [])]

/** A payload with the API's required keys, from what was saved or the type's default. */
export function normalise(payload, fallback) {
  const source = payload ?? fallback ?? {}
  return {
    paper: source.paper ?? fallback?.paper ?? 'A4',
    margins: source.margins ?? fallback?.margins ?? { top: 15, right: 15, bottom: 15, left: 15 },
    language: source.language ?? 'en',
    blocks: source.blocks ?? [],
    variants: source.variants ?? [],
  }
}

export function nextVariantId(payload) {
  const used = new Set((payload.variants ?? []).map((variant) => variant.id))
  let n = 1
  while (used.has(`variant-${n}`)) n += 1
  return `variant-${n}`
}

/** "{{path}}" placed in `text` between `start` and `end`. */
export function insertMerge(text, path, start, end) {
  const value = text ?? ''
  const from = start ?? value.length
  const to = end ?? from
  const token = `{{${path}}}`
  return { text: value.slice(0, from) + token + value.slice(to), caret: from + token.length }
}

/** An integer setting within its range, else null (the field shows the range). */
export function inRange(value, [min, max]) {
  return Number.isInteger(value) && value >= min && value <= max
}

/**
 * TPL-03: the locked blocks put back where missing: the totals (with their
 * tax lines) before the tax authority's block, which goes last (the
 * renderers add them the same way).
 */
export function withLockedBlocks(blocks = [], locked = []) {
  const next = [...blocks]
  if (locked.includes('totals') && !hasBlock(next, 'totals')) {
    const at = next.findIndex((block) => block.type === 'fiscal')
    const totals = { id: nextId(next, 'totals'), type: 'totals', show: ['subtotal', 'discount', 'total'], tax_lines: true }
    if (at === -1) next.push(totals)
    else next.splice(at, 0, totals)
  }
  if (locked.includes('fiscal') && !hasBlock(next, 'fiscal')) next.push({ id: 'fiscal', type: 'fiscal' })
  return next
}
