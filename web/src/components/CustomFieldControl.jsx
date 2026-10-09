import { useMutation, useQuery } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Button, Checkbox, Icon, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { CUSTOM_FILE_EXTENSIONS, CUSTOM_FILE_TYPES, formulaText, MAX_CUSTOM_FILE_BYTES } from '@/lib/customFields'
import { formatBytes } from '@/lib/format'
import { formatDecimal, parseDecimal } from '@/lib/money'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { useDebounced } from '@/lib/useDebounced'
import { useLocale } from '@/lib/useLocale'
import { TextAreaField } from '@/pages/approvals/TextAreaField'

/**
 * A decimal that may be negative, typed in the UI language ("-12,5" in
 * French). Reports the decimal string, '' when empty, or null while the
 * text is not a number (the reason shows once the field is left, or at
 * once with `showErrors`).
 */
export function SignedDecimalInput({ value, onChange, showErrors, error, maxDecimals = 8, ...rest }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [text, setText] = useState(() => (value === '' || value == null ? '' : formatDecimal(value, locale)))
  const [touched, setTouched] = useState(false)
  const read = (input) => {
    const trimmed = String(input).trim()
    const negative = trimmed.startsWith('-')
    const result = parseDecimal(negative ? trimmed.slice(1) : trimmed, { locale, maxDecimals, maxIntegerDigits: 18 })
    if (result.error) return result
    if (negative && result.value === '') return { value: '', error: 'format' }
    return { value: negative && result.value !== '' ? `-${result.value}` : result.value, error: null }
  }
  const parsed = read(text)
  const own =
    (touched || showErrors) && parsed.error
      ? t(`ds.numberInput.${parsed.error}`, { example: formatDecimal('-12.5', locale), count: maxDecimals })
      : null
  return (
    <TextField
      {...rest}
      inputMode="decimal"
      autoComplete="off"
      value={text}
      error={error || own || undefined}
      onChange={(event) => {
        setText(event.target.value)
        const result = read(event.target.value)
        onChange?.(result.error ? null : result.value)
      }}
      onBlur={() => setTouched(true)}
    />
  )
}

/** A custom file: upload (POST custom-field-files), then the file's name with a link, and Remove. */
function FileControl({ field, entity, value, onChange, disabled, error, label, help }) {
  const { t } = useTranslation()
  const id = useId()
  const locale = useLocale()
  const inputRef = useRef(null)
  const [localError, setLocalError] = useState(null)
  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('entity', entity)
      form.append('field', field.key)
      form.append('file', file)
      return api.upload('custom-field-files', form)
    },
    onSuccess: (response) => onChange(response.data),
  })
  const choose = (file) => {
    setLocalError(null)
    upload.reset()
    if (!file) return
    const extension = `.${String(file.name).split('.').pop()}`.toLowerCase()
    if (!CUSTOM_FILE_TYPES.includes(file.type) && !CUSTOM_FILE_EXTENSIONS.includes(extension)) return setLocalError(t('customFields.file.wrongType'))
    if (file.size > MAX_CUSTOM_FILE_BYTES) return setLocalError(t('customFields.file.tooLarge', { max: formatBytes(MAX_CUSTOM_FILE_BYTES, locale) }))
    upload.mutate(file)
  }
  const failure = error || localError || (upload.error ? errorMessage(upload.error) : null)

  return (
    <Field id={id} label={label} help={help} error={failure}>
      <div className="flex flex-wrap items-center gap-2">
        {value ? (
          <>
            <Icon name="attach" className="text-ink-muted" />
            {value.url ? (
              <a href={value.url} target="_blank" rel="noopener noreferrer" className="font-medium text-primary hover:text-primary-hover">
                {value.name}
              </a>
            ) : (
              <span className="font-medium text-ink">{value.name}</span>
            )}
            {value.size != null ? <span className="text-caption text-ink-muted">{formatBytes(value.size, locale)}</span> : null}
          </>
        ) : (
          <span className="text-ink-muted">{t('customFields.file.none')}</span>
        )}
        {disabled ? null : (
          <>
            <input
              ref={inputRef}
              id={id}
              type="file"
              accept={[...CUSTOM_FILE_TYPES, ...CUSTOM_FILE_EXTENSIONS].join(',')}
              className="sr-only"
              tabIndex={-1}
              aria-label={t('customFields.file.choose', { label })}
              onChange={(event) => {
                choose(event.target.files?.[0])
                event.target.value = ''
              }}
            />
            <Button variant="ghost" icon="attach" loading={upload.isPending} onClick={() => inputRef.current?.click()}>
              {value ? t('customFields.file.replace') : t('customFields.file.upload')}
            </Button>
            {value ? (
              <Button variant="ghost" icon="remove" onClick={() => onChange(null)} aria-label={t('customFields.file.removeName', { name: value.name })}>
                {t('customFields.file.remove')}
              </Button>
            ) : null}
          </>
        )}
      </div>
    </Field>
  )
}

/** A record of another kind (`lookup_target`), searched on the server (GET custom-fields/lookup). */
function LookupControl({ field, value, onChange, disabled, error, label, help, required }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const term = useDebounced(search.trim(), 300)
  const results = useQuery({
    queryKey: ['custom-fields', 'lookup', field.lookup_target, term],
    queryFn: () => api.get(`custom-fields/lookup?target=${encodeURIComponent(field.lookup_target ?? '')}&search=${encodeURIComponent(term)}`),
    enabled: !disabled && Boolean(field.lookup_target),
    staleTime: 30_000,
  })
  const found = results.data?.data ?? []
  const options = [
    { value: '', label: t('customFields.none') },
    ...(value && !found.some((entry) => entry.id === value.id) ? [{ value: value.id, label: value.label ?? value.id }] : []),
    ...found.map((entry) => ({ value: entry.id, label: entry.label })),
  ]
  return (
    <Select
      label={label}
      help={help}
      error={error}
      required={required}
      disabled={disabled}
      placeholder={t('customFields.lookupPlaceholder')}
      options={options}
      value={value?.id ?? ''}
      onSearchChange={setSearch}
      onChange={(event) => {
        const id = event.target.value
        onChange(id ? { id, label: options.find((option) => option.value === id)?.label ?? id } : null)
      }}
    />
  )
}

/** Money: the currency (the record's own, else the company's base) and the amount in minor units. */
function MoneyControl({ value, onChange, disabled, error, label, help, required, showErrors }) {
  const { t } = useTranslation()
  const defaults = useMoneyDefaults()
  const currency = value?.currency || defaults.currency
  const codes = [...new Set([...defaults.options, currency].filter(Boolean))]
  return (
    <div className="grid gap-3 sm:grid-cols-3">
      <Select
        label={t('customFields.currency', { label })}
        options={codes.map((code) => ({ value: code, label: code }))}
        value={currency}
        disabled={disabled}
        // The amount is in minor units of the old currency: cleared, never reinterpreted.
        onChange={(event) => onChange({ amount_minor: '', currency: event.target.value })}
      />
      <MoneyInput
        key={currency}
        className="sm:col-span-2"
        label={label}
        help={help}
        required={required}
        disabled={disabled}
        currency={currency}
        value={value?.amount_minor ?? ''}
        showErrors={showErrors}
        error={error}
        onChange={(amount_minor) => onChange({ amount_minor, currency })}
      />
    </div>
  )
}

/**
 * One custom field's control by type (CF-01, CF-02): `value` as
 * toFormValue holds it, `onChange(next)` in the same shape. Read-only
 * fields (a formula, a field the user may not edit, RBAC-05) are drawn
 * disabled. `entity` is needed for file uploads.
 */
export function CustomFieldControl({ field, entity, value, onChange, disabled = false, error, showErrors = false, className, label: labelOverride }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const label = labelOverride ?? field.label
  const help = field.help || undefined
  const required = Boolean(field.required)
  const locked = disabled || Boolean(field.readonly) || field.type === 'formula'
  const common = { label, help, error, required, disabled: locked }
  const textBounds = {
    minLength: field.min != null && field.min !== '' ? Number(field.min) : undefined,
    maxLength: field.max != null && field.max !== '' ? Number(field.max) : undefined,
  }

  const control = (() => {
    switch (field.type) {
      case 'text':
        return <TextField {...common} {...textBounds} value={value ?? ''} onChange={(event) => onChange(event.target.value)} autoComplete="off" />
      case 'long_text':
        return <TextAreaField {...common} {...textBounds} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
      case 'number':
        return <SignedDecimalInput {...common} value={value ?? ''} onChange={onChange} showErrors={showErrors} />
      case 'money':
        return <MoneyControl {...common} value={value} onChange={onChange} showErrors={showErrors} />
      case 'date':
        return <TextField {...common} type="date" value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
      case 'datetime':
        return <TextField {...common} type="datetime-local" value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
      case 'boolean':
        return (
          <div className="flex h-full items-end pb-2">
            <Checkbox label={label} help={error ? undefined : help} checked={Boolean(value)} disabled={locked} onChange={(event) => onChange(event.target.checked)} />
          </div>
        )
      case 'select':
        return (
          <Select
            {...common}
            options={[{ value: '', label: t('customFields.none') }, ...(field.options ?? []).map((option) => ({ value: String(option.value), label: option.label }))]}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
          />
        )
      case 'multi_select':
        return (
          <MultiSelect
            {...common}
            placeholder={t('customFields.choose')}
            options={(field.options ?? []).map((option) => ({ value: String(option.value), label: option.label }))}
            value={Array.isArray(value) ? value : []}
            onChange={onChange}
          />
        )
      case 'file':
        return <FileControl {...common} field={field} entity={entity} value={value} onChange={onChange} />
      case 'lookup':
        return <LookupControl {...common} field={field} value={value} onChange={onChange} />
      case 'formula':
        return <TextField {...common} value={formulaText(field, value, { t, locale })} readOnly help={help ?? t('customFields.formulaHelp')} />
      default:
        return null
    }
  })()

  return (
    <div className={className}>
      {control}
      {field.type === 'boolean' && error ? <p className="text-caption text-danger">{error}</p> : null}
    </div>
  )
}
