import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, PercentInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { usePlaces } from '@/pages/inventory/inventoryData'
import { PlaceBar, QueryNotes } from './SalonParts'
import { ONLINE_MANAGE, SETTINGS_EDIT, salonError, salonKey, useItems, useSalonPlace } from './salonData'

const FIELDS = ['slot_minutes', 'cancel_window_hours', 'late_cancel_fee_percent', 'no_show_fee_percent', 'deposit_percent', 'hold_minutes', 'reminder_hours', 'guest_retention_days', 'tip_item_id', 'fee_item_id', 'commission_item_code']
const SLOT_OPTIONS = [5, 10, 15, 20, 30, 60]

/** SAL-10, SAL-11, SAL-14, SAL-16, SAL-26: the company's rules for fees, deposits, reminders, the tip and fee items and the payroll item. */
function CompanyForm({ settings, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const items = useItems()
  const [v, setV] = useState({
    slot_minutes: String(settings.slot_minutes),
    cancel_window_hours: String(settings.cancel_window_hours),
    late_cancel_fee_percent: String(settings.late_cancel_fee_percent),
    no_show_fee_percent: String(settings.no_show_fee_percent),
    deposit_percent: String(settings.deposit_percent),
    deposit_online_required: Boolean(settings.deposit_online_required),
    hold_minutes: String(settings.hold_minutes),
    reminder_hours: String(settings.reminder_hours),
    guest_retention_days: String(settings.guest_retention_days),
    tip_item_id: settings.tip_item_id ?? '',
    fee_item_id: settings.fee_item_id ?? '',
    commission_item_code: settings.commission_item_code ?? '',
  })
  const set = (field) => (event) => setV((current) => ({ ...current, [field]: event.target.value }))
  const percent = (field) => (next) => setV((current) => ({ ...current, [field]: next ?? '' }))
  const save = useMutation({
    mutationFn: () =>
      api.put(`salon/companies/${settings.company_id}/settings`, {
        slot_minutes: Number(v.slot_minutes),
        cancel_window_hours: Number(v.cancel_window_hours),
        late_cancel_fee_percent: v.late_cancel_fee_percent || '0',
        no_show_fee_percent: v.no_show_fee_percent || '0',
        deposit_percent: v.deposit_percent || '0',
        deposit_online_required: v.deposit_online_required,
        hold_minutes: Number(v.hold_minutes),
        reminder_hours: Number(v.reminder_hours),
        guest_retention_days: Number(v.guest_retention_days),
        tip_item_id: v.tip_item_id || null,
        fee_item_id: v.fee_item_id || null,
        commission_item_code: v.commission_item_code.trim() || null,
      }),
    onSuccess: (response) => {
      toast.success(t('salon.settings.saved'))
      queryClient.setQueryData([...salonKey, 'settings', settings.company_id], response)
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const itemOptions = [{ value: '', label: t('salon.settings.noItem') }, ...items.map((item) => ({ value: item.id, label: item.name }))]
  const number = (field, label, help) => <TextField label={label} help={help} inputMode="numeric" value={v[field]} disabled={!canEdit} error={errors.fields[field]} onChange={set(field)} />
  return (
    <div className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card title={t('salon.settings.diaryTitle')} subtitle={t('salon.settings.diaryHelp')}>
        <div className="grid max-w-prose grid-cols-2 gap-4">
          <Select label={t('salon.settings.slot')} options={SLOT_OPTIONS.map((minutes) => ({ value: String(minutes), label: t('salon.services.minutes', { count: minutes }) }))} value={v.slot_minutes} disabled={!canEdit} onChange={set('slot_minutes')} />
          {number('reminder_hours', t('salon.settings.reminderHours'), t('salon.settings.reminderHelp'))}
        </div>
      </Card>
      <Card title={t('salon.settings.feesTitle')} subtitle={t('salon.settings.feesHelp')}>
        <div className="grid max-w-prose grid-cols-2 gap-4">
          {number('cancel_window_hours', t('salon.settings.cancelWindow'))}
          <PercentInput label={t('salon.settings.lateFee')} value={v.late_cancel_fee_percent} disabled={!canEdit} error={errors.fields.late_cancel_fee_percent} onChange={percent('late_cancel_fee_percent')} />
          <PercentInput label={t('salon.settings.noShowFee')} value={v.no_show_fee_percent} disabled={!canEdit} error={errors.fields.no_show_fee_percent} onChange={percent('no_show_fee_percent')} />
        </div>
      </Card>
      <Card title={t('salon.settings.depositTitle')} subtitle={t('salon.settings.depositHelp')}>
        <div className="flex max-w-prose flex-col gap-4">
          <PercentInput label={t('salon.settings.depositPercent')} value={v.deposit_percent} disabled={!canEdit} error={errors.fields.deposit_percent} onChange={percent('deposit_percent')} />
          <Switch label={t('salon.settings.depositOnline')} checked={v.deposit_online_required} disabled={!canEdit} onChange={(checked) => setV((current) => ({ ...current, deposit_online_required: checked }))} />
          {number('hold_minutes', t('salon.settings.holdMinutes'), t('salon.settings.holdHelp'))}
        </div>
      </Card>
      <Card title={t('salon.settings.checkoutTitle')} subtitle={t('salon.settings.checkoutHelp')}>
        <div className="grid max-w-prose grid-cols-2 gap-4">
          <Select label={t('salon.settings.tipItem')} options={itemOptions} value={v.tip_item_id} disabled={!canEdit} error={errors.fields.tip_item_id} onChange={set('tip_item_id')} />
          <Select label={t('salon.settings.feeItem')} options={itemOptions} value={v.fee_item_id} disabled={!canEdit} error={errors.fields.fee_item_id} onChange={set('fee_item_id')} />
          <TextField label={t('salon.settings.commissionCode')} help={t('salon.settings.commissionCodeHelp')} value={v.commission_item_code} disabled={!canEdit} error={errors.fields.commission_item_code} onChange={set('commission_item_code')} />
        </div>
      </Card>
      <Card title={t('salon.settings.privacyTitle')} subtitle={t('salon.settings.privacyHelp')}>
        <div className="max-w-prose">{number('guest_retention_days', t('salon.settings.retention'))}</div>
      </Card>
      {canEdit ? (
        <div>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {t('salon.settings.save')}
          </Button>
        </div>
      ) : (
        <p className="text-caption text-ink-muted">{t('salon.settings.readOnly')}</p>
      )}
    </div>
  )
}

/** SAL-15: a branch's public booking page. */
function OnlineForm({ page, branchId, canEdit, locations }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [v, setV] = useState({ enabled: Boolean(page.enabled), public_name: page.public_name ?? '', lead_minutes: String(page.lead_minutes), max_days_ahead: String(page.max_days_ahead), location_id: page.location_id ?? '' })
  const key = [...salonKey, 'online', branchId]
  const save = useMutation({
    mutationFn: () => api.put(`salon/branches/${branchId}/online`, { enabled: v.enabled, public_name: v.public_name.trim() || null, lead_minutes: Number(v.lead_minutes), max_days_ahead: Number(v.max_days_ahead), location_id: v.location_id || null }),
    onSuccess: (response) => {
      toast.success(t('salon.online.saved'))
      queryClient.setQueryData(key, response)
    },
  })
  const regenerate = useMutation({
    mutationFn: () => api.post(`salon/branches/${branchId}/online/regenerate-code`, {}),
    onSuccess: (response) => {
      toast.success(t('salon.online.regenerated'))
      queryClient.setQueryData(key, response)
    },
  })
  const errors = formErrors(save.error, ['public_name', 'lead_minutes', 'max_days_ahead', 'location_id'])
  const link = `${window.location.origin}${page.path}`
  return (
    <Card title={t('salon.online.title')} subtitle={t('salon.online.help')}>
      <div className="flex max-w-prose flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {regenerate.isError ? <Alert tone="danger" title={salonError(regenerate.error)} /> : null}
        <Switch label={t('salon.online.enabled')} checked={v.enabled} disabled={!canEdit} onChange={(checked) => setV((current) => ({ ...current, enabled: checked }))} />
        <TextField label={t('salon.online.publicName')} help={t('salon.online.publicNameHelp')} value={v.public_name} disabled={!canEdit} error={errors.fields.public_name} onChange={(event) => setV((current) => ({ ...current, public_name: event.target.value }))} />
        <div className="grid grid-cols-2 gap-4">
          <TextField label={t('salon.online.lead')} help={t('salon.online.leadHelp')} inputMode="numeric" value={v.lead_minutes} disabled={!canEdit} error={errors.fields.lead_minutes} onChange={(event) => setV((current) => ({ ...current, lead_minutes: event.target.value }))} />
          <TextField label={t('salon.online.maxDays')} inputMode="numeric" value={v.max_days_ahead} disabled={!canEdit} error={errors.fields.max_days_ahead} onChange={(event) => setV((current) => ({ ...current, max_days_ahead: event.target.value }))} />
        </div>
        <Select label={t('salon.online.location')} help={t('salon.online.locationHelp')} options={[{ value: '', label: t('salon.online.noLocation') }, ...locations.map((location) => ({ value: location.id, label: location.name }))]} value={v.location_id} disabled={!canEdit} error={errors.fields.location_id} onChange={(event) => setV((current) => ({ ...current, location_id: event.target.value }))} />
        <TextField label={t('salon.online.link')} help={t('salon.online.linkHelp')} value={link} readOnly onFocus={(event) => event.target.select()} />
        {canEdit ? (
          <div className="flex gap-2">
            <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
              {t('salon.online.save')}
            </Button>
            <Button loading={regenerate.isPending} onClick={() => regenerate.mutate()}>
              {t('salon.online.regenerate')}
            </Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('salon.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/** SAL-10 to SAL-15: the company's salon rules and each branch's online booking page. */
export default function SalonSettings() {
  const { t } = useTranslation()
  const { can, canWithin } = usePermissions()
  const place = useSalonPlace()
  const places = usePlaces()
  const companyId = place.company?.id ?? ''
  const settingsQuery = useQuery({ queryKey: [...salonKey, 'settings', companyId], queryFn: () => api.get(`salon/companies/${companyId}/settings`), enabled: Boolean(companyId) })
  const branchId = place.branch?.id ?? ''
  const onlineQuery = useQuery({ queryKey: [...salonKey, 'online', branchId], queryFn: () => api.get(`salon/branches/${branchId}/online`), enabled: Boolean(branchId) && can('salon.online.view') })
  const settings = settingsQuery.data?.data
  const canEdit = Boolean(companyId) && canWithin(SETTINGS_EDIT, [{ type: 'company', id: companyId }])
  const canOnline = Boolean(branchId) && canWithin(ONLINE_MANAGE, [{ type: 'company', id: companyId }, { type: 'branch', id: branchId }])
  const locations = places.activeLocations.filter((location) => location.branch_id === branchId)
  const page = onlineQuery.data?.data
  return (
    <>
      <PageHeader title={t('salon.settings.title')} description={t('salon.settings.description')} />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} />
        {!companyId ? <p className="text-ink-muted">{t('salon.settings.noCompany')}</p> : null}
        <QueryNotes query={settingsQuery} />
        {settings ? <CompanyForm key={`${companyId}-${JSON.stringify(settings)}`} settings={settings} canEdit={canEdit} /> : null}
        {can('salon.online.view') ? <QueryNotes query={onlineQuery} /> : null}
        {page ? <OnlineForm key={`${branchId}-${page.public_code}-${page.enabled}`} page={page} branchId={branchId} canEdit={canOnline} locations={locations} /> : null}
      </div>
    </>
  )
}
