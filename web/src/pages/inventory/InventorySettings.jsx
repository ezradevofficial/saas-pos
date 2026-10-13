import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Select, Switch } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { COSTING_METHODS, inventoryKey, SETTINGS_EDIT } from './inventoryData'

/** The form for one company's settings; remounted when the company or its saved values change. */
function SettingsForm({ settings, companyId, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [method, setMethod] = useState(settings.costing_method)
  const [allowNegative, setAllowNegative] = useState(settings.allow_negative)
  const save = useMutation({
    mutationFn: () => api.put(`inventory/companies/${companyId}/settings`, { costing_method: method, allow_negative: allowNegative }),
    onSuccess: async (response) => {
      toast.success(t('inventory.settings.saved'))
      queryClient.setQueryData([...inventoryKey, 'settings', companyId], response)
    },
  })
  const changed = method !== settings.costing_method || allowNegative !== settings.allow_negative

  return (
    <Card title={settings.company?.name} subtitle={t('inventory.settings.baseCurrency', { currency: settings.company?.base_currency ?? '' })}>
      <div className="flex flex-col gap-5">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        {settings.costing_locked ? <Alert tone="info" title={t('inventory.settings.locked')} /> : null}
        <Select
          label={t('inventory.settings.costingMethod')}
          options={COSTING_METHODS.map((value) => ({ value, label: t(`inventory.settings.methods.${value}`) }))}
          value={method}
          disabled={!canEdit || settings.costing_locked}
          help={settings.costing_locked ? t('inventory.settings.lockedHelp') : t(`inventory.settings.methodHelp.${method}`)}
          onChange={(event) => setMethod(event.target.value)}
          className="max-w-field"
        />
        <div className="flex flex-col gap-1">
          <Switch label={t('inventory.settings.allowNegative')} checked={allowNegative} disabled={!canEdit} onChange={setAllowNegative} />
          <p className="text-caption text-ink-muted">{t('inventory.settings.allowNegativeHelp')}</p>
        </div>
        {canEdit ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={!changed} onClick={() => save.mutate()}>
              {t('inventory.settings.save')}
            </Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('inventory.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/**
 * INV-12, INV-13: a company's costing method (FIFO or weighted average,
 * fixed once the company has a movement) and whether manual issues may
 * take stock below zero. The company comes from the switcher, or a picker
 * when the switcher shows all companies; saving needs
 * inventory.settings.edit at the company.
 */
export default function InventorySettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const { companies } = useCompanies()
  const { company: switcherCompany } = useCompanySelection()
  const [chosen, setChosen] = useState('')
  const active = companies.filter((entry) => !entry.archived_at)
  const companyId = chosen || switcherCompany?.id || active[0]?.id || ''
  const query = useQuery({
    queryKey: [...inventoryKey, 'settings', companyId],
    queryFn: () => api.get(`inventory/companies/${companyId}/settings`),
    enabled: Boolean(companyId),
  })
  const settings = query.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])

  return (
    <>
      <PageHeader title={t('inventory.settings.title')} description={t('inventory.settings.description')} />
      <div className="flex flex-col gap-5">
        {active.length > 1 ? (
          <Select
            label={t('inventory.fields.company')}
            options={active.map((entry) => ({ value: entry.id, label: entry.name }))}
            value={companyId}
            onChange={(event) => setChosen(event.target.value)}
            className="max-w-field"
          />
        ) : null}
        {!companyId ? <p className="text-ink-muted">{t('inventory.settings.noCompany')}</p> : null}
        {query.isPending && companyId ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {settings ? <SettingsForm key={`${companyId}-${settings.costing_method}-${settings.allow_negative}`} settings={settings} companyId={companyId} canEdit={canEdit} /> : null}
      </div>
    </>
  )
}
