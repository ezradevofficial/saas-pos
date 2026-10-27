import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ITEM_CLASSES, pharmacyKey, SETTINGS_EDIT, usePharmacyCompanyChoice } from './pharmacyData'

const NUMBER = /^\d+$/

/** A set of item classes as checkboxes: which classes need a prescription, or a pharmacist. */
function ClassChoice({ legend, help, value, onChange, disabled, error }) {
  const { t } = useTranslation()
  const toggle = (name, checked) => onChange(checked ? [...value, name] : value.filter((entry) => entry !== name))
  return (
    <fieldset className="flex flex-col gap-2">
      <legend className="text-label text-ink">{legend}</legend>
      <p className="text-caption text-ink-muted">{help}</p>
      <div className="flex flex-wrap gap-x-5 gap-y-2">
        {ITEM_CLASSES.map((name) => (
          <Checkbox key={name} label={t(`pharmacy.classes.${name}`)} checked={value.includes(name)} disabled={disabled} onChange={(event) => toggle(name, event.target.checked)} />
        ))}
      </div>
      {error ? <p className="text-caption text-danger">{error}</p> : null}
    </fieldset>
  )
}

/**
 * PHA-02: the company's pharmacy rules: the near-expiry window, the least
 * days to expiry a lot may have to be sold, which classes need a prescription
 * or a pharmacist, how long a prescription is valid, and whether expired
 * stock is quarantined each night. None of these is a regulator's number:
 * each is the pharmacy's own choice, to be confirmed against the rules it
 * works under.
 */
export default function PharmacySettings() {
  const { t } = useTranslation()
  const { company, companies, choose } = usePharmacyCompanyChoice()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const key = [...pharmacyKey, 'settings', company?.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`companies/${company.id}/pharmacy-settings`), enabled: Boolean(company) })
  const [form, setForm] = useState(null)
  const [saved, setSaved] = useState(false)
  const settings = query.data?.data
  if (settings && form?.company_id !== settings.company_id) {
    setForm({
      company_id: settings.company_id,
      near_expiry_days: String(settings.near_expiry_days),
      min_sale_days: String(settings.min_sale_days),
      prescription_classes: settings.prescription_classes,
      pharmacist_classes: settings.pharmacist_classes,
      prescription_valid_days: settings.prescription_valid_days == null ? '' : String(settings.prescription_valid_days),
      quarantine_expired: settings.quarantine_expired,
    })
  }

  const save = useMutation({
    mutationFn: () =>
      api.put(`companies/${company.id}/pharmacy-settings`, {
        near_expiry_days: Number(form.near_expiry_days),
        min_sale_days: Number(form.min_sale_days),
        prescription_classes: form.prescription_classes,
        pharmacist_classes: form.pharmacist_classes,
        prescription_valid_days: form.prescription_valid_days === '' ? null : Number(form.prescription_valid_days),
        quarantine_expired: form.quarantine_expired,
      }),
    onSuccess: async (response) => {
      queryClient.setQueryData(key, response)
      setSaved(true)
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
    },
  })

  if (!company) return <Alert tone="info" title={t('pharmacy.settings.noCompany')} />
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!form) return <p className="text-ink-muted">{t('common.loading')}</p>

  const editable = canWithin(SETTINGS_EDIT, [{ type: 'company', id: company.id }])
  const errors = formErrors(save.error, ['near_expiry_days', 'min_sale_days', 'prescription_classes', 'pharmacist_classes', 'prescription_valid_days', 'quarantine_expired'])
  const set = (name) => (value) => {
    setSaved(false)
    setForm((current) => ({ ...current, [name]: value }))
  }
  const valid = NUMBER.test(form.near_expiry_days) && NUMBER.test(form.min_sale_days) && (form.prescription_valid_days === '' || NUMBER.test(form.prescription_valid_days))

  return (
    <>
      <PageHeader title={t('pharmacy.settings.title')} description={t('pharmacy.settings.description', { company: company.name })} />
      {companies.length > 1 ? (
        <Select
          label={t('pharmacy.settings.company')}
          options={companies.map((entry) => ({ value: entry.id, label: entry.name }))}
          value={company.id}
          onChange={(event) => {
            setSaved(false)
            setForm(null)
            choose(event.target.value)
          }}
          className="max-w-sm"
        />
      ) : null}
      <Alert tone="info" title={t('pharmacy.settings.toConfirm')} />
      {saved ? <Alert tone="success" title={t('pharmacy.settings.saved')} /> : null}
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <form
        className="flex flex-col gap-6"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <Card title={t('pharmacy.settings.expiry')}>
          <div className="grid gap-4 md:grid-cols-2">
            <TextField label={t('pharmacy.settings.nearExpiryDays')} help={t('pharmacy.settings.nearExpiryDaysHelp')} suffix={t('pharmacy.settings.days')} inputMode="numeric" value={form.near_expiry_days} error={errors.fields.near_expiry_days} disabled={!editable} onChange={(event) => set('near_expiry_days')(event.target.value)} />
            <TextField label={t('pharmacy.settings.minSaleDays')} help={t('pharmacy.settings.minSaleDaysHelp')} suffix={t('pharmacy.settings.days')} inputMode="numeric" value={form.min_sale_days} error={errors.fields.min_sale_days} disabled={!editable} onChange={(event) => set('min_sale_days')(event.target.value)} />
            <Switch label={t('pharmacy.settings.quarantineExpired')} checked={form.quarantine_expired} disabled={!editable} onChange={set('quarantine_expired')} className="md:col-span-2" />
          </div>
        </Card>
        <Card title={t('pharmacy.settings.rules')}>
          <div className="flex flex-col gap-5">
            <ClassChoice legend={t('pharmacy.settings.prescriptionClasses')} help={t('pharmacy.settings.prescriptionClassesHelp')} value={form.prescription_classes} disabled={!editable} error={errors.fields.prescription_classes} onChange={set('prescription_classes')} />
            <ClassChoice legend={t('pharmacy.settings.pharmacistClasses')} help={t('pharmacy.settings.pharmacistClassesHelp')} value={form.pharmacist_classes} disabled={!editable} error={errors.fields.pharmacist_classes} onChange={set('pharmacist_classes')} />
            <TextField label={t('pharmacy.settings.validDays')} help={t('pharmacy.settings.validDaysHelp')} suffix={t('pharmacy.settings.days')} inputMode="numeric" value={form.prescription_valid_days} error={errors.fields.prescription_valid_days} disabled={!editable} onChange={(event) => set('prescription_valid_days')(event.target.value)} className="max-w-sm" />
          </div>
        </Card>
        {editable ? (
          <div>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
              {t('pharmacy.settings.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </>
  )
}
