import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { manufacturingKey, SETTINGS_EDIT } from './manufacturingData'

/** The form for one company's settings; remounted when the company or its saved values change. */
function SettingsForm({ settings, companyId, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const currency = settings.currency ?? settings.company?.base_currency ?? ''
  const [labour, setLabour] = useState(settings.labour_rate_minor ?? '0')
  const [overhead, setOverhead] = useState(settings.overhead_rate_minor ?? '0')
  const [approval, setApproval] = useState(Boolean(settings.release_approval))
  const save = useMutation({
    mutationFn: () => api.put(`manufacturing/companies/${companyId}/settings`, { labour_rate_minor: labour || '0', overhead_rate_minor: overhead || '0', release_approval: approval }),
    onSuccess: (response) => {
      toast.success(t('manufacturing.settings.saved'))
      queryClient.setQueryData([...manufacturingKey, 'settings', companyId], response)
    },
  })
  const errors = formErrors(save.error, ['labour_rate_minor', 'overhead_rate_minor', 'release_approval'])
  const valid = labour !== null && overhead !== null
  const changed = labour !== settings.labour_rate_minor || overhead !== settings.overhead_rate_minor || approval !== Boolean(settings.release_approval)

  return (
    <Card title={settings.company?.name} subtitle={t('manufacturing.settings.currency', { currency })}>
      <div className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          {[
            ['labour_rate_minor', 'labourRate', labour, setLabour],
            ['overhead_rate_minor', 'overheadRate', overhead, setOverhead],
          ].map(([field, label, value, set]) =>
            // RBAC-05: a reader without costs gets the rates as null: shown as hidden, never as zero.
            settings[field] === null || settings[field] === undefined ? (
              <TextField key={field} label={t(`manufacturing.settings.${label}`)} value={t('manufacturing.settings.hidden')} disabled readOnly />
            ) : (
              <MoneyInput
                key={field}
                label={t(`manufacturing.settings.${label}`)}
                help={t('manufacturing.settings.rateHelp')}
                currency={currency}
                value={value}
                disabled={!canEdit}
                error={errors.fields[field]}
                onChange={(next) => set(next === '' ? '0' : next)}
              />
            ),
          )}
        </div>
        <div className="flex flex-col gap-1">
          <Switch label={t('manufacturing.settings.releaseApproval')} checked={approval} disabled={!canEdit} onChange={setApproval} />
          <p className="text-caption text-ink-muted">{t('manufacturing.settings.releaseApprovalHelp')}</p>
        </div>
        {canEdit ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={!changed || !valid} onClick={() => save.mutate()}>
              {t('manufacturing.settings.save')}
            </Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('manufacturing.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/**
 * MFG-29: a company's labour and overhead rates per labour hour (typed in
 * major units of its base currency, sent in minor units) and whether a
 * production order needs approval to be released. The company comes from
 * the switcher, or a picker when it shows all companies; saving needs
 * manufacturing.settings.edit at the company.
 */
export default function ManufacturingSettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const { companies } = useCompanies()
  const { company: switcherCompany } = useCompanySelection()
  const [chosen, setChosen] = useState('')
  const active = companies.filter((entry) => !entry.archived_at)
  const companyId = chosen || switcherCompany?.id || active[0]?.id || ''
  const query = useQuery({
    queryKey: [...manufacturingKey, 'settings', companyId],
    queryFn: () => api.get(`manufacturing/companies/${companyId}/settings`),
    enabled: Boolean(companyId),
  })
  const settings = query.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])

  return (
    <>
      <PageHeader title={t('manufacturing.settings.title')} description={t('manufacturing.settings.description')} />
      <div className="flex flex-col gap-5">
        {active.length > 1 ? (
          <Select
            label={t('manufacturing.fields.company')}
            options={active.map((entry) => ({ value: entry.id, label: entry.name }))}
            value={companyId}
            onChange={(event) => setChosen(event.target.value)}
            className="max-w-field"
          />
        ) : null}
        {!companyId ? <p className="text-ink-muted">{t('manufacturing.settings.noCompany')}</p> : null}
        {query.isPending && companyId ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {settings ? (
          <SettingsForm
            key={`${companyId}-${settings.labour_rate_minor}-${settings.overhead_rate_minor}-${settings.release_approval}`}
            settings={settings}
            companyId={companyId}
            canEdit={canEdit}
          />
        ) : null}
      </div>
    </>
  )
}
