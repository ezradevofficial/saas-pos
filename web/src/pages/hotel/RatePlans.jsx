import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { addDays, BOARDS, hotelKey, isWhole, RATE_MANAGE, useBranchChoice } from './hotelData'
import { ChargeRule } from './ChargeRule'
import { Amount, BranchSwitch, Day, NoProperty, RateQuote } from './HotelParts'

const blankSeason = () => ({ name: '', starts_on: '', ends_on: '', weekday_rate_minor: '', weekend_rate_minor: '', priority: '1' })

function formOf(plan, currency) {
  return {
    room_type_id: plan?.room_type?.id ?? '',
    code: plan?.code ?? '',
    name: plan?.name ?? '',
    board: plan?.board ?? 'room_only',
    currency: plan?.currency ?? currency,
    weekday_rate_minor: plan?.weekday_rate_minor ?? '',
    weekend_rate_minor: plan?.weekend_rate_minor ?? '',
    extra_adult_minor: plan?.extra_adult_minor ?? '0',
    child_rate_minor: plan?.child_rate_minor ?? '0',
    free_children: String(plan?.free_children ?? 0),
    tax_inclusive: plan?.tax_inclusive ?? false,
    active: plan?.active ?? true,
    valid_from: plan?.valid_from ?? '',
    valid_until: plan?.valid_until ?? '',
    cancel_free_days: plan?.cancel_free_days == null ? '' : String(plan.cancel_free_days),
    cancel_charge_kind: plan?.cancel_charge_kind ?? '',
    cancel_charge_value: plan?.cancel_charge_value ?? '0',
    noshow_charge_kind: plan?.noshow_charge_kind ?? '',
    noshow_charge_value: plan?.noshow_charge_value ?? '0',
    seasons: (plan?.seasons ?? []).map((season) => ({ name: season.name, starts_on: season.starts_on, ends_on: season.ends_on, weekday_rate_minor: season.weekday_rate_minor, weekend_rate_minor: season.weekend_rate_minor ?? '', priority: String(season.priority) })),
  }
}

const FIELDS = ['room_type_id', 'code', 'name', 'board', 'currency', 'weekday_rate_minor', 'weekend_rate_minor', 'extra_adult_minor', 'child_rate_minor', 'free_children', 'valid_from', 'valid_until', 'cancel_free_days', 'cancel_charge_value', 'noshow_charge_value', 'seasons']

/** HOT-06 to HOT-09: add or change a rate plan, its seasons and its own cancellation rule. */
function PlanDialog({ plan, companyId, types, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { currency: base, options: currencies } = useMoneyDefaults(companyId)
  const [form, setForm] = useState(() => formOf(plan, base))
  const [showErrors, setShowErrors] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const text = (name) => (event) => set(name)(event.target.value)
  const updateSeason = (index, change) => setForm((current) => ({ ...current, seasons: current.seasons.map((season, at) => (at === index ? { ...season, ...change } : season)) }))
  const save = useMutation({
    mutationFn: () => {
      const body = {
        name: form.name.trim(),
        board: form.board,
        weekday_rate_minor: form.weekday_rate_minor,
        weekend_rate_minor: form.weekend_rate_minor,
        extra_adult_minor: form.extra_adult_minor || '0',
        child_rate_minor: form.child_rate_minor || '0',
        free_children: Number(form.free_children),
        tax_inclusive: form.tax_inclusive,
        active: form.active,
        valid_from: form.valid_from || null,
        valid_until: form.valid_until || null,
        cancel_free_days: form.cancel_free_days === '' ? null : Number(form.cancel_free_days),
        cancel_charge_kind: form.cancel_charge_kind || null,
        cancel_charge_value: form.cancel_charge_kind ? form.cancel_charge_value || '0' : null,
        noshow_charge_kind: form.noshow_charge_kind || null,
        noshow_charge_value: form.noshow_charge_kind ? form.noshow_charge_value || '0' : null,
        seasons: form.seasons.map((season) => ({
          name: season.name.trim(),
          starts_on: season.starts_on,
          ends_on: season.ends_on,
          weekday_rate_minor: season.weekday_rate_minor,
          weekend_rate_minor: season.weekend_rate_minor === '' ? null : season.weekend_rate_minor,
          priority: Number(season.priority || 0),
        })),
      }
      return plan ? api.patch(`hotel/rate-plans/${plan.id}`, body) : api.post('hotel/rate-plans', { ...body, room_type_id: form.room_type_id, code: form.code.trim(), currency: form.currency })
    },
    onSuccess: async () => {
      toast.success(t('hotel.rates.saved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const seasonError = (index, field) => save.error?.errors?.[`seasons.${index}.${field}`]?.[0]
  const missing = (value) => (showErrors && !String(value ?? '').trim() ? t('hotel.validation.required') : undefined)
  const complete = form.name.trim() && form.weekday_rate_minor !== '' && form.weekend_rate_minor !== '' && form.weekday_rate_minor !== null && form.weekend_rate_minor !== null && (plan || (form.room_type_id && form.code.trim())) && isWhole(form.free_children)
  const submit = () => {
    setShowErrors(true)
    if (complete) save.mutate()
  }
  const money = (name, label, required = false) => (
    <MoneyInput key={`${name}-${form.currency}`} label={label} currency={form.currency} value={form[name]} required={required} showErrors={showErrors} error={errors.fields[name] ?? (required ? missing(form[name]) : undefined)} onChange={(next) => set(name)(next ?? '')} />
  )
  return (
    <Dialog
      open
      size="lg"
      title={plan ? t('hotel.rates.edit') : t('hotel.rates.new')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={submit}>
            {t('hotel.rates.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t('hotel.columns.room_type')} placeholder={t('hotel.pickers.roomType')} options={types.map((type) => ({ value: type.id, label: `${type.name} · ${type.code}` }))} value={form.room_type_id} disabled={Boolean(plan)} required error={errors.fields.room_type_id ?? (plan ? undefined : missing(form.room_type_id))} onChange={text('room_type_id')} />
          <TextField label={t('hotel.columns.code')} value={form.code} maxLength={20} disabled={Boolean(plan)} required error={errors.fields.code ?? (plan ? undefined : missing(form.code))} onChange={text('code')} />
          <TextField label={t('hotel.columns.name')} value={form.name} maxLength={100} required error={errors.fields.name ?? missing(form.name)} onChange={text('name')} />
          <Select label={t('hotel.columns.board')} options={BOARDS.map((board) => ({ value: board, label: t(`hotel.boards.${board}`) }))} value={form.board} error={errors.fields.board} onChange={text('board')} />
          <Select label={t('hotel.rates.currency')} options={currencies} value={form.currency} disabled={Boolean(plan)} error={errors.fields.currency} onChange={text('currency')} />
          <Switch label={t('hotel.rates.taxInclusive')} checked={form.tax_inclusive} onChange={set('tax_inclusive')} className="self-end pb-2" />
          {money('weekday_rate_minor', t('hotel.rates.weekdayRate'), true)}
          {money('weekend_rate_minor', t('hotel.rates.weekendRate'), true)}
          {money('extra_adult_minor', t('hotel.rates.extraAdult'))}
          {money('child_rate_minor', t('hotel.rates.childRate'))}
          <TextField label={t('hotel.rates.freeChildren')} inputMode="numeric" value={form.free_children} error={errors.fields.free_children} onChange={text('free_children')} />
          <Switch label={t('hotel.rates.active')} checked={form.active} onChange={set('active')} className="self-end pb-2" />
          <TextField type="date" label={t('hotel.rates.validFrom')} value={form.valid_from} error={errors.fields.valid_from} onChange={text('valid_from')} />
          <TextField type="date" label={t('hotel.rates.validUntil')} value={form.valid_until} error={errors.fields.valid_until} onChange={text('valid_until')} />
        </div>

        <section className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-3">
            <h3 className="text-label text-ink">{t('hotel.rates.seasons')}</h3>
            <Button variant="ghost" icon="plus" onClick={() => set('seasons')([...form.seasons, blankSeason()])}>
              {t('hotel.rates.addSeason')}
            </Button>
          </div>
          <p className="text-caption text-ink-muted">{t('hotel.rates.seasonsHelp')}</p>
          {form.seasons.length === 0 ? <p className="text-body text-ink-muted">{t('hotel.rates.noSeasons')}</p> : null}
          {form.seasons.map((season, index) => (
            <div key={index} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-3">
              <TextField label={t('hotel.rates.seasonName')} value={season.name} maxLength={100} error={seasonError(index, 'name')} onChange={(event) => updateSeason(index, { name: event.target.value })} />
              <TextField type="date" label={t('hotel.rates.seasonFrom')} value={season.starts_on} error={seasonError(index, 'starts_on')} onChange={(event) => updateSeason(index, { starts_on: event.target.value })} />
              <TextField type="date" label={t('hotel.rates.seasonTo')} value={season.ends_on} error={seasonError(index, 'ends_on')} onChange={(event) => updateSeason(index, { ends_on: event.target.value })} />
              <MoneyInput key={`wd-${index}-${form.currency}`} label={t('hotel.rates.weekdayRate')} currency={form.currency} value={season.weekday_rate_minor} error={seasonError(index, 'weekday_rate_minor')} onChange={(next) => updateSeason(index, { weekday_rate_minor: next ?? '' })} />
              <MoneyInput key={`we-${index}-${form.currency}`} label={t('hotel.rates.weekendRate')} currency={form.currency} value={season.weekend_rate_minor} help={t('hotel.rates.weekendOptional')} error={seasonError(index, 'weekend_rate_minor')} onChange={(next) => updateSeason(index, { weekend_rate_minor: next ?? '' })} />
              <div className="flex items-end gap-2">
                <TextField label={t('hotel.rates.priority')} inputMode="numeric" value={season.priority} error={seasonError(index, 'priority')} onChange={(event) => updateSeason(index, { priority: event.target.value })} className="flex-1" />
                <Button variant="ghost" aria-label={t('hotel.rates.removeSeason', { number: index + 1 })} onClick={() => set('seasons')(form.seasons.filter((_, at) => at !== index))}>
                  {t('hotel.remove')}
                </Button>
              </div>
            </div>
          ))}
        </section>

        <section className="flex flex-col gap-3">
          <h3 className="text-label text-ink">{t('hotel.rates.cancellation')}</h3>
          <p className="text-caption text-ink-muted">{t('hotel.rates.cancellationHelp')}</p>
          <TextField label={t('hotel.settings.cancelFreeDays')} help={t('hotel.rates.inheritEmpty')} suffix={t('hotel.settings.days')} inputMode="numeric" value={form.cancel_free_days} error={errors.fields.cancel_free_days} onChange={text('cancel_free_days')} className="max-w-sm" />
          <ChargeRule key={`cancel-${form.currency}`} inherit legend={t('hotel.settings.cancelCharge')} kind={form.cancel_charge_kind} value={form.cancel_charge_value} currency={form.currency} error={errors.fields.cancel_charge_value} onChange={({ kind, value }) => setForm((current) => ({ ...current, cancel_charge_kind: kind, cancel_charge_value: value }))} />
          <ChargeRule key={`noshow-${form.currency}`} inherit legend={t('hotel.settings.noshowCharge')} kind={form.noshow_charge_kind} value={form.noshow_charge_value} currency={form.currency} error={errors.fields.noshow_charge_value} onChange={({ kind, value }) => setForm((current) => ({ ...current, noshow_charge_kind: kind, noshow_charge_value: value }))} />
        </section>
      </div>
    </Dialog>
  )
}

/** HOT-10: what a plan costs for dates and a party, night by night, before anyone books. */
function QuoteDialog({ plans, onClose }) {
  const { t } = useTranslation()
  const today = todayIn(undefined)
  const [planId, setPlanId] = useState(plans[0]?.id ?? '')
  const [arrival, setArrival] = useState(today)
  const [departure, setDeparture] = useState(addDays(today, 2))
  const [adults, setAdults] = useState('2')
  const [children, setChildren] = useState('0')
  return (
    <Dialog open size="lg" title={t('hotel.rates.quoteTitle')} onClose={onClose} footer={<Button variant="ghost" onClick={onClose}>{t('hotel.close')}</Button>}>
      <div className="flex flex-col gap-3">
        <Select label={t('hotel.rates.plan')} options={plans.map((plan) => ({ value: plan.id, label: `${plan.name} · ${plan.room_type?.name ?? ''}` }))} value={planId} onChange={(event) => setPlanId(event.target.value)} />
        <div className="grid gap-3 sm:grid-cols-4">
          <TextField type="date" label={t('hotel.columns.arrival_on')} value={arrival} onChange={(event) => setArrival(event.target.value)} />
          <TextField type="date" label={t('hotel.columns.departure_on')} value={departure} onChange={(event) => setDeparture(event.target.value)} />
          <TextField label={t('hotel.fields.adults')} inputMode="numeric" value={adults} onChange={(event) => setAdults(event.target.value)} />
          <TextField label={t('hotel.fields.children')} inputMode="numeric" value={children} onChange={(event) => setChildren(event.target.value)} />
        </div>
        <RateQuote planId={planId} arrival={arrival} departure={departure} adultCount={Number(adults) || 1} childCount={Number(children) || 0} />
      </div>
    </Dialog>
  )
}

/** HOT-06 to HOT-10: rate plans per room type and a quote preview. */
export default function RatePlans() {
  const { t } = useTranslation()
  const choice = useBranchChoice()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(undefined)
  const [quoting, setQuoting] = useState(false)
  const companyId = choice.companyId
  const plans = useQuery({ queryKey: [...hotelKey, 'rate-plans', companyId], queryFn: () => api.get(`hotel/rate-plans?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const types = useQuery({ queryKey: [...hotelKey, 'room-types', companyId], queryFn: () => api.get(`hotel/room-types?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const archive = useMutation({
    mutationFn: (plan) => api.post(`hotel/rate-plans/${plan.id}/archive`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKey }),
    onError: (error) => toast.error(errorMessage(error)),
  })
  const canManage = can(RATE_MANAGE)
  const rows = plans.data?.data ?? []
  const columns = [
    { key: 'code', label: t('hotel.columns.code'), render: (row) => <span className="font-mono">{row.code}</span> },
    { key: 'name', label: t('hotel.columns.name'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'room_type', label: t('hotel.columns.room_type'), render: (row) => row.room_type?.name ?? '' },
    { key: 'board', label: t('hotel.columns.board'), render: (row) => t(`hotel.boards.${row.board}`) },
    { key: 'weekday', label: t('hotel.rates.weekdayRate'), align: 'end', render: (row) => <Amount minor={row.weekday_rate_minor} currency={row.currency} /> },
    { key: 'weekend', label: t('hotel.rates.weekendRate'), align: 'end', render: (row) => <Amount minor={row.weekend_rate_minor} currency={row.currency} /> },
    { key: 'seasons', label: t('hotel.rates.seasons'), render: (row) => row.seasons.length },
    { key: 'valid', label: t('hotel.rates.validity'), render: (row) => (row.valid_from || row.valid_until ? <span><Day value={row.valid_from} /> – <Day value={row.valid_until} /></span> : '') },
    ...(canManage
      ? [
          {
            key: 'actions',
            label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
            render: (row) => (
              <div className="flex justify-end gap-1">
                <Button variant="ghost" onClick={() => setEditing(row)}>
                  {t('hotel.rates.edit')}
                </Button>
                <Button variant="ghost" onClick={() => archive.mutate(row)}>
                  {t('hotel.archive')}
                </Button>
              </div>
            ),
          },
        ]
      : []),
  ]
  return (
    <>
      <PageHeader
        title={t('hotel.rates.title')}
        description={t('hotel.rates.description')}
        actions={
          <>
            <Button variant="secondary" disabled={rows.length === 0} onClick={() => setQuoting(true)}>
              {t('hotel.rates.quote')}
            </Button>
            {canManage ? (
              <Button variant="primary" icon="plus" onClick={() => setEditing(null)}>
                {t('hotel.rates.new')}
              </Button>
            ) : null}
          </>
        }
      />
      <BranchSwitch choice={choice} />
      {choice.branch ? <DataTable caption={t('hotel.rates.title')} columns={columns} rows={rows} loading={plans.isPending} emptyText={t('hotel.rates.empty')} /> : <NoProperty choice={choice} />}
      {editing !== undefined ? <PlanDialog plan={editing} companyId={companyId} types={types.data?.data ?? []} onClose={() => setEditing(undefined)} /> : null}
      {quoting ? <QuoteDialog plans={rows} onClose={() => setQuoting(false)} /> : null}
    </>
  )
}
