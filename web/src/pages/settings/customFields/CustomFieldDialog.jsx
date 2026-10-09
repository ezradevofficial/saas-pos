import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { CustomFieldControl, SignedDecimalInput } from '@/components/CustomFieldControl'
import { Alert, Button, Checkbox, Dialog, MultiSelect, Select, Switch, TextField } from '@/components/ds'
import {
  BOUNDED_TYPES,
  DEFAULTABLE_TYPES,
  FORMULA_TYPES,
  OPTION_TYPES,
  slugify,
  toApiValue,
  toFormValue,
  UNIQUE_TYPES,
} from '@/lib/customFields'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useTimeZone } from '@/lib/useTimeZone'
import { TextAreaField } from '@/pages/approvals/TextAreaField'

let rowKey = 0
const nextKey = () => `option-${++rowKey}`

const KEY_PATTERN = /^[a-z][a-z0-9_]{0,39}$/

function initialValues(record) {
  return {
    label: record?.label ?? '',
    key: record?.key ?? '',
    keyTouched: Boolean(record),
    help: record?.help ?? '',
    type: record?.type ?? 'text',
    required: Boolean(record?.required),
    unique: Boolean(record?.unique),
    min: record?.min ?? '',
    max: record?.max ?? '',
    pattern: record?.pattern ?? '',
    options: (record?.options ?? []).map((option) => ({ key: nextKey(), value: String(option.value ?? ''), label: option.label ?? '', valueTouched: true })),
    lookup_target: record?.lookup_target ?? '',
    formula: record?.formula ?? '',
    formula_type: record?.formula_type ?? 'number',
    visible_roles: record?.visible_roles ?? [],
    editable_roles: record?.editable_roles ?? [],
    show_on_pos: Boolean(record?.show_on_pos),
    position: record?.position == null ? '' : String(record.position),
  }
}

/** CF-01: a select's options, as rows of label and value; added, removed and moved up or down. */
function OptionsEditor({ options, onChange, errors, error }) {
  const { t } = useTranslation()
  const setRow = (key, patch) => onChange(options.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  const move = (index, step) => {
    const next = [...options]
    ;[next[index], next[index + step]] = [next[index + step], next[index]]
    onChange(next)
  }
  return (
    <fieldset className="flex min-w-0 flex-col gap-3">
      <legend className="pb-1 text-label text-ink">{t('customFields.fields.options')}</legend>
      <span className="text-caption text-ink-muted">{t('customFields.fields.optionsHelp')}</span>
      {error ? <p className="text-caption text-danger">{error}</p> : null}
      {options.length ? (
        <ol aria-label={t('customFields.fields.options')} className="flex flex-col divide-y divide-border border-y border-border">
          {options.map((row, index) => (
            <li key={row.key} className="flex flex-wrap items-end gap-3 py-3">
              <TextField
                label={t('customFields.fields.optionLabel')}
                className="min-w-0 grow basis-full sm:basis-0"
                value={row.label}
                maxLength={100}
                error={errors[`options.${index}.label`]}
                onChange={(event) => {
                  const label = event.target.value
                  setRow(row.key, row.valueTouched ? { label } : { label, value: slugify(label) })
                }}
              />
              <TextField
                label={t('customFields.fields.optionValue')}
                className="min-w-0 grow basis-full sm:basis-0"
                value={row.value}
                maxLength={60}
                autoComplete="off"
                error={errors[`options.${index}.value`]}
                onChange={(event) => setRow(row.key, { value: event.target.value, valueTouched: true })}
              />
              <span className="flex gap-1">
                <Button
                  variant="ghost"
                  icon="up"
                  className="size-icon-btn px-0"
                  disabled={index === 0}
                  onClick={() => move(index, -1)}
                  aria-label={t('customFields.fields.moveOptionUp', { name: row.label || index + 1 })}
                />
                <Button
                  variant="ghost"
                  icon="down"
                  className="size-icon-btn px-0"
                  disabled={index === options.length - 1}
                  onClick={() => move(index, 1)}
                  aria-label={t('customFields.fields.moveOptionDown', { name: row.label || index + 1 })}
                />
                <Button
                  variant="ghost"
                  icon="remove"
                  className="size-icon-btn px-0"
                  onClick={() => onChange(options.filter((other) => other.key !== row.key))}
                  aria-label={t('customFields.fields.removeOption', { name: row.label || index + 1 })}
                />
              </span>
            </li>
          ))}
        </ol>
      ) : (
        <p className="text-ink-muted">{t('customFields.fields.noOptions')}</p>
      )}
      <div>
        <Button icon="plus" onClick={() => onChange([...options, { key: nextKey(), value: '', label: '', valueTouched: false }])}>
          {t('customFields.fields.addOption')}
        </Button>
      </div>
    </fieldset>
  )
}

/**
 * CF-01: a formula in a mono box, with a help panel whose field keys,
 * functions and operators insert themselves where the cursor is.
 */
function FormulaEditor({ value, onChange, error, fieldKeys, functions, operators }) {
  const { t } = useTranslation()
  const box = useRef(null)
  const insert = (text) => {
    const element = box.current
    const start = element?.selectionStart ?? value.length
    const end = element?.selectionEnd ?? value.length
    const next = value.slice(0, start) + text + value.slice(end)
    onChange(next)
    const cursor = start + text.length
    requestAnimationFrame(() => {
      if (!box.current) return
      box.current.focus()
      box.current.setSelectionRange(cursor, cursor)
    })
  }
  const chip = 'rounded-md border border-border bg-surface-100 px-2 py-1 font-mono text-caption text-ink hover:bg-surface-300'
  return (
    <div className="flex flex-col gap-3">
      <TextAreaField
        ref={box}
        label={t('customFields.fields.formula')}
        help={t('customFields.fields.formulaHelp')}
        rows={4}
        spellCheck={false}
        autoComplete="off"
        inputClassName="font-mono"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        error={error}
        required
      />
      <section aria-label={t('customFields.formula.helpTitle')} className="flex flex-col gap-3 rounded-md border border-border p-3">
        <div className="flex flex-col gap-2">
          <h4 className="text-label text-ink">{t('customFields.formula.fields')}</h4>
          {fieldKeys.length ? (
            <div className="flex flex-wrap gap-2">
              {fieldKeys.map((entry) => (
                <button key={entry.key} type="button" className={chip} title={entry.label} aria-label={t('customFields.formula.insert', { text: entry.key })} onClick={() => insert(entry.key)}>
                  {entry.key}
                </button>
              ))}
            </div>
          ) : (
            <p className="text-caption text-ink-muted">{t('customFields.formula.noFields')}</p>
          )}
        </div>
        {functions.length ? (
          <div className="flex flex-col gap-2">
            <h4 className="text-label text-ink">{t('customFields.formula.functions')}</h4>
            <div className="flex flex-wrap gap-2">
              {functions.map((name) => (
                <button key={name} type="button" className={chip} aria-label={t('customFields.formula.insert', { text: `${name}()` })} onClick={() => insert(`${name}(`)}>
                  {name}()
                </button>
              ))}
            </div>
          </div>
        ) : null}
        {operators.length ? (
          <div className="flex flex-col gap-2">
            <h4 className="text-label text-ink">{t('customFields.formula.operators')}</h4>
            <div className="flex flex-wrap gap-2">
              {operators.map((operator) => (
                <button key={operator} type="button" className={chip} aria-label={t('customFields.formula.insert', { text: operator })} onClick={() => insert(` ${operator} `)}>
                  {operator}
                </button>
              ))}
            </div>
          </div>
        ) : null}
      </section>
    </div>
  )
}

/**
 * CF-01, RBAC-05: add or edit a custom field of `entity`. Key and type are
 * chosen once, on add (the key is suggested from the label until typed);
 * the rest can change later. Who sees and who edits the field are roles;
 * none means everyone with access to the record.
 */
export function CustomFieldDialog({ entity, entityLabel, record, meta, roles, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const timeZone = useTimeZone()
  const creating = !record
  const [values, setValues] = useState(() => initialValues(record))
  const [submitted, setSubmitted] = useState(false)
  const type = values.type
  const defaultField = { key: 'default', type, label: t('customFields.fields.default'), options: values.options.filter((row) => row.value.trim()).map((row) => ({ value: row.value.trim(), label: row.label || row.value })) }
  const [defaultValue, setDefaultValue] = useState(() => toFormValue({ type }, record?.default ?? null, timeZone))
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const setValue = (field, value) => setValues((current) => ({ ...current, [field]: value }))

  // The other fields of the entity, for the formula help (CF-01).
  const siblings = useQuery({
    queryKey: ['custom-fields', 'siblings', entity],
    queryFn: () => api.get(`custom-fields?entity=${encodeURIComponent(entity)}&status=active&sort=position&per_page=100`),
    enabled: type === 'formula',
  })
  const fieldKeys = (siblings.data?.data ?? []).filter((field) => field.key !== record?.key).map((field) => ({ key: field.key, label: field.label }))

  const textBounds = type === 'text' || type === 'long_text'
  const defaultSent = DEFAULTABLE_TYPES.includes(type) ? toApiValue(defaultField, defaultValue, timeZone) : null
  const badKey = creating && submitted && !KEY_PATTERN.test(values.key)

  const body = () => {
    const data = {
      label: values.label.trim(),
      help: values.help.trim() || null,
      required: values.required,
      visible_roles: values.visible_roles,
      editable_roles: values.editable_roles,
      show_on_pos: values.show_on_pos,
    }
    if (creating) Object.assign(data, { entity, key: values.key.trim(), type })
    if (values.position.trim() !== '') data.position = /^\d+$/.test(values.position.trim()) ? Number(values.position.trim()) : values.position.trim()
    if (UNIQUE_TYPES.includes(type)) data.unique = values.unique
    if (BOUNDED_TYPES.includes(type)) {
      data.min = values.min === '' || values.min === null ? null : String(values.min).trim()
      data.max = values.max === '' || values.max === null ? null : String(values.max).trim()
    }
    if (type === 'text') data.pattern = values.pattern.trim() || null
    if (OPTION_TYPES.includes(type)) data.options = values.options.map((row) => ({ value: row.value.trim(), label: row.label.trim() }))
    if (type === 'lookup') data.lookup_target = values.lookup_target || null
    if (type === 'formula') Object.assign(data, { formula: values.formula.trim(), formula_type: values.formula_type })
    if (DEFAULTABLE_TYPES.includes(type)) data.default = defaultSent
    return data
  }

  const mutation = useMutation({
    mutationFn: () => (creating ? api.post('custom-fields', body()) : api.patch(`custom-fields/${record.id}`, body())),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['custom-fields'] })
      if (record) queryClient.invalidateQueries({ queryKey: ['history', 'custom_field', record.id] })
      onClose()
    },
  })
  const optionFields = values.options.flatMap((_, index) => [`options.${index}.value`, `options.${index}.label`])
  const errors = formErrors(mutation.error, [
    'label',
    'key',
    'type',
    'help',
    'default',
    'required',
    'unique',
    'min',
    'max',
    'pattern',
    'options',
    'lookup_target',
    'formula',
    'formula_type',
    'visible_roles',
    'editable_roles',
    'show_on_pos',
    'position',
    ...optionFields,
  ])
  useErrorFocus(formRef, alertRef, mutation.error)

  const roleOptions = roles.map((role) => ({ value: role.id, label: role.name }))
  const unknownRole = () => t('customFields.fields.unknownRole')
  const typeLabel = (value) => t(`customFields.types.${value}`, { defaultValue: value })

  return (
    <Dialog
      open
      size="lg"
      title={creating ? t('customFields.addTitle', { entity: entityLabel }) : t('customFields.editTitle', { label: record.label })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {creating ? t('customFields.add') : t('common.save')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          setSubmitted(true)
          if (creating && !KEY_PATTERN.test(values.key)) return
          if (defaultSent === undefined) return
          if ((values.min === null || values.max === null) && BOUNDED_TYPES.includes(type)) return
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label={t('customFields.fields.label')}
            help={t('customFields.fields.labelHelp')}
            value={values.label}
            maxLength={100}
            onChange={(event) => {
              const label = event.target.value
              setValues((current) => ({ ...current, label, key: current.keyTouched ? current.key : slugify(label) }))
            }}
            error={errors.fields.label}
            required
          />
          {creating ? (
            <TextField
              label={t('customFields.fields.key')}
              help={t('customFields.fields.keyHelp')}
              value={values.key}
              maxLength={40}
              autoComplete="off"
              spellCheck={false}
              onChange={(event) => setValues((current) => ({ ...current, key: event.target.value, keyTouched: true }))}
              error={errors.fields.key ?? (badKey ? t('customFields.fields.keyInvalid') : undefined)}
              required
            />
          ) : (
            <TextField label={t('customFields.fields.key')} help={t('customFields.fields.keyFixed')} value={values.key} disabled />
          )}
          <TextField label={t('customFields.fields.help')} help={t('customFields.fields.helpHelp')} className="sm:col-span-2" value={values.help} maxLength={255} onChange={set('help')} error={errors.fields.help} />
          {creating ? (
            <Select
              label={t('customFields.fields.type')}
              options={meta.types.map((value) => ({ value, label: typeLabel(value) }))}
              value={type}
              onChange={(event) => {
                const next = event.target.value
                setValue('type', next)
                setDefaultValue(toFormValue({ type: next }, null, timeZone))
              }}
              error={errors.fields.type}
              required
            />
          ) : (
            <TextField label={t('customFields.fields.type')} help={t('customFields.fields.typeFixed')} value={typeLabel(type)} disabled />
          )}
          <TextField
            label={t('customFields.fields.position')}
            help={t('customFields.fields.positionHelp')}
            inputMode="numeric"
            value={values.position}
            onChange={set('position')}
            error={errors.fields.position}
          />
        </div>

        <div className="flex flex-col gap-2">
          {type === 'formula' ? null : (
            <Checkbox
              label={t('customFields.fields.required')}
              help={t('customFields.fields.requiredHelp')}
              checked={values.required}
              onChange={(event) => setValue('required', event.target.checked)}
            />
          )}
          {errors.fields.required ? <p className="text-caption text-danger">{errors.fields.required}</p> : null}
          {UNIQUE_TYPES.includes(type) ? (
            <Checkbox label={t('customFields.fields.unique')} help={t('customFields.fields.uniqueHelp')} checked={values.unique} onChange={(event) => setValue('unique', event.target.checked)} />
          ) : null}
          {errors.fields.unique ? <p className="text-caption text-danger">{errors.fields.unique}</p> : null}
        </div>

        {BOUNDED_TYPES.includes(type) ? (
          <div key={type} className="grid gap-4 sm:grid-cols-2">
            {textBounds ? (
              <>
                <TextField label={t('customFields.fields.minLength')} inputMode="numeric" value={values.min ?? ''} onChange={set('min')} error={errors.fields.min} />
                <TextField label={t('customFields.fields.maxLength')} inputMode="numeric" value={values.max ?? ''} onChange={set('max')} error={errors.fields.max} />
              </>
            ) : (
              <>
                <SignedDecimalInput label={t('customFields.fields.min')} value={values.min} onChange={(min) => setValue('min', min)} showErrors={submitted} error={errors.fields.min} />
                <SignedDecimalInput label={t('customFields.fields.max')} value={values.max} onChange={(max) => setValue('max', max)} showErrors={submitted} error={errors.fields.max} />
              </>
            )}
          </div>
        ) : null}

        {type === 'text' ? (
          <TextField
            label={t('customFields.fields.pattern')}
            help={t('customFields.fields.patternHelp')}
            value={values.pattern}
            maxLength={255}
            spellCheck={false}
            autoComplete="off"
            onChange={set('pattern')}
            error={errors.fields.pattern}
          />
        ) : null}

        {OPTION_TYPES.includes(type) ? (
          <OptionsEditor options={values.options} onChange={(options) => setValue('options', options)} errors={errors.fields} error={errors.fields.options} />
        ) : null}

        {type === 'lookup' ? (
          <Select
            label={t('customFields.fields.lookupTarget')}
            help={t('customFields.fields.lookupTargetHelp')}
            placeholder={t('customFields.fields.chooseTarget')}
            options={meta.lookupTargets.map((target) => ({ value: target.key, label: target.label }))}
            value={values.lookup_target}
            onChange={set('lookup_target')}
            error={errors.fields.lookup_target}
            required
          />
        ) : null}

        {type === 'formula' ? (
          <>
            <Select
              label={t('customFields.fields.formulaType')}
              options={FORMULA_TYPES.map((value) => ({ value, label: t(`customFields.formulaTypes.${value}`) }))}
              value={values.formula_type}
              onChange={set('formula_type')}
              error={errors.fields.formula_type}
              required
            />
            <FormulaEditor
              value={values.formula}
              onChange={(formula) => setValue('formula', formula)}
              error={errors.fields.formula}
              fieldKeys={fieldKeys}
              functions={meta.functions}
              operators={meta.operators}
            />
          </>
        ) : null}

        {DEFAULTABLE_TYPES.includes(type) ? (
          <CustomFieldControl
            key={`default-${type}`}
            field={{ ...defaultField, help: t('customFields.fields.defaultHelp') }}
            entity={entity}
            value={defaultValue}
            onChange={setDefaultValue}
            error={errors.fields.default}
            showErrors={submitted}
          />
        ) : null}

        <MultiSelect
          label={t('customFields.fields.visibleRoles')}
          help={t('customFields.fields.visibleRolesHelp')}
          placeholder={t('customFields.fields.everyone')}
          options={roleOptions}
          value={values.visible_roles}
          unknownLabel={unknownRole}
          onChange={(next) => setValue('visible_roles', next)}
          error={errors.fields.visible_roles}
        />
        {type === 'formula' ? null : (
          <MultiSelect
            label={t('customFields.fields.editableRoles')}
            help={t('customFields.fields.editableRolesHelp')}
            placeholder={t('customFields.fields.everyoneWhoEdits')}
            options={roleOptions}
            value={values.editable_roles}
            unknownLabel={unknownRole}
            onChange={(next) => setValue('editable_roles', next)}
            error={errors.fields.editable_roles}
          />
        )}
        <Switch label={t('customFields.fields.showOnPos')} checked={values.show_on_pos} onChange={(next) => setValue('show_on_pos', next)} />
        {errors.fields.show_on_pos ? <p className="text-caption text-danger">{errors.fields.show_on_pos}</p> : null}
      </form>
    </Dialog>
  )
}
