// Picker options and search, shared by the ds Combobox and Select.

/** Lower case without accents, so "societe" finds "Société". */
export function normalizeSearch(text) {
  return String(text ?? '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
}

/** Options as strings or { value, label, disabled }, normalised to objects with string values. */
export function normalizeOptions(options = []) {
  return options.map((option) =>
    typeof option === 'string'
      ? { value: option, label: option, disabled: false }
      : { value: String(option.value ?? ''), label: option.label ?? String(option.value ?? ''), disabled: Boolean(option.disabled) },
  )
}
