import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Select, TextField } from '@/components/ds'
import { cn } from '@/lib/utils'
import { fieldOptions, OPERATORS, VALUELESS } from './blocks'

const tagsOf = (text) =>
  text
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)

/** One variant's name and when it applies: customer tags and conditions on merge fields (TPL-05). */
function VariantEditor({ variant, type, operators, canEdit, onChange, onRemove }) {
  const { t } = useTranslation()
  // The typed text, so commas and spaces survive while typing; the payload keeps the list.
  const [tagText, setTagText] = useState((variant.applies_when?.customer_tags ?? []).join(', '))
  const when = variant.applies_when ?? {}
  const conditions = when.conditions ?? []
  const setWhen = (patch) => onChange({ ...variant, applies_when: { ...when, ...patch } })
  const setCondition = (index, patch) => setWhen({ conditions: conditions.map((condition, at) => (at === index ? { ...condition, ...patch } : condition)) })
  const fields = fieldOptions(t, type.fields)

  return (
    <div className="flex flex-col gap-4 rounded-md border border-border p-4">
      <TextField
        label={t('documentTemplates.variants.name')}
        value={variant.name ?? ''}
        maxLength={100}
        readOnly={!canEdit}
        error={(variant.name ?? '').trim() === '' ? t('documentTemplates.variants.nameRequired') : undefined}
        onChange={(event) => onChange({ ...variant, name: event.target.value })}
      />
      <TextField
        label={t('documentTemplates.variants.tags')}
        help={t('documentTemplates.variants.tagsHelp')}
        value={tagText}
        readOnly={!canEdit}
        onChange={(event) => {
          setTagText(event.target.value)
          setWhen({ customer_tags: tagsOf(event.target.value) })
        }}
      />
      <div className="flex flex-col gap-3">
        <h4 className="text-label text-ink">{t('documentTemplates.variants.conditions')}</h4>
        {conditions.length === 0 ? <p className="text-caption text-ink-muted">{t('documentTemplates.variants.noConditions')}</p> : null}
        <ul className="flex flex-col gap-3">
          {conditions.map((condition, index) => (
            <li key={index} className="flex flex-wrap items-end gap-2">
              <Select
                label={t('documentTemplates.variants.field')}
                className="min-w-0 flex-1"
                options={fields}
                placeholder={t('documentTemplates.inspector.chooseField')}
                value={condition.field ?? ''}
                disabled={!canEdit}
                onChange={(event) => setCondition(index, { field: event.target.value })}
              />
              <Select
                label={t('documentTemplates.variants.operator')}
                options={operators.map((op) => ({ value: op, label: t(`documentTemplates.operators.${op}`, { defaultValue: op }) }))}
                value={condition.op ?? 'eq'}
                disabled={!canEdit}
                onChange={(event) => {
                  const op = event.target.value
                  const next = { ...condition, op }
                  // "Is empty" and "Has a value" take no value.
                  if (VALUELESS.includes(op)) delete next.value
                  setWhen({ conditions: conditions.map((entry, at) => (at === index ? next : entry)) })
                }}
              />
              {!VALUELESS.includes(condition.op) ? (
                <TextField
                  label={t('documentTemplates.variants.value')}
                  className="min-w-0 flex-1"
                  value={condition.value ?? ''}
                  readOnly={!canEdit}
                  onChange={(event) => setCondition(index, { value: event.target.value })}
                />
              ) : null}
              {canEdit ? (
                <Button
                  variant="ghost"
                  icon="remove"
                  aria-label={t('documentTemplates.variants.removeCondition', { n: index + 1 })}
                  className="size-icon-btn px-0"
                  onClick={() => setWhen({ conditions: conditions.filter((_, at) => at !== index) })}
                />
              ) : null}
            </li>
          ))}
        </ul>
        {canEdit ? (
          <div className="flex flex-wrap gap-2">
            <Button icon="plus" onClick={() => setWhen({ conditions: [...conditions, { field: type.fields?.[0]?.path ?? '', op: 'eq', value: '' }] })}>
              {t('documentTemplates.variants.addCondition')}
            </Button>
            <Button variant="danger" icon="remove" onClick={onRemove}>
              {t('documentTemplates.variants.remove')}
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  )
}

/**
 * TPL-05: the main template and its variants (a different layout for some
 * customers or documents). Picks which one the canvas and preview show.
 */
export function VariantsPanel({ payload, type, operators = OPERATORS, editing, onEdit, canEdit, onAdd, onChangeVariant, onRemoveVariant }) {
  const { t } = useTranslation()
  const variants = payload.variants ?? []
  const current = variants.find((variant) => variant.id === editing) ?? null
  const choices = [{ id: null, name: t('documentTemplates.variants.main') }, ...variants]

  return (
    <div className="flex flex-col gap-4">
      <p className="text-caption text-ink-muted">{t('documentTemplates.variants.help')}</p>
      <ul aria-label={t('documentTemplates.variants.label')} className="flex flex-col gap-px">
        {choices.map((choice) => {
          const active = (choice.id ?? null) === (editing ?? null)
          return (
            <li key={choice.id ?? 'main'}>
              <button
                type="button"
                aria-pressed={active}
                onClick={() => onEdit(choice.id)}
                className={cn(
                  'flex w-full items-center rounded-md px-3 py-2 text-left text-body text-ink-muted transition-colors hover:bg-surface-300 hover:text-ink',
                  active && 'bg-surface-300 font-medium text-ink',
                )}
              >
                {choice.name || t('documentTemplates.variants.unnamed')}
              </button>
            </li>
          )
        })}
      </ul>
      {canEdit && variants.length < 10 ? (
        <Button icon="plus" onClick={onAdd}>
          {t('documentTemplates.variants.add')}
        </Button>
      ) : null}
      {current ? (
        <VariantEditor
          key={current.id}
          variant={current}
          type={type}
          operators={operators}
          canEdit={canEdit}
          onChange={onChangeVariant}
          onRemove={() => onRemoveVariant(current.id)}
        />
      ) : null}
    </div>
  )
}
