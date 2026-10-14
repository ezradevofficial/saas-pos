import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, PercentInput, Select } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { procurementKey, SETTINGS_EDIT } from './procurementData'

const NAMES = ['price_tolerance_percent', 'qty_tolerance_percent', 'over_receipt_percent']

/** The form for one company's tolerances; remounted when the company or its saved values change. */
function SettingsForm({ settings, companyId, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => Object.fromEntries(NAMES.map((name) => [name, settings[name] ?? '0'])))
  const save = useMutation({
    mutationFn: () => api.put(`procurement/companies/${companyId}/settings`, Object.fromEntries(NAMES.map((name) => [name, values[name] || '0']))),
    onSuccess: (response) => {
      toast.success(t('procurement.settings.saved'))
      queryClient.setQueryData([...procurementKey, 'settings', companyId], response)
    },
  })
  const errors = formErrors(save.error, NAMES)
  const changed = NAMES.some((name) => (values[name] || '0') !== settings[name])
  const valid = NAMES.every((name) => values[name] !== null)

  return (
    <Card title={settings.company?.name}>
      <div className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-3">
          {NAMES.map((name) => (
            <PercentInput
              key={name}
              label={t(`procurement.settings.${name}`)}
              help={t(`procurement.settings.help.${name}`)}
              value={values[name]}
              disabled={!canEdit}
              error={errors.fields[name]}
              onChange={(value) => setValues((current) => ({ ...current, [name]: value }))}
            />
          ))}
        </div>
        {canEdit ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={!changed || !valid} onClick={() => save.mutate()}>
              {t('procurement.settings.save')}
            </Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('procurement.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/**
 * PRC-01: a company's three-way match tolerances (unit price and
 * quantity, percent) and over-receipt allowance. The company comes from
 * the switcher, or a picker when it shows all companies; saving needs
 * procurement.settings.edit at the company.
 */
export default function ProcurementSettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const { companies } = useCompanies()
  const { company: switcherCompany } = useCompanySelection()
  const [chosen, setChosen] = useState('')
  const active = companies.filter((entry) => !entry.archived_at)
  const companyId = chosen || switcherCompany?.id || active[0]?.id || ''
  const query = useQuery({
    queryKey: [...procurementKey, 'settings', companyId],
    queryFn: () => api.get(`procurement/companies/${companyId}/settings`),
    enabled: Boolean(companyId),
  })
  const settings = query.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])

  return (
    <>
      <PageHeader title={t('procurement.settings.title')} description={t('procurement.settings.description')} />
      <div className="flex flex-col gap-5">
        {active.length > 1 ? (
          <Select
            label={t('procurement.fields.company')}
            options={active.map((entry) => ({ value: entry.id, label: entry.name }))}
            value={companyId}
            onChange={(event) => setChosen(event.target.value)}
            className="max-w-field"
          />
        ) : null}
        {!companyId ? <p className="text-ink-muted">{t('procurement.settings.noCompany')}</p> : null}
        {query.isPending && companyId ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {settings ? <SettingsForm key={`${companyId}-${NAMES.map((name) => settings[name]).join('-')}`} settings={settings} companyId={companyId} canEdit={canEdit} /> : null}
      </div>
    </>
  )
}
