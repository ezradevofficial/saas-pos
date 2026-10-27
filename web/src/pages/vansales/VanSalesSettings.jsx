import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanyChoice } from '@/pages/restaurant/restaurantData'
import { GPS_MODES, SETTINGS_EDIT, vanSalesKey } from './vansalesData'

/** The form for one company's settings; remounted when the company or its saved values change. */
function SettingsForm({ settings, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [gps, setGps] = useState(settings.gps_checkin ?? 'off')
  const [days, setDays] = useState(String(settings.gps_retention_days ?? 30))
  const [flagOffline, setFlagOffline] = useState(Boolean(settings.flag_offline_credit))
  const retention = /^\d{1,2}$/.test(days.trim()) ? Number(days.trim()) : null
  const valid = retention !== null && retention >= 1 && retention <= 90
  const save = useMutation({
    mutationFn: () => api.put(`van-sales/companies/${settings.company_id}/settings`, { gps_checkin: gps, gps_retention_days: retention, flag_offline_credit: flagOffline }),
    onSuccess: (response) => {
      toast.success(t('vansales.settings.saved'))
      queryClient.setQueryData([...vanSalesKey, 'settings', settings.company_id], response)
    },
  })
  const errors = formErrors(save.error, ['gps_checkin', 'gps_retention_days', 'flag_offline_credit'])
  return (
    <div className="flex flex-col gap-5">
      <Card title={t('vansales.settings.gpsTitle')} subtitle={t('vansales.settings.gpsHelp')}>
        <div className="flex flex-col gap-4">
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Select label={t('vansales.settings.gpsMode')} options={GPS_MODES.map((mode) => ({ value: mode, label: t(`vansales.settings.gps.${mode}`) }))} value={gps} disabled={!canEdit} error={errors.fields.gps_checkin} onChange={(event) => setGps(event.target.value)} className="max-w-field" />
          <TextField
            label={t('vansales.settings.retention')}
            help={t('vansales.settings.retentionHelp')}
            inputMode="numeric"
            value={days}
            disabled={!canEdit}
            error={errors.fields.gps_retention_days ?? (valid ? undefined : t('vansales.settings.retentionInvalid'))}
            onChange={(event) => setDays(event.target.value)}
            className="max-w-field"
          />
          <p className="text-caption text-ink-muted">{t('vansales.settings.privacy')}</p>
        </div>
      </Card>
      <Card title={t('vansales.settings.creditTitle')} subtitle={t('vansales.settings.creditHelp')}>
        <Switch label={t('vansales.settings.flagOffline')} checked={flagOffline} disabled={!canEdit} onChange={setFlagOffline} />
      </Card>
      {canEdit ? (
        <div>
          <Button variant="primary" loading={save.isPending} disabled={!valid} onClick={() => save.mutate()}>
            {t('vansales.settings.save')}
          </Button>
        </div>
      ) : (
        <p className="text-caption text-ink-muted">{t('vansales.settings.readOnly')}</p>
      )}
    </div>
  )
}

/** VAN-24, VAN-11: a company's GPS check-in, its retention and the offline credit flag. */
export default function VanSalesSettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const choice = useCompanyChoice()
  const companyId = choice.company?.id ?? ''
  const query = useQuery({ queryKey: [...vanSalesKey, 'settings', companyId], queryFn: () => api.get(`van-sales/companies/${companyId}/settings`), enabled: Boolean(companyId) })
  const settings = query.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])
  return (
    <>
      <PageHeader title={t('vansales.settings.title')} description={t('vansales.settings.description')} />
      <div className="flex flex-col gap-5">
        {choice.companies.length > 1 ? (
          <Select label={t('vansales.fields.company')} options={choice.companies.map((entry) => ({ value: entry.id, label: entry.name }))} value={companyId} onChange={(event) => choice.choose(event.target.value)} className="max-w-field" />
        ) : null}
        {!companyId ? <p className="text-ink-muted">{t('vansales.settings.noCompany')}</p> : null}
        {query.isPending && companyId ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {settings ? <SettingsForm key={`${companyId}-${settings.gps_checkin}-${settings.gps_retention_days}-${settings.flag_offline_credit}`} settings={settings} canEdit={canEdit} /> : null}
      </div>
    </>
  )
}
