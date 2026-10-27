import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { addDays, BLANK_GUEST, guestBody, guestReady, hotelKey, RATE_OVERRIDE, SOURCES, useBranchChoice } from './hotelData'
import { BranchSwitch, GuestField, NoProperty, RateQuote } from './HotelParts'

const blankLine = () => ({ room_type_id: '', rate_plan_id: '', rooms: '1', adults: '2', children: '0', override: false, rate_minor: '', rate_reason: '' })

/** One room line of a booking: type, plan, party and, for a user allowed to, a typed rate with a reason (HOT-10). */
function Line({ index, line, group, types, plans, currency, arrival, departure, canOverride, errors, onChange, onRemove, removable }) {
  const { t } = useTranslation()
  const typePlans = plans.filter((plan) => plan.room_type?.id === line.room_type_id && plan.active)
  const plan = plans.find((entry) => entry.id === line.rate_plan_id)
  const set = (name) => (event) => onChange({ ...line, [name]: event.target.value })
  const fieldError = (name) => errors?.[`stays.${index}.${name}`]?.[0]
  return (
    <div className="flex flex-col gap-3 rounded-md border border-border p-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <Select
          label={t('hotel.columns.room_type')}
          placeholder={t('hotel.pickers.roomType')}
          options={types.map((type) => ({ value: type.id, label: type.name }))}
          value={line.room_type_id}
          required
          error={fieldError('room_type_id')}
          onChange={(event) => onChange({ ...line, room_type_id: event.target.value, rate_plan_id: '' })}
        />
        <Select
          label={t('hotel.rates.plan')}
          placeholder={t('hotel.pickers.ratePlan')}
          options={typePlans.map((entry) => ({ value: entry.id, label: `${entry.name} · ${t(`hotel.boards.${entry.board}`)}` }))}
          value={line.rate_plan_id}
          required
          disabled={!line.room_type_id}
          error={fieldError('rate_plan_id')}
          onChange={set('rate_plan_id')}
        />
        <TextField label={t('hotel.fields.adults')} inputMode="numeric" value={line.adults} error={fieldError('adults')} onChange={set('adults')} />
        <TextField label={t('hotel.fields.children')} inputMode="numeric" value={line.children} error={fieldError('children')} onChange={set('children')} />
        {group ? <TextField label={t('hotel.fields.rooms')} inputMode="numeric" value={line.rooms} error={fieldError('rooms')} onChange={set('rooms')} /> : null}
      </div>
      {canOverride && plan ? (
        <div className="flex flex-col gap-3">
          <Switch label={t('hotel.reservations.overrideRate')} checked={line.override} onChange={(next) => onChange({ ...line, override: next })} />
          {line.override ? (
            <div className="grid gap-3 sm:grid-cols-2">
              <MoneyInput key={plan.currency} label={t('hotel.reservations.rateNight')} currency={plan.currency ?? currency} value={line.rate_minor} error={fieldError('rate_minor')} onChange={(next) => onChange({ ...line, rate_minor: next ?? '' })} />
              <TextField label={t('hotel.reservations.overrideReason')} value={line.rate_reason} maxLength={500} required error={fieldError('rate_reason')} onChange={set('rate_reason')} />
            </div>
          ) : null}
        </div>
      ) : null}
      <RateQuote planId={line.rate_plan_id} arrival={arrival} departure={departure} adultCount={Number(line.adults) || 1} childCount={Number(line.children) || 0} rooms={group ? Number(line.rooms) || 1 : 1} />
      {removable ? (
        <div>
          <Button variant="ghost" onClick={onRemove}>
            {t('hotel.reservations.removeLine')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

/**
 * HOT-12: a new reservation: the guest (found or added here), dates, one room
 * line, or many for a group with an optional master folio. The rate quote
 * shows live; a typed rate needs `hotel.rate.override` and a reason. The
 * deposit is taken from the reservation once it exists (HOT-14).
 */
export default function NewReservation() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const choice = useBranchChoice()
  const companyId = choice.companyId
  const today = todayIn(choice.timezone)
  const [kind, setKind] = useState('direct')
  const [guest, setGuest] = useState(BLANK_GUEST)
  const [arrival, setArrival] = useState(today)
  const [departure, setDeparture] = useState(addDays(today, 1))
  const [lines, setLines] = useState([blankLine()])
  const [groupName, setGroupName] = useState('')
  const [masterFolio, setMasterFolio] = useState(true)
  const [source, setSource] = useState('direct')
  const [notes, setNotes] = useState('')
  const types = useQuery({ queryKey: [...hotelKey, 'room-types', companyId], queryFn: () => api.get(`hotel/room-types?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const plans = useQuery({ queryKey: [...hotelKey, 'rate-plans', companyId], queryFn: () => api.get(`hotel/rate-plans?company=${companyId}&status=active`), enabled: Boolean(companyId) })
  const group = kind === 'group'
  const save = useMutation({
    mutationFn: () =>
      api.post('hotel/reservations', {
        branch_id: choice.branch.id,
        kind,
        ...guestBody(guest),
        ...(group ? { group_name: groupName.trim(), master_folio: masterFolio } : {}),
        source,
        ...(notes.trim() ? { notes: notes.trim() } : {}),
        stays: (group ? lines : lines.slice(0, 1)).map((line) => ({
          room_type_id: line.room_type_id,
          rate_plan_id: line.rate_plan_id,
          arrival_on: arrival,
          departure_on: departure,
          adults: Number(line.adults) || 1,
          children: Number(line.children) || 0,
          ...(group ? { rooms: Number(line.rooms) || 1, bill_to_master: masterFolio } : {}),
          ...(line.override && line.rate_minor ? { rate_minor: line.rate_minor, rate_reason: line.rate_reason.trim() } : {}),
        })),
      }),
    onSuccess: async (response) => {
      toast.success(t('hotel.reservations.created'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      navigate(`/hotel/reservations/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['name', 'phone', 'email', 'id_type', 'id_number', 'nationality', 'guest_id', 'group_name', 'source', 'notes'], { 'guest.name': 'name', 'guest.phone': 'phone', 'guest.email': 'email', 'guest.id_type': 'id_type', 'guest.id_number': 'id_number', 'guest.nationality': 'nationality' })
  const ready = choice.branch && guestReady(guest) && departure > arrival && lines.every((line) => line.room_type_id && line.rate_plan_id) && (!group || groupName.trim())
  const canOverride = can(RATE_OVERRIDE)
  return (
    <>
      <PageHeader title={t('hotel.reservations.newTitle')} description={t('hotel.reservations.newDescription')} />
      <BranchSwitch choice={choice} />
      {!choice.branch ? <NoProperty choice={choice} /> : null}
      {choice.branch ? (
        <form
          className="flex max-w-3xl flex-col gap-5"
          onSubmit={(event) => {
            event.preventDefault()
            if (ready) save.mutate()
          }}
        >
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Card title={t('hotel.reservations.stayCard')}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Select label={t('hotel.reservations.kind')} options={['direct', 'group'].map((entry) => ({ value: entry, label: t(`hotel.kinds.${entry}`) }))} value={kind} onChange={(event) => setKind(event.target.value)} />
              <Select label={t('hotel.fields.source')} options={SOURCES.map((entry) => ({ value: entry, label: t(`hotel.sources.${entry}`) }))} value={source} error={errors.fields.source} onChange={(event) => setSource(event.target.value)} />
              <TextField type="date" label={t('hotel.columns.arrival_on')} value={arrival} required onChange={(event) => setArrival(event.target.value)} />
              <TextField type="date" label={t('hotel.columns.departure_on')} value={departure} required error={departure <= arrival ? t('hotel.reservations.departureAfter') : undefined} onChange={(event) => setDeparture(event.target.value)} />
              {group ? <TextField label={t('hotel.fields.groupName')} value={groupName} maxLength={120} required error={errors.fields.group_name} onChange={(event) => setGroupName(event.target.value)} className="sm:col-span-2" /> : null}
              {group ? <Switch label={t('hotel.reservations.masterFolio')} checked={masterFolio} onChange={setMasterFolio} className="sm:col-span-2" /> : null}
            </div>
          </Card>
          <Card title={group ? t('hotel.reservations.contact') : t('hotel.fields.guest')}>
            <GuestField companyId={companyId} value={guest} onChange={setGuest} errors={errors.fields} />
          </Card>
          <Card title={group ? t('hotel.reservations.roomLines') : t('hotel.reservations.room')}>
            <div className="flex flex-col gap-4">
              {(group ? lines : lines.slice(0, 1)).map((line, index) => (
                <Line
                  key={index}
                  index={index}
                  line={line}
                  group={group}
                  types={types.data?.data ?? []}
                  plans={plans.data?.data ?? []}
                  currency={choice.currency}
                  arrival={arrival}
                  departure={departure}
                  canOverride={canOverride}
                  errors={save.error?.errors}
                  removable={group && lines.length > 1}
                  onChange={(next) => setLines((current) => current.map((entry, at) => (at === index ? next : entry)))}
                  onRemove={() => setLines((current) => current.filter((_, at) => at !== index))}
                />
              ))}
              {group ? (
                <div>
                  <Button variant="secondary" icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>
                    {t('hotel.reservations.addLine')}
                  </Button>
                </div>
              ) : null}
            </div>
          </Card>
          <TextField label={t('hotel.fields.notes')} value={notes} maxLength={2000} error={errors.fields.notes} onChange={(event) => setNotes(event.target.value)} />
          <p className="text-caption text-ink-muted">{t('hotel.reservations.depositLater')}</p>
          <div className="flex gap-2">
            <Button variant="ghost" onClick={() => navigate('/hotel/reservations')}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!ready}>
              {t('hotel.reservations.create')}
            </Button>
          </div>
        </form>
      ) : null}
    </>
  )
}
