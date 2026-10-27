import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, MoneyInput, MultiSelect, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { ChargeRule } from './ChargeRule'
import { ItemPicker } from './HotelParts'
import { hotelKey, isWhole, SETTINGS_EDIT, useHotelCompanyChoice, usePaymentMethodsOfType, WEEKDAYS } from './hotelData'

const FIELDS = [
  'check_in_time', 'check_out_time', 'night_audit_time', 'early_checkin_fee_minor', 'late_checkout_fee_minor', 'fee_currency', 'weekend_days', 'cancel_free_days',
  'cancel_charge_kind', 'cancel_charge_value', 'noshow_charge_kind', 'noshow_charge_value', 'deposit_percent', 'require_guest_id', 'room_item_id', 'levy_tax_code_ids', 'charge_limit_minor', 'room_charge_method_id',
]

function toForm(settings) {
  return {
    company_id: settings.company_id,
    check_in_time: settings.check_in_time,
    check_out_time: settings.check_out_time,
    night_audit_time: settings.night_audit_time,
    early_checkin_fee_minor: settings.early_checkin_fee_minor,
    late_checkout_fee_minor: settings.late_checkout_fee_minor,
    fee_currency: settings.fee_currency ?? '',
    weekend_days: settings.weekend_days,
    cancel_free_days: String(settings.cancel_free_days),
    cancel_charge_kind: settings.cancel_charge_kind,
    cancel_charge_value: settings.cancel_charge_value,
    noshow_charge_kind: settings.noshow_charge_kind,
    noshow_charge_value: settings.noshow_charge_value,
    deposit_percent: String(settings.deposit_percent),
    require_guest_id: settings.require_guest_id,
    room_item_id: settings.room_item_id ?? '',
    levy_tax_code_ids: settings.levy_tax_code_ids,
    charge_limit_minor: settings.charge_limit_minor ?? '',
    room_charge_method_id: settings.room_charge_method_id ?? '',
  }
}

/** A room item picked by id: loads its name for the picker. */
function RoomItem({ id, onChange, error, disabled }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: ['items', 'detail', id], queryFn: () => api.get(`items/${id}`), enabled: Boolean(id) })
  const item = id ? (query.data?.data ?? { id, name: id, code: '' }) : null
  return <ItemPicker label={t('hotel.settings.roomItem')} value={item} error={error} disabled={disabled} onChange={(next) => onChange(next?.id ?? '')} />
}

/**
 * HOT-02: the company's hotel settings: times, fees, weekend days, the
 * cancellation and no-show rules, the deposit suggested at booking, the room
 * revenue item, extra levy tax codes, the in-house charge limit and whether a
 * guest's ID is required. No levy or rate is built in: the company lists its
 * tax codes (HOT-32).
 */
export default function HotelSettings() {
  const { t } = useTranslation()
  const { company, companies, choose } = useHotelCompanyChoice()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const key = [...hotelKey, 'settings', company?.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`companies/${company.id}/hotel-settings`), enabled: Boolean(company) })
  const taxCodes = useQuery({ queryKey: ['tax-codes', company?.id], queryFn: () => api.get(`companies/${company.id}/tax-codes?per_page=200`), enabled: Boolean(company) })
  const roomChargeMethods = usePaymentMethodsOfType(company?.id, 'room_charge')
  const { currency: baseCurrency, options: currencies } = useMoneyDefaults(company?.id)
  const [form, setForm] = useState(null)
  const [saved, setSaved] = useState(false)
  const settings = query.data?.data
  if (settings && form?.company_id !== settings.company_id) setForm(toForm(settings))

  const save = useMutation({
    mutationFn: () =>
      api.put(`companies/${company.id}/hotel-settings`, {
        check_in_time: form.check_in_time,
        check_out_time: form.check_out_time,
        night_audit_time: form.night_audit_time,
        early_checkin_fee_minor: form.early_checkin_fee_minor,
        late_checkout_fee_minor: form.late_checkout_fee_minor,
        fee_currency: form.fee_currency || null,
        weekend_days: form.weekend_days,
        cancel_free_days: Number(form.cancel_free_days),
        cancel_charge_kind: form.cancel_charge_kind,
        cancel_charge_value: form.cancel_charge_value === '' ? '0' : form.cancel_charge_value,
        noshow_charge_kind: form.noshow_charge_kind,
        noshow_charge_value: form.noshow_charge_value === '' ? '0' : form.noshow_charge_value,
        deposit_percent: Number(form.deposit_percent),
        require_guest_id: form.require_guest_id,
        room_item_id: form.room_item_id || null,
        levy_tax_code_ids: form.levy_tax_code_ids,
        charge_limit_minor: form.charge_limit_minor === '' ? null : form.charge_limit_minor,
        room_charge_method_id: form.room_charge_method_id || null,
      }),
    onSuccess: async (response) => {
      queryClient.setQueryData(key, response)
      setSaved(true)
      await queryClient.invalidateQueries({ queryKey: hotelKey })
    },
  })

  if (!company) return <Alert tone="info" title={t('hotel.settings.noCompany')} />
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!form) return <p className="text-ink-muted">{t('common.loading')}</p>

  const editable = canWithin(SETTINGS_EDIT, [{ type: 'company', id: company.id }])
  const errors = formErrors(save.error, FIELDS)
  const set = (name) => (value) => {
    setSaved(false)
    setForm((current) => ({ ...current, [name]: value }))
  }
  const feeCurrency = form.fee_currency || baseCurrency
  const valid = isWhole(form.cancel_free_days) && isWhole(form.deposit_percent) && Number(form.deposit_percent) <= 100 && form.early_checkin_fee_minor !== null && form.late_checkout_fee_minor !== null
  const toggleDay = (day, on) => set('weekend_days')(on ? [...form.weekend_days, day].sort() : form.weekend_days.filter((entry) => entry !== day))
  const taxOptions = (taxCodes.data?.data ?? []).filter((code) => !code.archived_at).map((code) => ({ value: code.id, label: code.name }))

  return (
    <>
      <PageHeader title={t('hotel.settings.title')} description={t('hotel.settings.description', { company: company.name })} />
      {companies.length > 1 ? (
        <Select
          label={t('hotel.settings.company')}
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
      <Alert tone="info" title={t('hotel.settings.toConfirm')} />
      {saved ? <Alert tone="success" title={t('hotel.settings.saved')} /> : null}
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <form
        className="flex flex-col gap-6"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        <Card title={t('hotel.settings.times')}>
          <div className="grid gap-4 md:grid-cols-3">
            <TextField type="time" label={t('hotel.settings.checkInTime')} value={form.check_in_time} error={errors.fields.check_in_time} disabled={!editable} onChange={(event) => set('check_in_time')(event.target.value)} />
            <TextField type="time" label={t('hotel.settings.checkOutTime')} value={form.check_out_time} error={errors.fields.check_out_time} disabled={!editable} onChange={(event) => set('check_out_time')(event.target.value)} />
            <TextField type="time" label={t('hotel.settings.nightAuditTime')} value={form.night_audit_time} error={errors.fields.night_audit_time} disabled={!editable} onChange={(event) => set('night_audit_time')(event.target.value)} />
          </div>
          <fieldset className="mt-5 flex flex-col gap-2">
            <legend className="text-label text-ink">{t('hotel.settings.weekendDays')}</legend>
            <p className="text-caption text-ink-muted">{t('hotel.settings.weekendDaysHelp')}</p>
            <div className="flex flex-wrap gap-x-5 gap-y-2">
              {WEEKDAYS.map((day) => (
                <Checkbox key={day} label={t(`hotel.weekdays.${day}`)} checked={form.weekend_days.includes(day)} disabled={!editable} onChange={(event) => toggleDay(day, event.target.checked)} />
              ))}
            </div>
            {errors.fields.weekend_days ? <p className="text-caption text-danger">{errors.fields.weekend_days}</p> : null}
          </fieldset>
        </Card>

        <Card title={t('hotel.settings.fees')}>
          <div className="grid gap-4 md:grid-cols-3">
            <Select label={t('hotel.settings.feeCurrency')} options={currencies} value={feeCurrency} error={errors.fields.fee_currency} disabled={!editable} onChange={(event) => set('fee_currency')(event.target.value)} />
            <MoneyInput key={`early-${feeCurrency}`} label={t('hotel.settings.earlyFee')} currency={feeCurrency} value={form.early_checkin_fee_minor} error={errors.fields.early_checkin_fee_minor} disabled={!editable} onChange={set('early_checkin_fee_minor')} />
            <MoneyInput key={`late-${feeCurrency}`} label={t('hotel.settings.lateFee')} currency={feeCurrency} value={form.late_checkout_fee_minor} error={errors.fields.late_checkout_fee_minor} disabled={!editable} onChange={set('late_checkout_fee_minor')} />
          </div>
        </Card>

        <Card title={t('hotel.settings.rules')}>
          <div className="flex flex-col gap-5">
            <TextField label={t('hotel.settings.cancelFreeDays')} help={t('hotel.settings.cancelFreeDaysHelp')} suffix={t('hotel.settings.days')} inputMode="numeric" value={form.cancel_free_days} error={errors.fields.cancel_free_days} disabled={!editable} onChange={(event) => set('cancel_free_days')(event.target.value)} className="max-w-sm" />
            <ChargeRule
              key={`cancel-${feeCurrency}`}
              legend={t('hotel.settings.cancelCharge')}
              kind={form.cancel_charge_kind}
              value={form.cancel_charge_value}
              currency={feeCurrency}
              disabled={!editable}
              error={errors.fields.cancel_charge_value}
              onChange={({ kind, value }) => setForm((current) => ({ ...current, cancel_charge_kind: kind, cancel_charge_value: value }))}
            />
            <ChargeRule
              key={`noshow-${feeCurrency}`}
              legend={t('hotel.settings.noshowCharge')}
              kind={form.noshow_charge_kind}
              value={form.noshow_charge_value}
              currency={feeCurrency}
              disabled={!editable}
              error={errors.fields.noshow_charge_value}
              onChange={({ kind, value }) => setForm((current) => ({ ...current, noshow_charge_kind: kind, noshow_charge_value: value }))}
            />
            <TextField label={t('hotel.settings.depositPercent')} help={t('hotel.settings.depositPercentHelp')} suffix="%" inputMode="numeric" value={form.deposit_percent} error={errors.fields.deposit_percent} disabled={!editable} onChange={(event) => set('deposit_percent')(event.target.value)} className="max-w-sm" />
            <Switch label={t('hotel.settings.requireGuestId')} checked={form.require_guest_id} disabled={!editable} onChange={set('require_guest_id')} />
          </div>
        </Card>

        <Card title={t('hotel.settings.billing')}>
          <div className="grid gap-4 md:grid-cols-2">
            <RoomItem id={form.room_item_id} error={errors.fields.room_item_id} disabled={!editable} onChange={set('room_item_id')} />
            <MultiSelect
              label={t('hotel.settings.levies')}
              help={t('hotel.settings.leviesHelp')}
              placeholder={t('hotel.settings.noLevies')}
              options={taxOptions}
              value={form.levy_tax_code_ids}
              error={errors.fields.levy_tax_code_ids}
              disabled={!editable}
              onChange={set('levy_tax_code_ids')}
            />
            <MoneyInput key={`limit-${feeCurrency}`} label={t('hotel.settings.chargeLimit')} help={t('hotel.settings.chargeLimitHelp')} currency={feeCurrency} value={form.charge_limit_minor} error={errors.fields.charge_limit_minor} disabled={!editable} onChange={(next) => set('charge_limit_minor')(next ?? '')} />
            <Select
              label={t('hotel.settings.roomChargeMethod')}
              placeholder={t('hotel.settings.noMethod')}
              options={[{ value: '', label: t('hotel.settings.noMethod') }, ...roomChargeMethods.map((method) => ({ value: method.id, label: method.name }))]}
              value={form.room_charge_method_id}
              error={errors.fields.room_charge_method_id}
              disabled={!editable}
              onChange={(event) => set('room_charge_method_id')(event.target.value)}
            />
          </div>
        </Card>
        {editable ? (
          <div>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
              {t('hotel.settings.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </>
  )
}
