import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DecimalInput, MoneyInput, PercentInput, Select } from '@/components/ds'
import { usePharmacyCompanyChoice } from '../pharmacy/pharmacyData'
import { fuelKey, SETTINGS_EDIT } from './fuelData'

const FIELDS = ['cash_variance_tolerance_minor', 'litre_tolerance', 'loss_tolerance_percent', 'loss_tolerance_litres', 'test_tolerance_litres', 'delivery_tolerance_percent']

/** FUEL-03: the company's tolerances. All start at 0 (every difference needs approval); none is built in. */
export default function SetupSettings() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const { company, companies, choose } = usePharmacyCompanyChoice()
  const key = [...fuelKey, 'settings', company?.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`companies/${company.id}/fuel-settings`), enabled: Boolean(company) })
  const [form, setForm] = useState(null)
  const [saved, setSaved] = useState(false)
  const [shown, setShown] = useState(false)
  const settings = query.data?.data
  if (settings && form?.company_id !== settings.company_id) setForm({ ...settings })

  const save = useMutation({
    mutationFn: () =>
      api.put(`companies/${company.id}/fuel-settings`, {
        cash_variance_tolerance_minor: form.cash_variance_tolerance_minor,
        litre_tolerance: form.litre_tolerance,
        loss_tolerance_percent: form.loss_tolerance_percent,
        loss_tolerance_litres: form.loss_tolerance_litres,
        test_tolerance_litres: form.test_tolerance_litres,
        delivery_tolerance_percent: form.delivery_tolerance_percent,
      }),
    onSuccess: async (response) => {
      queryClient.setQueryData(key, response)
      setSaved(true)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })

  if (!company) return <Alert tone="info" title={t('fuel.settings.noCompany')} />
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!form) return <p className="text-ink-muted">{t('common.loading')}</p>

  const editable = canWithin(SETTINGS_EDIT, [{ type: 'company', id: company.id }])
  const errors = formErrors(save.error, FIELDS)
  const set = (name) => (value) => {
    setSaved(false)
    setForm((current) => ({ ...current, [name]: value }))
  }
  const valid = FIELDS.every((name) => form[name] !== null)
  return (
    <section className="flex flex-col gap-4">
      <p className="text-body text-ink-muted">{t('fuel.settings.description', { company: company.name })}</p>
      {companies.length > 1 ? (
        <Select
          label={t('fuel.fields.company')}
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
      <Alert tone="info" title={t('fuel.settings.toConfirm')} />
      {saved ? <Alert tone="success" title={t('fuel.settings.saved')} /> : null}
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <form
        className="flex flex-col gap-6"
        onSubmit={(event) => {
          event.preventDefault()
          setShown(true)
          if (valid) save.mutate()
        }}
      >
        <Card title={t('fuel.settings.shifts')}>
          <div className="grid gap-4 md:grid-cols-2">
            <MoneyInput
              key={`cash-${company.id}`}
              label={t('fuel.settings.cashTolerance')}
              help={t('fuel.settings.cashToleranceHelp')}
              currency={company.base_currency}
              value={form.cash_variance_tolerance_minor}
              showErrors={shown}
              error={errors.fields.cash_variance_tolerance_minor}
              disabled={!editable}
              onChange={set('cash_variance_tolerance_minor')}
            />
            <DecimalInput
              label={t('fuel.settings.litreTolerance')}
              help={t('fuel.settings.litreToleranceHelp')}
              suffix={t('fuel.units.litres')}
              value={form.litre_tolerance}
              maxDecimals={3}
              positive={false}
              showErrors={shown}
              error={errors.fields.litre_tolerance}
              disabled={!editable}
              onChange={set('litre_tolerance')}
            />
          </div>
        </Card>
        <Card title={t('fuel.settings.tanks')}>
          <div className="grid gap-4 md:grid-cols-3">
            <PercentInput label={t('fuel.settings.lossPercent')} help={t('fuel.settings.lossPercentHelp')} value={form.loss_tolerance_percent} showErrors={shown} error={errors.fields.loss_tolerance_percent} disabled={!editable} onChange={set('loss_tolerance_percent')} />
            <DecimalInput
              label={t('fuel.settings.lossLitres')}
              help={t('fuel.settings.lossLitresHelp')}
              suffix={t('fuel.units.litres')}
              value={form.loss_tolerance_litres}
              maxDecimals={3}
              positive={false}
              showErrors={shown}
              error={errors.fields.loss_tolerance_litres}
              disabled={!editable}
              onChange={set('loss_tolerance_litres')}
            />
            <DecimalInput
              label={t('fuel.settings.testLitres')}
              help={t('fuel.settings.testLitresHelp')}
              suffix={t('fuel.units.litres')}
              value={form.test_tolerance_litres}
              maxDecimals={3}
              positive={false}
              showErrors={shown}
              error={errors.fields.test_tolerance_litres}
              disabled={!editable}
              onChange={set('test_tolerance_litres')}
            />
            <PercentInput label={t('fuel.settings.deliveryPercent')} help={t('fuel.settings.deliveryPercentHelp')} value={form.delivery_tolerance_percent} showErrors={shown} error={errors.fields.delivery_tolerance_percent} disabled={!editable} onChange={set('delivery_tolerance_percent')} />
          </div>
        </Card>
        {editable ? (
          <div>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
              {t('fuel.settings.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </section>
  )
}
