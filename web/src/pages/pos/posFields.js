import { createElement } from 'react'
import { TextField } from '@/components/ds'

/** A date filter for a list's drawer (`from`, `to`: calendar days); the chip shows the date as typed. */
export const dateField = (name, label) => ({
  name,
  label,
  render: ({ value, onChange }) => createElement(TextField, { type: 'date', label, value, onChange: (event) => onChange(event.target.value), className: 'w-full' }),
  valueLabel: (value) => value,
})
