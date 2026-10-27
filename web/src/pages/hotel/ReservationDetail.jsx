import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { CheckInDialog, CheckOutDialog, MoveRoomDialog } from './FrontDeskDialogs'
import { PaymentDialog } from './FolioDialogs'
import { Amount, Back, Day, Detail, DetailState, Stamp, StateBadge } from './HotelParts'
import { CHECKIN, CHECKOUT, FEE_WAIVE, FOLIO_PAYMENT, GUEST_VIEW, hotelKey, MOVE, nightsBetween, RESERVATION_CANCEL, RESERVATION_EDIT, useHotelPlaces } from './hotelData'

/** The cancellation or no-show charge the property's rule would make, in minor units, or null when it cannot be known here. */
function chargeOf(rule, stay) {
  if (!rule) return null
  const { kind, value } = rule
  if (kind === 'none') return 0n
  if (kind === 'fixed') return BigInt(value || '0')
  if (kind === 'percent') return (BigInt(stay.total_minor) * BigInt(value || '0')) / 100n
  if (kind === 'nights') return stay.nightly.slice(0, Number(value || 0)).reduce((sum, night) => sum + BigInt(night.amount_minor), 0n)
  return null
}

/** HOT-15: the rule for a stay: the plan's own when it has one, else the company's. */
function ruleFor(which, plan, settings) {
  if (!settings) return null
  const own = plan?.[`${which}_charge_kind`]
  const free = which === 'cancel' ? (plan?.cancel_free_days ?? settings.cancel_free_days) : 0
  return own
    ? { kind: own, value: plan[`${which}_charge_value`], freeDays: free }
    : { kind: settings[`${which}_charge_kind`], value: settings[`${which}_charge_value`], freeDays: free }
}

/** HOT-15: cancel a stay, or mark it a no-show, with the charge the rule makes and the right to waive it. */
function CancelDialog({ stay, mode, branchCompany, onClose }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const zone = useTimeZone(branchCompany)
  const [reason, setReason] = useState('')
  const [waive, setWaive] = useState(false)
  const cancel = mode === 'cancel'
  // Both reads need permissions a desk may lack; the preview is then left out and the server decides.
  const settings = useQuery({ queryKey: [...hotelKey, 'settings', branchCompany], queryFn: () => api.get(`companies/${branchCompany}/hotel-settings`), enabled: Boolean(branchCompany), retry: false })
  const plan = useQuery({ queryKey: [...hotelKey, 'rate-plan', stay.rate_plan?.id], queryFn: () => api.get(`hotel/rate-plans/${stay.rate_plan.id}`), enabled: Boolean(stay.rate_plan?.id), retry: false })
  const rule = ruleFor(cancel ? 'cancel' : 'noshow', plan.data?.data, settings.data?.data)
  const today = todayIn(zone)
  const free = cancel && rule && nightsBetween(today, stay.arrival_on) >= (rule.freeDays ?? 0)
  const charge = free ? 0n : chargeOf(rule, stay)
  const send = useMutation({
    mutationFn: () => api.post(`hotel/stays/${stay.id}/${cancel ? 'cancel' : 'no-show'}`, { ...(reason.trim() ? { reason: reason.trim() } : {}), ...(waive ? { waive_charge: true } : {}) }),
    onSuccess: async () => {
      toast.success(cancel ? t('hotel.reservation.cancelled') : t('hotel.reservation.markedNoShow'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(send.error, ['reason', 'waive_charge'])
  return (
    <Dialog
      open
      title={cancel ? t('hotel.reservation.cancelTitle') : t('hotel.reservation.noShowTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('hotel.reservation.keep')}
          </Button>
          <Button variant="danger" loading={send.isPending} disabled={cancel && !reason.trim()} onClick={() => send.mutate()}>
            {cancel ? t('hotel.reservation.cancelAction') : t('hotel.reservation.noShowAction')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {charge === null ? (
          <p className="text-body text-ink-muted">{t('hotel.reservation.chargeUnknown')}</p>
        ) : charge === 0n ? (
          <p className="text-body text-ink-muted">{free ? t('hotel.reservation.freeCancel') : t('hotel.reservation.noCharge')}</p>
        ) : (
          <p className="text-body text-ink">
            {t('hotel.reservation.chargeWill')}: <Amount minor={charge.toString()} currency={stay.currency} />
          </p>
        )}
        <TextField label={cancel ? t('hotel.reservation.cancelReason') : t('hotel.reservation.noShowReason')} value={reason} maxLength={500} required={cancel} error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
        {can(FEE_WAIVE) && charge !== 0n ? <Switch label={t('hotel.reservation.waiveCharge')} checked={waive} onChange={setWaive} /> : null}
      </div>
    </Dialog>
  )
}

/** HOT-37: change the dates or the party of a stay; availability is checked again by the server. */
function ChangeStayDialog({ stay, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const inHouse = stay.status === 'checked_in'
  const [form, setForm] = useState({ arrival_on: stay.arrival_on, departure_on: stay.departure_on, adults: String(stay.adults), children: String(stay.children), notes: stay.notes ?? '' })
  const set = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = {}
      if (!inHouse && form.arrival_on !== stay.arrival_on) body.arrival_on = form.arrival_on
      if (form.departure_on !== stay.departure_on) body.departure_on = form.departure_on
      if (Number(form.adults) !== stay.adults) body.adults = Number(form.adults)
      if (Number(form.children) !== stay.children) body.children = Number(form.children)
      if (form.notes !== (stay.notes ?? '')) body.notes = form.notes.trim() || null
      return api.patch(`hotel/stays/${stay.id}`, body)
    },
    onSuccess: async () => {
      toast.success(t('hotel.reservation.changed'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['arrival_on', 'departure_on', 'adults', 'children', 'notes'])
  return (
    <Dialog
      open
      title={t('hotel.reservation.changeTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={form.departure_on <= form.arrival_on} onClick={() => save.mutate()}>
            {t('hotel.reservation.saveChanges')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {inHouse ? <p className="text-body text-ink-muted">{t('hotel.reservation.inHouseChange')}</p> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField type="date" label={t('hotel.columns.arrival_on')} value={form.arrival_on} disabled={inHouse} error={errors.fields.arrival_on} onChange={set('arrival_on')} />
          <TextField type="date" label={t('hotel.columns.departure_on')} value={form.departure_on} error={errors.fields.departure_on} onChange={set('departure_on')} />
          <TextField label={t('hotel.fields.adults')} inputMode="numeric" value={form.adults} error={errors.fields.adults} onChange={set('adults')} />
          <TextField label={t('hotel.fields.children')} inputMode="numeric" value={form.children} error={errors.fields.children} onChange={set('children')} />
        </div>
        <TextField label={t('hotel.fields.notes')} value={form.notes} maxLength={1000} error={errors.fields.notes} onChange={set('notes')} />
      </div>
    </Dialog>
  )
}

function StayCard({ stay, companyId, onAction }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const open = stay.status === 'reserved' || stay.status === 'checked_in'
  const nights = [
    { key: 'date', label: t('hotel.columns.date'), render: (row) => <Day value={row.date} /> },
    { key: 'season', label: t('hotel.rates.season'), render: (row) => row.season ?? '' },
    { key: 'rate', label: t('hotel.columns.rate'), align: 'end', render: (row) => <Amount minor={row.rate_minor} currency={stay.currency} /> },
    { key: 'extra', label: t('hotel.columns.extra'), align: 'end', render: (row) => <Amount minor={row.extra_minor} currency={stay.currency} /> },
    { key: 'amount', label: t('hotel.columns.amount_minor'), align: 'end', render: (row) => <Amount minor={row.amount_minor} currency={stay.currency} /> },
  ]
  return (
    <Card
      title={`${stay.room_type?.name ?? ''}${stay.room?.number ? ` · ${t('hotel.reservation.roomNumber', { number: stay.room.number })}` : ''}`}
      subtitle={<StateBadge status={stay.status} />}
      actions={
        <div className="flex flex-wrap justify-end gap-2">
          {stay.status === 'reserved' && can(CHECKIN) ? <Button variant="primary" onClick={() => onAction('in', stay)}>{t('hotel.frontDesk.checkIn')}</Button> : null}
          {stay.status === 'checked_in' && can(CHECKOUT) ? <Button variant="primary" onClick={() => onAction('out', stay)}>{t('hotel.frontDesk.checkOut')}</Button> : null}
          {stay.status === 'checked_in' && can(MOVE) ? <Button onClick={() => onAction('move', stay)}>{t('hotel.frontDesk.move')}</Button> : null}
          {open && can(RESERVATION_EDIT) ? <Button onClick={() => onAction('change', stay)}>{t('hotel.reservation.change')}</Button> : null}
          {stay.status === 'reserved' && can(FOLIO_PAYMENT) && stay.folio_id ? <Button onClick={() => onAction('deposit', stay)}>{t('hotel.reservation.takeDeposit')}</Button> : null}
          {stay.status === 'reserved' && can(RESERVATION_CANCEL) ? <Button onClick={() => onAction('noshow', stay)}>{t('hotel.reservation.noShow')}</Button> : null}
          {open && stay.status === 'reserved' && can(RESERVATION_CANCEL) ? <Button variant="danger" onClick={() => onAction('cancel', stay)}>{t('hotel.reservation.cancel')}</Button> : null}
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <dl className="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <Detail label={t('hotel.columns.arrival_on')}><Day value={stay.arrival_on} /></Detail>
          <Detail label={t('hotel.columns.departure_on')}><Day value={stay.departure_on} /></Detail>
          <Detail label={t('hotel.columns.nights')}>{stay.nights}</Detail>
          <Detail label={t('hotel.columns.party')}>{`${stay.adults}+${stay.children}`}</Detail>
          <Detail label={t('hotel.columns.board')}>{t(`hotel.boards.${stay.board}`)}</Detail>
          <Detail label={t('hotel.rates.plan')}>{stay.rate_plan?.name}{stay.rate_overridden ? ` · ${t('hotel.reservation.rateOverridden')}` : ''}</Detail>
          <Detail label={t('hotel.columns.total_minor')}><Amount minor={stay.total_minor} currency={stay.currency} /></Detail>
          <Detail label={t('hotel.frontDesk.keys')}>{stay.keys_issued}</Detail>
          {stay.checked_in_at ? <Detail label={t('hotel.reservation.checkedInAt')}><Stamp value={stay.checked_in_at} companyId={companyId} /></Detail> : null}
          {stay.checked_out_at ? <Detail label={t('hotel.reservation.checkedOutAt')}><Stamp value={stay.checked_out_at} companyId={companyId} /></Detail> : null}
          {stay.cancelled_at ? <Detail label={t('hotel.reservation.cancelledAt')}><Stamp value={stay.cancelled_at} companyId={companyId} /></Detail> : null}
          {stay.cancel_reason ? <Detail label={t('hotel.reservation.cancelReason')}>{stay.cancel_reason}</Detail> : null}
        </dl>
        {stay.folio_id ? (
          <Link to={`/hotel/folios/${stay.folio_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
            {t('hotel.reservation.openFolio')}
          </Link>
        ) : null}
        <details>
          <summary className="cursor-pointer text-label text-ink-muted hover:text-ink">{t('hotel.reservation.nightly')}</summary>
          <div className="pt-3">
            <DataTable caption={t('hotel.reservation.nightly')} columns={nights} rows={(stay.nightly ?? []).map((night) => ({ ...night, id: night.date }))} />
          </div>
        </details>
      </div>
    </Card>
  )
}

/** HOT-12 to HOT-17, HOT-37: a reservation with its stays, their folios, and what can be done to each. */
export default function ReservationDetail() {
  const { t } = useTranslation()
  const { reservationId } = useParams()
  const { can } = usePermissions()
  const [action, setAction] = useState(null)
  const places = useHotelPlaces()
  const query = useQuery({ queryKey: [...hotelKey, 'reservation', reservationId], queryFn: () => api.get(`hotel/reservations/${reservationId}`) })
  const reservation = query.data?.data
  const back = <Back to="/hotel/reservations">{t('hotel.reservations.back')}</Back>
  if (!reservation) return <DetailState query={query} back={back} notFound={t('hotel.reservations.notFound')} />
  const companyId = places.branches.find((branch) => branch.id === reservation.branch?.id)?.company_id
  const close = () => setAction(null)
  const masters = (reservation.folios ?? []).filter((folio) => folio.kind === 'master')
  const stay = action?.stay
  return (
    <>
      {back}
      <PageHeader
        title={reservation.number}
        description={reservation.group_name ? `${t(`hotel.kinds.${reservation.kind}`)} · ${reservation.group_name}` : t(`hotel.kinds.${reservation.kind}`)}
      />
      <Card title={t('hotel.fields.guest')}>
        <dl className="grid gap-4 sm:grid-cols-3">
          <Detail label={t('hotel.columns.guest')}>
            {can(GUEST_VIEW) && reservation.guest?.id ? (
              <Link to={`/hotel/guests/${reservation.guest.id}`} className="text-primary hover:text-primary-hover">
                {reservation.guest.name}
              </Link>
            ) : (
              reservation.guest?.name
            )}
          </Detail>
          <Detail label={t('hotel.fields.property')}>{reservation.branch?.name}</Detail>
          <Detail label={t('hotel.fields.source')}>{t(`hotel.sources.${reservation.source}`, { defaultValue: reservation.source })}</Detail>
          {reservation.notes ? <Detail label={t('hotel.fields.notes')}>{reservation.notes}</Detail> : null}
        </dl>
        {masters.map((folio) => (
          <Link key={folio.id} to={`/hotel/folios/${folio.id}`} className="mt-4 block w-fit text-label text-primary hover:text-primary-hover">
            {t('hotel.reservation.masterFolio', { number: folio.number })}
          </Link>
        ))}
      </Card>
      {(reservation.stays ?? []).map((entry) => (
        <StayCard key={entry.id} stay={entry} companyId={companyId} onAction={(kind, target) => setAction({ kind, stay: target })} />
      ))}
      {action?.kind === 'in' ? <CheckInDialog stay={stay} onClose={close} /> : null}
      {action?.kind === 'out' ? <CheckOutDialog stay={stay} companyId={companyId} onClose={close} /> : null}
      {action?.kind === 'move' ? <MoveRoomDialog stay={stay} onClose={close} /> : null}
      {action?.kind === 'change' ? <ChangeStayDialog stay={stay} onClose={close} /> : null}
      {action?.kind === 'cancel' ? <CancelDialog stay={stay} mode="cancel" branchCompany={companyId} onClose={close} /> : null}
      {action?.kind === 'noshow' ? <CancelDialog stay={stay} mode="noshow" branchCompany={companyId} onClose={close} /> : null}
      {action?.kind === 'deposit' ? <PaymentDialog folio={{ id: stay.folio_id, currency: stay.currency, company_id: companyId }} stay={stay} kind="deposit" onClose={close} /> : null}
    </>
  )
}
