import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, PercentInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { compareDecimal } from '@/lib/money'
import { CompanyPicker } from './RestaurantParts'
import { parsePresets, restaurantKey, SETTINGS_EDIT, useCompanyChoice, useItems } from './restaurantData'

/** The form for one company's settings; remounted when the company or its saved values change. */
function SettingsForm({ settings, companyId, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const items = useItems().filter((item) => item.type !== 'stock')
  const [percent, setPercent] = useState(String(Number(settings.service_charge_percent ?? 0)))
  const [serviceItem, setServiceItem] = useState(settings.service_charge_item_id ?? '')
  const [tipItem, setTipItem] = useState(settings.tip_item_id ?? '')
  const [presets, setPresets] = useState((settings.tip_presets ?? []).join(', '))
  const parsedPresets = parsePresets(presets)
  const percentOver = percent !== null && percent !== '' && compareDecimal(percent, '30') > 0
  const valid = percent !== null && !percentOver && (presets.trim() === '' || parsedPresets !== null)
  const save = useMutation({
    mutationFn: () =>
      api.put(`restaurant/companies/${companyId}/settings`, {
        service_charge_percent: percent === '' ? '0' : percent,
        service_charge_item_id: serviceItem || null,
        tip_item_id: tipItem || null,
        tip_presets: parsedPresets ?? [],
      }),
    onSuccess: (response) => {
      toast.success(t('restaurant.settings.saved'))
      queryClient.setQueryData([...restaurantKey, 'settings', companyId], response)
    },
  })
  const errors = formErrors(save.error, ['service_charge_percent', 'service_charge_item_id', 'tip_item_id', 'tip_presets'])
  const options = [{ value: '', label: t('restaurant.settings.noItem') }, ...items.map((item) => ({ value: String(item.id), label: item.code ? `${item.name} · ${item.code}` : item.name }))]
  return (
    <Card title={t('restaurant.settings.serviceAndTips')} subtitle={t('restaurant.settings.serviceAndTipsHelp')}>
      <div className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <PercentInput
            label={t('restaurant.settings.serviceChargePercent')}
            help={t('restaurant.settings.serviceChargeHelp')}
            value={percent}
            disabled={!canEdit}
            error={errors.fields.service_charge_percent ?? (percentOver ? t('restaurant.settings.percentTooHigh') : undefined)}
            onChange={setPercent}
          />
          <TextField
            label={t('restaurant.settings.tipPresets')}
            help={t('restaurant.settings.tipPresetsHelp')}
            value={presets}
            disabled={!canEdit}
            error={errors.fields.tip_presets ?? (presets.trim() !== '' && parsedPresets === null ? t('restaurant.settings.tipPresetsInvalid') : undefined)}
            onChange={(event) => setPresets(event.target.value)}
          />
          <Select label={t('restaurant.settings.serviceItem')} help={t('restaurant.settings.serviceItemHelp')} options={options} value={serviceItem} disabled={!canEdit} error={errors.fields.service_charge_item_id} onChange={(event) => setServiceItem(event.target.value)} />
          <Select label={t('restaurant.settings.tipItem')} help={t('restaurant.settings.tipItemHelp')} options={options} value={tipItem} disabled={!canEdit} error={errors.fields.tip_item_id} onChange={(event) => setTipItem(event.target.value)} />
        </div>
        {canEdit ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
              {t('restaurant.settings.save')}
            </Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('restaurant.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/**
 * REST-13: a company's service charge percentage (0 to 30), the core items
 * that carry the service charge and the tip on a sale, and the tip presets
 * the till offers. Saving needs restaurant.settings.edit at the company.
 */
export default function RestaurantSettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const companies = useCompanyChoice()
  const { company } = companies
  const companyId = company?.id ?? ''
  const query = useQuery({
    queryKey: [...restaurantKey, 'settings', companyId],
    queryFn: () => api.get(`restaurant/companies/${companyId}/settings`),
    enabled: Boolean(companyId),
  })
  const settings = query.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])

  return (
    <>
      <PageHeader title={t('restaurant.settings.title')} description={t('restaurant.settings.description')} />
      <div className="flex flex-col gap-5">
        <CompanyPicker choice={companies} />
        {!companyId ? <p className="text-ink-muted">{t('restaurant.noCompany')}</p> : null}
        {query.isPending && companyId ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {settings ? (
          <SettingsForm
            key={`${companyId}-${settings.service_charge_percent}-${settings.service_charge_item_id}-${settings.tip_item_id}-${(settings.tip_presets ?? []).join('.')}`}
            settings={settings}
            companyId={companyId}
            canEdit={canEdit}
          />
        ) : null}
      </div>
    </>
  )
}
