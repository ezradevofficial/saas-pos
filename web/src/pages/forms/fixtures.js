// Shared answers for the custom form page tests (CF-04, CF-05).
const base = { help: null, default: null, required: false, unique: false, min: null, max: null, pattern: null, options: [], lookup_target: null, formula_type: null, readonly: false }

export const PETTY_CASH = {
  id: 'ft-1',
  key: 'petty_cash',
  name: 'Petty cash request',
  description: null,
  entity: 'custom_form:petty_cash',
  line_entity: 'custom_form_line:petty_cash',
  layout_key: 'custom_form.petty_cash',
  document_type: 'core.custom_form_petty_cash',
  number_type: 'core.custom_form_petty_cash',
  workflow: true,
  has_lines: true,
  attachments: true,
  line_fields: [],
  role_ids: [],
  archived_at: null,
  can: { create: true },
}

export const HEADER_FIELDS = [{ ...base, key: 'reason', type: 'text', label: 'Reason', required: true }]
export const LINE_FIELDS = [
  { ...base, key: 'description', type: 'text', label: 'Description', required: true },
  { ...base, key: 'quantity', type: 'number', label: 'Quantity' },
]

export const COMPANY = { id: 'c-1', name: 'Acme', archived_at: null, timezone: 'Africa/Nairobi', base_currency: 'KES', country: 'KE' }

export const FORM_PERMISSIONS = ['core.company.view', 'core.custom_form.view', 'core.custom_form.create', 'core.custom_form.edit', 'core.custom_form_type.manage']

/** The routes every form page reads: the types, the field schemas, the places. */
export function formRoutes(extra = []) {
  return [
    ...extra,
    ['custom-form-types', { data: [PETTY_CASH] }],
    ['custom-fields/schema?entity=custom_form%3Apetty_cash', { data: HEADER_FIELDS }],
    ['custom-fields/schema?entity=custom_form_line%3Apetty_cash', { data: LINE_FIELDS }],
    ['branches?per_page=200', { data: [] }],
    ['locations?per_page=200', { data: [] }],
  ]
}
