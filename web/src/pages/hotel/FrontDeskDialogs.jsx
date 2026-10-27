import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { todayIn } from '@/lib/dates'
import { addDays, BLANK_GUEST, FEE_WAIVE, FOLIO_PAYMENT, FOLIO_SETTLE_OPEN, guestBody, guestReady, hotelKey, RATE_OVERRIDE, useRooms } from './hotelData'
import { Amount, GuestField, PaymentMethodSelect } from './HotelParts'

const roomLabel = (room) => `${room.number} · ${room.room_type?.name ?? ''}`

function Footer({ onClose, action, loading, disabled, onConfirm, variant = 'primary' }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="ghost" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button variant={variant} loading={loading} disabled={disabled} onClick={onConfirm}>
        {action}
      </Button>
    </>
  )
}

function useDone(onClose) {
  const queryClient = useQueryClient()
  return async (message) => {
    if (message) toast.success(message)
    await queryClient.invalidateQueries({ queryKey: hotelKey })
    onClose()
  }
}

/** HOT-17, HOT-18: assign a clean room, hand over keys, waive the early fee. The guest's ID is checked before. */
export function CheckInDialog({ stay, onClose }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const rooms = useRooms(stay.branch_id)
  const done = useDone(onClose)
  const ready = rooms.filter((room) => room.status === 'vacant_clean' || room.id === stay.room?.id)
  const [roomId, setRoomId] = useState(stay.room?.id ?? '')
  const [keys, setKeys] = useState('1')
  const [waive, setWaive] = useState(false)
  const guest = useQuery({ queryKey: [...hotelKey, 'guest', stay.guest?.id], queryFn: () => api.get(`hotel/guests/${stay.guest.id}`), enabled: Boolean(stay.guest?.id), retry: false })
  const noId = guest.data?.data && !guest.data.data.has_identity_document
  const save = useMutation({
    mutationFn: () => api.post(`hotel/stays/${stay.id}/check-in`, { room_id: roomId, keys_issued: Number(keys) || 0, ...(waive ? { waive_fees: true } : {}) }),
    onSuccess: () => done(t('hotel.frontDesk.checkedIn', { guest: stay.guest?.name })),
  })
  const errors = formErrors(save.error, ['room_id', 'keys_issued', 'waive_fees'])
  return (
    <Dialog open title={t('hotel.frontDesk.checkInTitle', { guest: stay.guest?.name })} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.frontDesk.checkIn')} loading={save.isPending} disabled={!roomId} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {noId ? (
          <Alert tone="warning" title={t('hotel.frontDesk.idMissing')} action={<Link to={`/hotel/guests/${stay.guest.id}`} className="text-label text-primary hover:text-primary-hover">{t('hotel.frontDesk.openGuest')}</Link>} />
        ) : null}
        <Select label={t('hotel.columns.room')} placeholder={t('hotel.pickers.room')} help={stay.room_type ? t('hotel.frontDesk.bookedType', { type: stay.room_type.name }) : undefined} options={ready.map((room) => ({ value: room.id, label: roomLabel(room) }))} value={roomId} required error={errors.fields.room_id} onChange={(event) => setRoomId(event.target.value)} />
        <TextField label={t('hotel.frontDesk.keys')} inputMode="numeric" value={keys} error={errors.fields.keys_issued} onChange={(event) => setKeys(event.target.value)} className="max-w-40" />
        {can(FEE_WAIVE) ? <Switch label={t('hotel.frontDesk.waiveEarly')} checked={waive} onChange={setWaive} /> : null}
      </div>
    </Dialog>
  )
}

/** HOT-17, HOT-37: move a guest in house to another clean room. */
export function MoveRoomDialog({ stay, onClose }) {
  const { t } = useTranslation()
  const rooms = useRooms(stay.branch_id)
  const done = useDone(onClose)
  const [roomId, setRoomId] = useState('')
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`hotel/stays/${stay.id}/move-room`, { room_id: roomId, reason: reason.trim() }),
    onSuccess: () => done(t('hotel.frontDesk.moved')),
  })
  const errors = formErrors(save.error, ['room_id', 'reason'])
  const options = rooms.filter((room) => room.status === 'vacant_clean' && room.id !== stay.room?.id)
  return (
    <Dialog open title={t('hotel.frontDesk.moveTitle', { guest: stay.guest?.name })} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.frontDesk.move')} loading={save.isPending} disabled={!roomId || !reason.trim()} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <p className="text-body text-ink-muted">{t('hotel.frontDesk.moveHelp', { room: stay.room?.number ?? '' })}</p>
        <Select label={t('hotel.frontDesk.newRoom')} placeholder={t('hotel.pickers.room')} options={options.map((room) => ({ value: room.id, label: roomLabel(room) }))} value={roomId} required error={errors.fields.room_id} onChange={(event) => setRoomId(event.target.value)} />
        <TextField label={t('hotel.frontDesk.moveReason')} value={reason} maxLength={500} required error={errors.fields.reason} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/**
 * HOT-19, HOT-26: the folio at check-out: what is owed, the payments that
 * clear it (any method), and, only with `hotel.folio.settle_open`, leaving the
 * balance on account.
 */
export function CheckOutDialog({ stay, companyId, onClose }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const done = useDone(onClose)
  const folio = useQuery({ queryKey: [...hotelKey, 'folio', stay.folio_id], queryFn: () => api.get(`hotel/folios/${stay.folio_id}`), enabled: Boolean(stay.folio_id) })
  const [payments, setPayments] = useState([])
  const [waive, setWaive] = useState(false)
  const [leave, setLeave] = useState(false)
  const data = folio.data?.data
  const balance = BigInt(data?.balance_minor ?? '0')
  const paid = payments.reduce((sum, payment) => sum + BigInt(payment.amount_minor || '0'), 0n)
  const remaining = balance - paid
  const save = useMutation({
    mutationFn: () =>
      api.post(`hotel/stays/${stay.id}/check-out`, {
        ...(payments.length ? { payments: payments.map((payment) => ({ payment_method_id: payment.method.id, amount_minor: payment.amount_minor, ...(payment.reference.trim() ? { reference: payment.reference.trim() } : {}) })) } : {}),
        ...(waive ? { waive_fees: true } : {}),
        ...(leave ? { leave_balance: true } : {}),
      }),
    onSuccess: () => done(t('hotel.frontDesk.checkedOut', { guest: stay.guest?.name })),
  })
  const errors = formErrors(save.error, ['payments', 'waive_fees', 'leave_balance'])
  const update = (index, change) => setPayments((current) => current.map((payment, at) => (at === index ? { ...payment, ...change } : payment)))
  const addPayment = () => setPayments((current) => [...current, { method: null, amount_minor: remaining > 0n ? remaining.toString() : '', reference: '' }])
  const blocked = remaining > 0n && !leave
  const incomplete = payments.some((payment) => !payment.method || !payment.amount_minor || BigInt(payment.amount_minor) <= 0n)
  return (
    <Dialog open size="lg" title={t('hotel.frontDesk.checkOutTitle', { guest: stay.guest?.name })} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.frontDesk.checkOut')} loading={save.isPending} disabled={!data || blocked || incomplete} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {folio.isPending ? <p className="text-body text-ink-muted">{t('common.loading')}</p> : null}
        {data ? (
          <>
            <dl className="grid grid-cols-3 gap-3 rounded-md border border-border p-3">
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('hotel.folio.charges')}</dt>
                <dd><Amount minor={data.charges_minor} currency={data.currency} /></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('hotel.folio.paid')}</dt>
                <dd><Amount minor={data.payments_minor} currency={data.currency} /></dd>
              </div>
              <div className="flex flex-col gap-1">
                <dt className="text-caption text-ink-muted">{t('hotel.folio.balance')}</dt>
                <dd><Amount minor={data.balance_minor} currency={data.currency} tone={balance > 0n ? 'danger' : undefined} /></dd>
              </div>
            </dl>
            <Link to={`/hotel/folios/${data.id}`} className="w-fit text-label text-primary hover:text-primary-hover">
              {t('hotel.frontDesk.openFolio', { number: data.number })}
            </Link>
            {can(FOLIO_PAYMENT) ? (
              <section className="flex flex-col gap-3">
                {payments.map((payment, index) => (
                  <div key={index} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-3">
                    <PaymentMethodSelect companyId={companyId} currency={data.currency} value={payment.method} error={errors.fields[`payments.${index}.payment_method_id`]} onChange={(method) => update(index, { method })} />
                    <MoneyInput label={t('hotel.fields.amount')} currency={data.currency} value={payment.amount_minor} onChange={(next) => update(index, { amount_minor: next ?? '' })} />
                    <TextField label={t('hotel.fields.reference')} value={payment.reference} maxLength={120} onChange={(event) => update(index, { reference: event.target.value })} />
                    <div className="sm:col-span-3">
                      <Button variant="ghost" onClick={() => setPayments((current) => current.filter((_, at) => at !== index))}>
                        {t('hotel.remove')}
                      </Button>
                    </div>
                  </div>
                ))}
                <div>
                  <Button variant="secondary" icon="plus" onClick={addPayment}>
                    {t('hotel.frontDesk.addPayment')}
                  </Button>
                </div>
              </section>
            ) : null}
            <p className="text-body text-ink">
              {t('hotel.frontDesk.stillOwed')}: <Amount minor={remaining.toString()} currency={data.currency} tone={remaining > 0n ? 'danger' : undefined} />
            </p>
            {remaining > 0n && can(FOLIO_SETTLE_OPEN) ? <Switch label={t('hotel.frontDesk.leaveBalance')} checked={leave} onChange={setLeave} /> : null}
            {remaining > 0n && !can(FOLIO_SETTLE_OPEN) ? <p className="text-caption text-ink-muted">{t('hotel.frontDesk.balanceBlocks')}</p> : null}
            {can(FEE_WAIVE) ? <Switch label={t('hotel.frontDesk.waiveLate')} checked={waive} onChange={setWaive} /> : null}
          </>
        ) : null}
        {!stay.folio_id ? <Alert tone="warning" title={t('hotel.frontDesk.noFolio')} /> : null}
      </div>
    </Dialog>
  )
}

/** HOT-12: a guest at the desk now: booking and check-in in one step, in a room that is clean. */
export function WalkInDialog({ branchId, companyId, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const rooms = useRooms(branchId)
  const plans = useQuery({ queryKey: [...hotelKey, 'rate-plans', companyId], queryFn: () => api.get(`hotel/rate-plans?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const [guest, setGuest] = useState(BLANK_GUEST)
  const [roomId, setRoomId] = useState('')
  const [planId, setPlanId] = useState('')
  const [departure, setDeparture] = useState(addDays(todayIn(undefined), 1))
  const [adults, setAdults] = useState('1')
  const [children, setChildren] = useState('0')
  const [keys, setKeys] = useState('1')
  const [waive, setWaive] = useState(false)
  const [override, setOverride] = useState(false)
  const [rate, setRate] = useState('')
  const [reason, setReason] = useState('')
  const room = rooms.find((entry) => entry.id === roomId)
  const typePlans = (plans.data?.data ?? []).filter((plan) => plan.room_type?.id === room?.room_type?.id && plan.active)
  const plan = typePlans.find((entry) => entry.id === planId)
  const save = useMutation({
    mutationFn: () =>
      api.post('hotel/walk-ins', {
        branch_id: branchId,
        ...guestBody(guest),
        room_id: roomId,
        rate_plan_id: planId,
        departure_on: departure,
        adults: Number(adults) || 1,
        children: Number(children) || 0,
        keys_issued: Number(keys) || 0,
        ...(waive ? { waive_fees: true } : {}),
        ...(override && rate ? { rate_minor: rate, rate_reason: reason.trim() } : {}),
      }),
    onSuccess: async (response) => {
      toast.success(t('hotel.frontDesk.walkedIn'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
      navigate(`/hotel/reservations/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['name', 'phone', 'email', 'id_type', 'id_number', 'nationality', 'guest_id', 'room_id', 'rate_plan_id', 'departure_on', 'adults', 'children', 'rate_minor', 'rate_reason'], { 'guest.name': 'name', 'guest.phone': 'phone', 'guest.email': 'email', 'guest.id_type': 'id_type', 'guest.id_number': 'id_number', 'guest.nationality': 'nationality' })
  const ready = guestReady(guest) && roomId && planId && departure && (!override || (rate && reason.trim()))
  return (
    <Dialog open size="lg" title={t('hotel.frontDesk.walkInTitle')} onClose={onClose} footer={<Footer onClose={onClose} action={t('hotel.frontDesk.walkInAction')} loading={save.isPending} disabled={!ready} onConfirm={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <GuestField companyId={companyId} value={guest} onChange={setGuest} errors={errors.fields} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t('hotel.columns.room')} placeholder={t('hotel.pickers.room')} options={rooms.filter((entry) => entry.status === 'vacant_clean').map((entry) => ({ value: entry.id, label: roomLabel(entry) }))} value={roomId} required error={errors.fields.room_id} onChange={(event) => { setRoomId(event.target.value); setPlanId('') }} />
          <Select label={t('hotel.rates.plan')} placeholder={t('hotel.pickers.ratePlan')} options={typePlans.map((entry) => ({ value: entry.id, label: `${entry.name} · ${t(`hotel.boards.${entry.board}`)}` }))} value={planId} required disabled={!roomId} error={errors.fields.rate_plan_id} onChange={(event) => setPlanId(event.target.value)} />
          <TextField type="date" label={t('hotel.columns.departure_on')} value={departure} required error={errors.fields.departure_on} onChange={(event) => setDeparture(event.target.value)} />
          <TextField label={t('hotel.frontDesk.keys')} inputMode="numeric" value={keys} onChange={(event) => setKeys(event.target.value)} />
          <TextField label={t('hotel.fields.adults')} inputMode="numeric" value={adults} error={errors.fields.adults} onChange={(event) => setAdults(event.target.value)} />
          <TextField label={t('hotel.fields.children')} inputMode="numeric" value={children} error={errors.fields.children} onChange={(event) => setChildren(event.target.value)} />
        </div>
        {can(RATE_OVERRIDE) && plan ? (
          <div className="flex flex-col gap-3">
            <Switch label={t('hotel.reservations.overrideRate')} checked={override} onChange={setOverride} />
            {override ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <MoneyInput key={plan.currency} label={t('hotel.reservations.rateNight')} currency={plan.currency} value={rate} error={errors.fields.rate_minor} onChange={(next) => setRate(next ?? '')} />
                <TextField label={t('hotel.reservations.overrideReason')} value={reason} maxLength={500} required error={errors.fields.rate_reason} onChange={(event) => setReason(event.target.value)} />
              </div>
            ) : null}
          </div>
        ) : null}
        {can(FEE_WAIVE) ? <Switch label={t('hotel.frontDesk.waiveEarly')} checked={waive} onChange={setWaive} /> : null}
      </div>
    </Dialog>
  )
}
