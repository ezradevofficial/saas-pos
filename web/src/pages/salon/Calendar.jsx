import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { AppointmentDialog, BookDialog } from './AppointmentDialogs'
import { PlaceBar, Status } from './SalonParts'
import { APPOINTMENT_CREATE, QUEUE_MANAGE, queryString, salonError, salonKey, timeIn, today, useSalonPlace, useServices } from './salonData'

/** "09:00–13:00, 14:00–17:00" for spans of instants. */
function spanText(spans, zone) {
  return spans.map(([from, to]) => `${timeIn(from, zone)}–${timeIn(to, zone)}`).join(', ')
}

/** One staff member's day: when they work and are off, and their appointments in time order. */
function StaffColumn({ member, appointments, zone, canBook, onBook, onOpen }) {
  const { t } = useTranslation()
  const mine = appointments
    .flatMap((appointment) => appointment.lines.filter((line) => line.staff_id === member.id).map((line) => ({ appointment, line })))
    .sort((a, b) => a.line.starts_at.localeCompare(b.line.starts_at))
  return (
    <section aria-label={member.name} className="flex min-w-56 flex-1 flex-col gap-3 rounded-md border border-border bg-surface-200 p-3">
      <header className="flex items-start justify-between gap-2">
        <div>
          <h2 className="text-label text-ink">{member.name}</h2>
          {member.title ? <p className="text-caption text-ink-muted">{member.title}</p> : null}
        </div>
        {canBook ? (
          <Button variant="ghost" aria-label={t('salon.calendar.bookWith', { name: member.name })} onClick={() => onBook(member.id)}>
            {t('salon.calendar.book')}
          </Button>
        ) : null}
      </header>
      <p className="text-caption text-ink-muted">{member.work.length ? t('salon.calendar.works', { spans: spanText(member.work, zone) }) : t('salon.calendar.notWorking')}</p>
      {member.breaks?.length ? <p className="text-caption text-ink-muted">{t('salon.calendar.breaks', { spans: spanText(member.breaks, zone) })}</p> : null}
      {member.time_off?.length ? <p className="text-caption text-ink-muted">{t('salon.calendar.timeOff', { spans: spanText(member.time_off, zone) })}</p> : null}
      <ul className="flex flex-col gap-2">
        {mine.map(({ appointment, line }) => (
          <li key={line.id}>
            <button type="button" onClick={() => onOpen(appointment.id)} className="flex min-h-12 w-full flex-col gap-1 rounded-md border border-border bg-surface-100 p-2 text-start hover:border-ink-muted focus-visible:outline-2 focus-visible:outline-focus">
              <span className="flex justify-between gap-2">
                <span className="tabular-nums text-ink">
                  {timeIn(line.starts_at, zone)}–{timeIn(line.ends_at, zone)}
                </span>
                <Status status={appointment.status} />
              </span>
              <span className="text-ink">{line.service_name}</span>
              <span className="text-caption text-ink-muted">{appointment.client_name}</span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** SAL-12: a walk-in joins the queue: a name, the services wanted and maybe a preferred staff member. */
function QueueDialog({ branch, company, services, staff, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ guest_name: '', guest_phone: '', service_ids: [], staff_id: '' })
  const save = useMutation({
    mutationFn: () => api.post('salon/queue', { branch_id: branch.id, guest_name: values.guest_name.trim(), guest_phone: values.guest_phone.trim() || undefined, service_ids: values.service_ids, staff_id: values.staff_id || undefined }),
    onSuccess: () => {
      toast.success(t('salon.queue.added'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('salon.queue.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.guest_name.trim() || !values.service_ids.length} onClick={() => save.mutate()}>
            {t('salon.queue.add')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {save.isError ? <Alert tone="danger" title={salonError(save.error)} /> : null}
        <TextField label={t('salon.book.guestName')} value={values.guest_name} onChange={(event) => setValues((current) => ({ ...current, guest_name: event.target.value }))} />
        <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={values.guest_phone} onChange={(event) => setValues((current) => ({ ...current, guest_phone: event.target.value }))} />
        <MultiSelect label={t('salon.book.services')} options={services.filter((service) => !service.archived_at).map((service) => ({ value: service.id, label: service.name }))} value={values.service_ids} onChange={(next) => setValues((current) => ({ ...current, service_ids: next }))} />
        <Select label={t('salon.queue.preferred')} options={[{ value: '', label: t('salon.book.anyStaff') }, ...staff.map((member) => ({ value: member.id, label: member.name }))]} value={values.staff_id} onChange={(event) => setValues((current) => ({ ...current, staff_id: event.target.value }))} />
        <p className="text-caption text-ink-muted">{t('salon.queue.company', { name: company?.name ?? '' })}</p>
      </div>
    </Dialog>
  )
}

/** SAL-12: seat a waiting walk-in with a staff member who is free now. */
function SeatDialog({ entry, staff, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [staffId, setStaffId] = useState(entry.staff_id ?? '')
  const seat = useMutation({
    mutationFn: () => api.post(`salon/queue/${entry.id}/seat`, { staff_id: staffId }),
    onSuccess: () => {
      toast.success(t('salon.queue.seated'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('salon.queue.seatTitle', { name: entry.guest_name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={seat.isPending} disabled={!staffId} onClick={() => seat.mutate()}>
            {t('salon.queue.seat')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {seat.isError ? <Alert tone="danger" title={salonError(seat.error)} /> : null}
        <Select label={t('salon.book.staff')} placeholder={t('salon.queue.pickStaff')} options={staff.map((member) => ({ value: member.id, label: member.name }))} value={staffId} onChange={(event) => setStaffId(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** SAL-12: the walk-in queue of the branch. */
function QueuePanel({ branch, company, staff, services }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manage = can(QUEUE_MANAGE)
  const query = useQuery({ queryKey: [...salonKey, 'queue', branch.id], queryFn: () => api.get(`salon/queue${queryString({ branch_id: branch.id })}`) })
  const [dialog, setDialog] = useState(null)
  const leave = useMutation({ mutationFn: (id) => api.post(`salon/queue/${id}/leave`, {}), onSuccess: () => queryClient.invalidateQueries({ queryKey: salonKey }) })
  const names = Object.fromEntries(services.map((service) => [service.id, service.name]))
  const rows = query.data?.data ?? []
  return (
    <Card
      title={t('salon.queue.title')}
      subtitle={t('salon.queue.subtitle')}
      actions={
        manage ? (
          <Button icon="plus" onClick={() => setDialog({ type: 'add' })}>
            {t('salon.queue.add')}
          </Button>
        ) : null
      }
    >
      {query.isError ? <Alert tone="danger" title={salonError(query.error)} /> : null}
      {!rows.length && !query.isPending ? <p className="text-ink-muted">{t('salon.queue.empty')}</p> : null}
      <ol className="flex flex-col gap-3">
        {rows.map((entry) => (
          <li key={entry.id} className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-ink">{`${entry.position}. ${entry.guest_name}`}</p>
              <p className="text-caption text-ink-muted">
                {entry.service_ids.map((id) => names[id] ?? '').filter(Boolean).join(', ')}
                {entry.wait_minutes != null ? ` · ${t('salon.queue.wait', { count: entry.wait_minutes })}` : ''}
              </p>
            </div>
            {manage ? (
              <div className="flex gap-2">
                <Button aria-label={t('salon.queue.seatNamed', { name: entry.guest_name })} onClick={() => setDialog({ type: 'seat', entry })}>
                  {t('salon.queue.seat')}
                </Button>
                <Button variant="ghost" aria-label={t('salon.queue.leaveNamed', { name: entry.guest_name })} loading={leave.isPending} onClick={() => leave.mutate(entry.id)}>
                  {t('salon.queue.leave')}
                </Button>
              </div>
            ) : null}
          </li>
        ))}
      </ol>
      {dialog?.type === 'add' ? <QueueDialog branch={branch} company={company} services={services} staff={staff} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'seat' ? <SeatDialog entry={dialog.entry} staff={staff} onClose={() => setDialog(null)} /> : null}
    </Card>
  )
}

/** SAL-07, SAL-12, SAL-13: the day's calendar by staff member, booking, the day's flow and the walk-in queue. */
export default function Calendar() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const place = useSalonPlace()
  const navigate = useNavigate()
  const branch = place.branch
  const [date, setDate] = useState('')
  const [booking, setBooking] = useState(null)
  const [openId, setOpenId] = useState(null)
  const services = useServices(place.company?.id)
  const zoneGuess = place.company?.timezone
  const day = date || today(zoneGuess)
  const query = useQuery({ queryKey: [...salonKey, 'calendar', branch?.id, day], queryFn: () => api.get(`salon/calendar${queryString({ branch_id: branch.id, date: day })}`), enabled: Boolean(branch), refetchInterval: 60_000 })
  const data = query.data?.data
  const zone = data?.timezone ?? zoneGuess
  const shift = (days) => {
    const next = new Date(`${day}T12:00:00Z`)
    next.setUTCDate(next.getUTCDate() + days)
    setDate(next.toISOString().slice(0, 10))
  }
  return (
    <>
      <PageHeader
        title={t('salon.calendar.title')}
        description={t('salon.calendar.description')}
        actions={
          branch && can(APPOINTMENT_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate(`/salon/appointments/new?date=${day}`)}>
              {t('salon.calendar.newBooking')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-end gap-4">
          <PlaceBar place={place} />
          <div className="flex items-end gap-2">
            <Button aria-label={t('salon.calendar.previous')} onClick={() => shift(-1)}>
              {t('salon.calendar.previous')}
            </Button>
            <TextField label={t('salon.book.date')} type="date" value={day} onChange={(event) => setDate(event.target.value)} />
            <Button aria-label={t('salon.calendar.next')} onClick={() => shift(1)}>
              {t('salon.calendar.next')}
            </Button>
          </div>
        </div>
        {!branch ? <p className="text-ink-muted">{t('salon.calendar.noBranch')}</p> : null}
        {query.isPending && branch ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isError ? <Alert tone="danger" title={salonError(query.error)} /> : null}
        {data && !data.staff.length ? <p className="text-ink-muted">{t('salon.calendar.noStaff')}</p> : null}
        {data ? (
          <div className="flex flex-wrap gap-3">
            {data.staff.map((member) => (
              <StaffColumn key={member.id} member={member} appointments={data.appointments} zone={zone} canBook={can(APPOINTMENT_CREATE)} onBook={(staffId) => setBooking({ staffId })} onOpen={setOpenId} />
            ))}
          </div>
        ) : null}
        {branch ? <QueuePanel branch={branch} company={place.company} staff={data?.staff ?? []} services={services.rows} /> : null}
      </div>
      {booking && branch ? <BookDialog branch={branch} company={place.company} staff={data?.staff ?? []} services={services.rows} zone={zone} date={day} staffId={booking.staffId} onClose={() => setBooking(null)} /> : null}
      {openId ? <AppointmentDialog id={openId} zone={zone} branchId={branch?.id} onClose={() => setOpenId(null)} /> : null}
    </>
  )
}
