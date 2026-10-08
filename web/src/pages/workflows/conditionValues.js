// Starting values for conditions and other typed field values (WF-04, AUTO-02).

export const UNARY = new Set(['empty', 'not_empty'])
export const LISTS = new Set(['in', 'not_in'])

/** A starting value for a comparison on `field` with `op`; money starts in `currency` (the company's, useMoneyDefaults). */
export function emptyValue(field, op, currency) {
  if (UNARY.has(op)) return undefined
  if (LISTS.has(op)) return []
  switch (field?.type) {
    case 'money':
      return { amount_minor: '', currency }
    case 'boolean':
      return true
    case 'enum':
      return field.values?.[0] ?? ''
    default:
      return ''
  }
}
