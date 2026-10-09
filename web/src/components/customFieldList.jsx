import { useTranslation } from 'react-i18next'
import { Money, TextField } from '@/components/ds'
import { customValueText, SORTABLE_TYPES, useCustomFieldSchema } from '@/lib/customFields'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { TypedFilter } from './TypedFilter'

const param = (key, bound) => (bound ? `custom[${key}][${bound}]` : `custom[${key}]`)

/**
 * CF-02, CF-03 in lists: the custom fields of `entity` the user sees as
 * columns (hidden until chosen in the Columns menu; key, sort and export
 * key `cf_<key>`), and filters for the drawer (`custom[<key>]`, ranges as
 * `custom[<key>][min|max]`). Spread `filterDefaults` into useServerList's
 * filters, `columns` after the list's own and `filterFields` after its own.
 */
export function useCustomListFields(entity) {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const { fields } = useCustomFieldSchema(entity)

  const columns = fields.map((field) => ({
    key: `cf_${field.key}`,
    label: field.label,
    sortKey: SORTABLE_TYPES.includes(field.type) ? `cf_${field.key}` : undefined,
    exportKey: `cf_${field.key}`,
    defaultHidden: true,
    align: ['number', 'money'].includes(field.type) || (field.type === 'formula' && field.formula_type === 'number') ? 'end' : undefined,
    numeric: ['number', 'money'].includes(field.type) || undefined,
    render: (row) => {
      const value = row.custom?.[field.key]
      if (field.type === 'money') return value?.amount_minor != null && value.amount_minor !== '' ? <Money amount={value.amount_minor} currency={value.currency} /> : ''
      return customValueText(field, value, { t, locale, timeZone })
    },
  }))

  const filterFields = fields.flatMap((field) => {
    const name = param(field.key)
    switch (field.type) {
      case 'select':
      case 'multi_select':
        return [
          {
            name,
            label: field.label,
            options: [{ value: '', label: t('customFields.filters.any') }, ...(field.options ?? []).map((option) => ({ value: String(option.value), label: option.label }))],
          },
        ]
      case 'boolean':
        return [
          {
            name,
            label: field.label,
            options: [
              { value: '', label: t('customFields.filters.any') },
              { value: 'true', label: t('customFields.yes') },
              { value: 'false', label: t('customFields.no') },
            ],
          },
        ]
      case 'text':
      case 'long_text':
        return [{ name, label: field.label, render: ({ label, value, onChange }) => <TypedFilter label={label} value={value} onChange={onChange} />, valueLabel: (value) => value }]
      case 'number':
        return ['min', 'max'].map((bound) => ({
          name: param(field.key, bound),
          label: t(`customFields.filters.${bound}`, { label: field.label }),
          render: ({ label, value, onChange }) => <TypedFilter label={label} value={value} onChange={onChange} inputMode="decimal" />,
          valueLabel: (value) => value,
        }))
      case 'date':
        return ['min', 'max'].map((bound) => ({
          name: param(field.key, bound),
          label: t(`customFields.filters.${bound === 'min' ? 'from' : 'to'}`, { label: field.label }),
          render: ({ label, value, onChange }) => <TextField type="date" label={label} className="w-full" value={value} onChange={(event) => onChange(event.target.value)} />,
          valueLabel: (value) => formatCalendarDate(value, locale) ?? value,
        }))
      default:
        return []
    }
  })

  const filterDefaults = Object.fromEntries(filterFields.map((field) => [field.name, '']))
  return { fields, columns, filterFields, filterDefaults }
}

/** Whether any custom filter of `custom` (useCustomListFields) is set on `list`. */
export const customFiltersActive = (list, custom) => custom.filterFields.some((field) => (list.filters?.[field.name] ?? '') !== '')
