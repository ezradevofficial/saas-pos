import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog, Select, TextField } from '@/components/ds'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { orgErrorMessage } from './orgErrors'
import { invalidateOrganisation, LOCATION_TYPES } from './orgTree'

const COUNTRIES = ['KE', 'CD']

const FIELDS = {
  company: ['name', 'legal_name', 'country', 'tax_id'],
  branch: ['name', 'code'],
  location: ['name', 'type', 'code'],
}

function initialValues(level, record) {
  if (level === 'company') {
    return { name: record?.name ?? '', legal_name: record?.legal_name ?? '', country: record?.country ?? 'KE', tax_id: record?.tax_id ?? '' }
  }
  if (level === 'branch') return { name: record?.name ?? '', code: record?.code ?? '' }
  return { name: record?.name ?? '', type: record?.type ?? 'outlet', code: record?.code ?? '' }
}

function payload(level, values, editing) {
  if (level === 'company') {
    const body = { name: values.name.trim(), legal_name: values.legal_name.trim() || null, tax_id: values.tax_id.trim() || null }
    // The country (and the currency and time zone it sets) is chosen once, at creation.
    if (!editing) body.country = values.country
    if (editing && body.legal_name === null) delete body.legal_name
    return body
  }
  if (level === 'branch') return { name: values.name.trim(), code: values.code.trim() }
  // NUM-01: printed in receipt numbers as {LOCATION}; emptied, it is cleared.
  const body = { name: values.name.trim(), type: values.type }
  const code = values.code.trim()
  if (code) body.code = code
  else if (editing) body.code = null
  return body
}

function endpoint(level, record, parent) {
  if (record) return { method: 'patch', path: `${level === 'company' ? 'companies' : level === 'branch' ? 'branches' : 'locations'}/${record.id}` }
  if (level === 'company') return { method: 'post', path: 'companies' }
  if (level === 'branch') return { method: 'post', path: `companies/${parent.id}/branches` }
  return { method: 'post', path: `branches/${parent.id}/locations` }
}

/**
 * Create or edit a company, branch or location (TEN-03..TEN-05). `record`
 * is set when editing; `parent` is the company (for a branch) or branch
 * (for a location) when creating.
 */
export function RecordDialog({ level, record, parent, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const editing = Boolean(record)
  const [values, setValues] = useState(() => initialValues(level, record))
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  const mutation = useMutation({
    mutationFn: () => {
      const { method, path } = endpoint(level, record, parent)
      return api[method](path, payload(level, values, editing))
    },
    onSuccess: async () => {
      await invalidateOrganisation(queryClient)
      onClose()
    },
  })
  const errors = formErrors(mutation.error, FIELDS[level])
  const formError = errors.form ? orgErrorMessage(mutation.error, level) : null
  useErrorFocus(formRef, alertRef, mutation.error)

  const title = editing
    ? t(`organisation.${level}.editTitle`, { name: record.name })
    : parent
      ? t(`organisation.${level}.addTitleIn`, { parent: parent.name })
      : t(`organisation.${level}.addTitle`)

  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {editing ? t('common.save') : t(`organisation.${level}.add`)}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="flex flex-col gap-4 pt-1"
      >
        {formError ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={formError} />
          </div>
        ) : null}
        <TextField label={t('organisation.fields.name')} value={values.name} onChange={set('name')} error={errors.fields.name} required />
        {level === 'company' ? (
          <>
            <TextField
              label={t('organisation.fields.legalName')}
              help={t('organisation.fields.legalNameHelp')}
              value={values.legal_name}
              onChange={set('legal_name')}
              error={errors.fields.legal_name}
            />
            {editing ? null : (
              <Select
                label={t('organisation.fields.country')}
                help={t('organisation.fields.countryHelp')}
                options={COUNTRIES.map((code) => ({ value: code, label: t(`auth.countries.${code}`) }))}
                value={values.country}
                onChange={set('country')}
                error={errors.fields.country}
                required
              />
            )}
            <TextField label={t('organisation.fields.taxId')} value={values.tax_id} onChange={set('tax_id')} error={errors.fields.tax_id} />
          </>
        ) : null}
        {level === 'branch' ? (
          <TextField
            label={t('organisation.fields.code')}
            help={t('organisation.fields.codeHelp')}
            value={values.code}
            onChange={set('code')}
            error={errors.fields.code}
            maxLength={20}
            autoCapitalize="characters"
            required
          />
        ) : null}
        {level === 'location' ? (
          <Select
            label={t('organisation.fields.type')}
            options={LOCATION_TYPES.map((type) => ({ value: type, label: t(`organisation.locationTypes.${type}`) }))}
            value={values.type}
            onChange={set('type')}
            error={errors.fields.type}
            required
          />
        ) : null}
        {level === 'location' ? (
          <TextField
            label={t('organisation.fields.locationCode')}
            help={t('organisation.fields.locationCodeHelp')}
            value={values.code}
            onChange={(event) => setValues((current) => ({ ...current, code: event.target.value.toUpperCase() }))}
            error={errors.fields.code}
            maxLength={10}
            autoCapitalize="characters"
            className="font-mono"
          />
        ) : null}
      </form>
    </Dialog>
  )
}
