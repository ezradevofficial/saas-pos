import { useTranslation } from 'react-i18next'
import { Button, Money } from '@/components/ds'
import { lineTotals, newLine } from '@/lib/customForms'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { CustomFieldControl } from './CustomFieldControl'

const WIDE = ['long_text', 'multi_select', 'file']

/**
 * CF-05: a custom form's line table. Each line holds a control per line
 * field (custom fields of `custom_form_line:<key>`, in the type's column
 * order); lines are added and removed; the totals of number and money
 * fields show under the lines as they are typed (the API computes them
 * again on save). `errors` are the API's messages by `lines.<n>.custom.<key>`.
 */
export function LineTable({ entity, fields, lines, onChange, readOnly = false, errors = {}, showErrors = false, label, help }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const totals = lineTotals(fields, lines)
  const totalled = fields.filter((field) => field.key in totals)
  const setValue = (key, fieldKey, value) => onChange(lines.map((line) => (line.key === key ? { ...line, values: { ...line.values, [fieldKey]: value } } : line)))

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <span className="text-label text-ink">{label ?? t('customForms.lines.title')}</span>
        {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
      </div>
      {errors.lines ? <p className="text-caption text-danger">{errors.lines}</p> : null}
      {fields.length === 0 ? <p className="text-ink-muted">{t('customForms.lines.noFields')}</p> : null}
      {lines.length === 0 && fields.length > 0 ? <p className="text-ink-muted">{t('customForms.lines.empty')}</p> : null}
      {lines.length ? (
        <ol aria-label={label ?? t('customForms.lines.title')} className="flex flex-col divide-y divide-border border-y border-border">
          {lines.map((line, index) => (
            <li key={line.key} className="flex flex-wrap items-end gap-3 py-3" data-line={index + 1}>
              <span className="w-6 pb-2 text-caption text-ink-muted tabular-nums">{index + 1}</span>
              {fields.map((field) => (
                <CustomFieldControl
                  key={field.key}
                  field={field}
                  entity={entity}
                  label={t('customForms.lines.fieldOfLine', { field: field.label, n: index + 1 })}
                  value={line.values[field.key]}
                  onChange={(next) => setValue(line.key, field.key, next)}
                  disabled={readOnly}
                  error={errors[`lines.${index}.custom.${field.key}`]}
                  showErrors={showErrors}
                  className={cn('min-w-0 grow basis-full', WIDE.includes(field.type) ? 'sm:basis-full' : 'sm:basis-0')}
                />
              ))}
              {readOnly ? null : (
                <Button variant="ghost" icon="remove" aria-label={t('customForms.lines.remove', { n: index + 1 })} onClick={() => onChange(lines.filter((entry) => entry.key !== line.key))}>
                  {t('items.form.remove')}
                </Button>
              )}
            </li>
          ))}
        </ol>
      ) : null}
      {readOnly || fields.length === 0 ? null : (
        <div>
          <Button icon="plus" onClick={() => onChange([...lines, newLine(fields)])}>
            {t('customForms.lines.add')}
          </Button>
        </div>
      )}
      {totalled.length && lines.length ? (
        <dl className="flex flex-wrap justify-end gap-x-6 gap-y-2" aria-label={t('customForms.lines.totals')}>
          {totalled.map((field) => (
            <div key={field.key} className="flex items-baseline gap-2">
              <dt className="text-label text-ink-muted">{t('customForms.lines.total', { field: field.label })}</dt>
              <dd className="font-medium text-ink tabular-nums" data-total={field.key}>
                {field.type === 'money' ? (
                  totals[field.key] === null ? (
                    <span className="text-danger">{t('customForms.lines.mixedCurrencies')}</span>
                  ) : totals[field.key].currency ? (
                    <Money amount={totals[field.key].amount_minor} currency={totals[field.key].currency} />
                  ) : (
                    t('customFields.noValue')
                  )
                ) : (
                  formatDecimal(totals[field.key], locale)
                )}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}
    </div>
  )
}
