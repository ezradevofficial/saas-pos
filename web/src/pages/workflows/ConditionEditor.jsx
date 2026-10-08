import { useTranslation } from 'react-i18next'
import { Button, Checkbox, DecimalInput, MoneyInput, Select, TextField } from '@/components/ds'
import { CURRENCY_DECIMALS } from '@/lib/money'
import { cn } from '@/lib/utils'

const UNARY = new Set(['empty', 'not_empty'])
const LISTS = new Set(['in', 'not_in'])
const MAX_DEPTH = 3
const CURRENCIES = Object.keys(CURRENCY_DECIMALS)

/** A starting value for a comparison on `field` with `op`. */
function emptyValue(field, op) {
  if (UNARY.has(op)) return undefined
  if (LISTS.has(op)) return []
  switch (field?.type) {
    case 'money':
      return { amount_minor: '', currency: 'KES' }
    case 'boolean':
      return true
    case 'enum':
      return field.values?.[0] ?? ''
    default:
      return ''
  }
}

function comparison(field, op = field?.operators?.[0] ?? 'eq') {
  const next = { field: field?.name ?? '', op }
  const value = emptyValue(field, op)
  if (value !== undefined) next.value = value
  return next
}

/** A value typed for its field: money, number, date, an enum's values, yes/no or text. */
export function ValueInput({ field, op = 'eq', value, onChange, label, error }) {
  const { t } = useTranslation()
  if (LISTS.has(op)) {
    const list = Array.isArray(value) ? value : []
    if (field?.type === 'enum') {
      return (
        <fieldset className="flex min-w-0 flex-col gap-2">
          <legend className="pb-1 text-label text-ink">{label}</legend>
          {field.values.map((option) => (
            <Checkbox
              key={option}
              label={option}
              checked={list.includes(option)}
              onChange={(event) => onChange(event.target.checked ? [...list, option] : list.filter((one) => one !== option))}
            />
          ))}
        </fieldset>
      )
    }
    return (
      <TextField
        label={label}
        help={t('workflows.condition.listHelp')}
        value={list.join(', ')}
        error={error}
        onChange={(event) =>
          onChange(
            event.target.value
              .split(',')
              .map((one) => one.trim())
              .filter((one) => one !== ''),
          )
        }
      />
    )
  }

  switch (field?.type) {
    case 'money': {
      const money = value && typeof value === 'object' ? value : { amount_minor: '', currency: 'KES' }
      return (
        <div className="flex min-w-0 items-end gap-2">
          <Select
            label={t('workflows.condition.currency')}
            options={CURRENCIES}
            value={money.currency}
            onChange={(event) => onChange({ ...money, currency: event.target.value })}
            className="w-24 shrink-0"
          />
          <MoneyInput
            key={money.currency}
            label={label}
            currency={money.currency}
            value={money.amount_minor}
            error={error}
            onChange={(minor) => onChange({ ...money, amount_minor: minor ?? '' })}
            className="min-w-0 flex-1"
          />
        </div>
      )
    }
    case 'number':
      return <DecimalInput label={label} value={value ?? ''} positive={false} error={error} onChange={(next) => onChange(next ?? '')} />
    case 'date':
      return <TextField type="date" label={label} value={value ?? ''} error={error} onChange={(event) => onChange(event.target.value)} />
    case 'enum':
      return <Select label={label} options={field.values} value={value ?? ''} error={error} onChange={(event) => onChange(event.target.value)} />
    case 'boolean':
      return (
        <Select
          label={label}
          options={[
            { value: 'true', label: t('workflows.condition.yes') },
            { value: 'false', label: t('workflows.condition.no') },
          ]}
          value={value === false ? 'false' : 'true'}
          onChange={(event) => onChange(event.target.value === 'true')}
        />
      )
    default:
      return <TextField label={label} value={value ?? ''} error={error} onChange={(event) => onChange(event.target.value)} />
  }
}

function ComparisonRow({ value, fields, onChange, onRemove, index }) {
  const { t } = useTranslation()
  const field = fields.find((one) => one.name === value.field)
  const operators = field?.operators ?? []
  const others = fields.filter((one) => one.name !== field?.name && one.type === field?.type)
  const comparesOther = value.other !== undefined
  const canCompareOther = others.length > 0 && !UNARY.has(value.op) && !LISTS.has(value.op)

  return (
    <div role="group" aria-label={t('workflows.condition.ruleNumber', { number: index + 1 })} className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-3">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Select
          label={t('workflows.condition.field')}
          options={fields.map((one) => ({ value: one.name, label: one.label }))}
          value={value.field}
          placeholder={t('workflows.condition.chooseField')}
          onChange={(event) => onChange(comparison(fields.find((one) => one.name === event.target.value)))}
        />
        <Select
          label={t('workflows.condition.operator')}
          options={operators.map((op) => ({ value: op, label: t(`workflows.operators.${op}`) }))}
          value={value.op}
          onChange={(event) => onChange(comparison(field, event.target.value))}
        />
      </div>
      {canCompareOther ? (
        <Select
          label={t('workflows.condition.compareWith')}
          options={[
            { value: 'value', label: t('workflows.condition.aValue') },
            { value: 'other', label: t('workflows.condition.anotherField') },
          ]}
          value={comparesOther ? 'other' : 'value'}
          onChange={(event) => {
            if (event.target.value === 'other') onChange({ field: value.field, op: value.op, other: others[0].name })
            else onChange(comparison(field, value.op))
          }}
        />
      ) : null}
      {UNARY.has(value.op) ? null : comparesOther ? (
        <Select
          label={t('workflows.condition.otherField')}
          options={others.map((one) => ({ value: one.name, label: one.label }))}
          value={value.other}
          onChange={(event) => onChange({ field: value.field, op: value.op, other: event.target.value })}
        />
      ) : field ? (
        <ValueInput
          key={`${value.field}-${value.op}`}
          field={field}
          op={value.op}
          label={t('workflows.condition.value')}
          value={value.value}
          onChange={(next) => onChange({ field: value.field, op: value.op, value: next })}
        />
      ) : null}
      <Button variant="ghost" icon="remove" className="self-start" onClick={onRemove}>
        {t('workflows.condition.removeRule')}
      </Button>
    </div>
  )
}

const isGroup = (condition) => Boolean(condition && (Array.isArray(condition.all) || Array.isArray(condition.any)))

/**
 * Conditions on the document's fields (WF-04, WF-05, AUTO-02): rules
 * grouped with AND (all) or OR (any), groups nested up to three levels.
 * `value` is null (no rule), a comparison or a group; an emptied group
 * reports null, since the API refuses empty groups.
 */
export function ConditionEditor({ label, help, value, onChange, fields, depth = 0, onRemove }) {
  const { t } = useTranslation()
  const group = value == null ? null : isGroup(value) ? value : { all: [value] }
  const mode = group && Array.isArray(group.any) ? 'any' : 'all'
  const items = group ? group[mode] : []

  const write = (nextMode, nextItems) => {
    const kept = nextItems.filter(Boolean)
    onChange(kept.length === 0 ? null : { [nextMode]: kept })
  }
  const add = (item) => write(mode, [...items, item])

  if (!group) {
    return (
      <div className="flex flex-col gap-2">
        <div className="flex flex-col gap-1">
          <span className="text-label text-ink">{label}</span>
          {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
        </div>
        <span className="text-caption text-ink-muted">{t('workflows.condition.none')}</span>
        <Button icon="plus" className="self-start" disabled={fields.length === 0} onClick={() => add(comparison(fields[0]))}>
          {t('workflows.condition.addRule')}
        </Button>
      </div>
    )
  }

  return (
    <fieldset className={cn('flex min-w-0 flex-col gap-3', depth > 0 && 'rounded-md border border-border p-3')}>
      <legend className="pb-1 text-label text-ink">{label}</legend>
      {help && depth === 0 ? <span className="text-caption text-ink-muted">{help}</span> : null}
      <Select
        label={t('workflows.condition.match')}
        options={[
          { value: 'all', label: t('workflows.condition.matchAll') },
          { value: 'any', label: t('workflows.condition.matchAny') },
        ]}
        value={mode}
        onChange={(event) => write(event.target.value, items)}
      />
      {items.map((item, index) =>
        isGroup(item) ? (
          <ConditionEditor
            key={index}
            label={t('workflows.condition.groupNumber', { number: index + 1 })}
            value={item}
            fields={fields}
            depth={depth + 1}
            onChange={(next) => write(mode, items.map((one, i) => (i === index ? next : one)))}
            onRemove={() => write(mode, items.filter((_, i) => i !== index))}
          />
        ) : (
          <ComparisonRow
            key={index}
            index={index}
            value={item}
            fields={fields}
            onChange={(next) => write(mode, items.map((one, i) => (i === index ? next : one)))}
            onRemove={() => write(mode, items.filter((_, i) => i !== index))}
          />
        ),
      )}
      <div className="flex flex-wrap gap-2">
        <Button icon="plus" onClick={() => add(comparison(fields[0]))}>
          {t('workflows.condition.addRule')}
        </Button>
        {depth + 1 < MAX_DEPTH ? (
          <Button variant="ghost" icon="plus" onClick={() => add({ [mode === 'all' ? 'any' : 'all']: [comparison(fields[0])] })}>
            {t('workflows.condition.addGroup')}
          </Button>
        ) : null}
        {onRemove ? (
          <Button variant="ghost" icon="remove" onClick={onRemove}>
            {t('workflows.condition.removeGroup')}
          </Button>
        ) : null}
      </div>
    </fieldset>
  )
}
