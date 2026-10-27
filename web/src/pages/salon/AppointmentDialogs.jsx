import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Money, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { ReasonDialog, Status } from './SalonParts'
import {
  APPOINTMENT_CANCEL,
  APPOINTMENT_EDIT,
  APPOINTMENT_SERVE,
  CLIENT_NOTES,
  DEPOSIT_REFUND,
  DEPOSIT_TAKE,
  FEE_WAIVE,
  queryString,
  salonError,
  salonKey,
  timeIn,
  usePaymentMethods,
} from './salonData'

/**
 * SAL-06, SAL-07: the open times for services on a day (GET salon/availability), picked as one button each; the same
 * list serves a new booking and a move. A refusal from the server (the time was just taken) shows and the list reloads.
 */
function SlotPicker({ branchId, date, serviceIds, staffId, zone, slot, onPick }) {
  const { t } = useTranslation()
  const ready = Boolean(branchId && date && serviceIds.length)
  const query = useQuery({
    queryKey: [...salonKey, 'availability', branchId, date, serviceIds.join(','), staffId],
    queryFn: () => api.get(`salon/availability${queryString({ branch_id: branchId, date, service_ids: serviceIds, staff_id: staffId })}`),
    enabled: ready,
  })
  const slots = query.data?.data?.slots ?? []
  if (!ready) return <p className="text-caption text-ink-muted">{t('salon.book.pickFirst')}</p>
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) return <Alert tone="danger" title={salonError(query.error)} />
  if (!slots.length) return <p className="text-ink-muted">{t('salon.book.noSlots')}</p>
  return (
    <div role="group" aria-label={t('salon.book.times')} className="flex flex-wrap gap-2">
      {slots.slice(0, 48).map((entry) => (
        <Button key={entry.starts_at} variant={slot?.starts_at === entry.starts_at ? 'primary' : 'secondary'} aria-pressed={slot?.starts_at === entry.starts_at} onClick={() => onPick(entry)}>
          {timeIn(entry.starts_at, zone)}
        </Button>
      ))}
    </div>
  )
}

/** SAL-07: books an appointment: the client, the services, a day and one of the open times. */
export function BookDialog({ branch, company, staff, services, zone, date: startDate, staffId: presetStaff = '', onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [clientSearch, setClientSearch] = useState('')
  const [values, setValues] = useState({ client_id: '', guest_name: '', guest_phone: '', service_ids: [], staff_id: presetStaff, date: startDate, notes: '' })
  const [slot, setSlot] = useState(null)
  const clients = useQuery({
    queryKey: [...salonKey, 'client-search', company?.id, clientSearch],
    queryFn: () => api.get(`salon/clients${queryString({ company_id: company?.id, search: clientSearch })}`),
    enabled: clientSearch.trim().length >= 2 && Boolean(company?.id),
  })
  const set = (field) => (event) => {
    setSlot(null)
    setValues((current) => ({ ...current, [field]: event.target.value }))
  }
  const book = useMutation({
    mutationFn: () =>
      api.post('salon/appointments', {
        branch_id: branch.id,
        salon_client_id: values.client_id || undefined,
        guest_name: values.client_id ? undefined : values.guest_name.trim(),
        guest_phone: values.client_id || !values.guest_phone.trim() ? undefined : values.guest_phone.trim(),
        notes: values.notes.trim() || undefined,
        starts_at: slot.starts_at,
        lines: slot.lines.map((line) => ({ service_id: line.service_id, staff_id: line.staff_id, resource_id: line.resource_id })),
      }),
    onSuccess: () => {
      toast.success(t('salon.book.booked'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onClose()
    },
    onError: () => {
      setSlot(null)
      queryClient.invalidateQueries({ queryKey: [...salonKey, 'availability'] })
    },
  })
  const hasClient = values.client_id || values.guest_name.trim()
  return (
    <Dialog
      open
      title={t('salon.book.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={book.isPending} disabled={!slot || !hasClient} onClick={() => book.mutate()}>
            {slot ? t('salon.book.confirmAt', { time: timeIn(slot.starts_at, zone) }) : t('salon.book.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {book.isError ? <Alert tone="danger" title={salonError(book.error)} /> : null}
        <TextField label={t('salon.book.findClient')} help={t('salon.book.findClientHelp')} value={clientSearch} onChange={(event) => setClientSearch(event.target.value)} />
        {(clients.data?.data ?? []).length ? (
          <Select label={t('salon.book.client')} placeholder={t('salon.book.pickClient')} options={[{ value: '', label: t('salon.book.guest') }, ...clients.data.data.map((client) => ({ value: client.party_id, label: client.name }))]} value={values.client_id} onChange={set('client_id')} />
        ) : null}
        {values.client_id ? null : (
          <div className="grid grid-cols-2 gap-4">
            <TextField label={t('salon.book.guestName')} value={values.guest_name} onChange={set('guest_name')} />
            <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={values.guest_phone} onChange={set('guest_phone')} />
          </div>
        )}
        <MultiSelect
          label={t('salon.book.services')}
          options={services.filter((service) => !service.archived_at).map((service) => ({ value: service.id, label: service.name }))}
          value={values.service_ids}
          onChange={(next) => {
            setSlot(null)
            setValues((current) => ({ ...current, service_ids: next }))
          }}
        />
        <div className="grid grid-cols-2 gap-4">
          <Select label={t('salon.book.staff')} options={[{ value: '', label: t('salon.book.anyStaff') }, ...staff.map((member) => ({ value: member.id, label: member.name }))]} value={values.staff_id} onChange={set('staff_id')} />
          <TextField label={t('salon.book.date')} type="date" value={values.date} onChange={set('date')} />
        </div>
        <SlotPicker branchId={branch.id} date={values.date} serviceIds={values.service_ids} staffId={values.staff_id} zone={zone} slot={slot} onPick={setSlot} />
        <TextField label={t('salon.book.notes')} value={values.notes} onChange={(event) => setValues((current) => ({ ...current, notes: event.target.value }))} />
      </div>
    </Dialog>
  )
}

/** SAL-09: moves an open appointment to another open time (the same services, the same checks). */
function MoveDialog({ appointment, branchId, zone, onClose, onDone }) {
  const { t } = useTranslation()
  const [date, setDate] = useState(appointment.starts_at.slice(0, 10))
  const [slot, setSlot] = useState(null)
  const serviceIds = appointment.lines.map((line) => line.service_id)
  const move = useMutation({
    mutationFn: () => api.post(`salon/appointments/${appointment.id}/move`, { starts_at: slot.starts_at, lines: appointment.lines.map((line, index) => ({ id: line.id, staff_id: slot.lines[index]?.staff_id ?? line.staff_id, resource_id: slot.lines[index]?.resource_id ?? line.resource_id })) }),
    onSuccess: onDone,
  })
  return (
    <Dialog
      open
      title={t('salon.move.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={move.isPending} disabled={!slot} onClick={() => move.mutate()}>
            {t('salon.move.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {move.isError ? <Alert tone="danger" title={salonError(move.error)} /> : null}
        <TextField
          label={t('salon.book.date')}
          type="date"
          value={date}
          onChange={(event) => {
            setSlot(null)
            setDate(event.target.value)
          }}
        />
        <SlotPicker branchId={branchId} date={date} serviceIds={serviceIds} staffId={appointment.lines[0]?.staff_id} zone={zone} slot={slot} onPick={setSlot} />
      </div>
    </Dialog>
  )
}

/** SAL-11: takes a deposit (cash, card or an M-Pesa push to the client's phone) or pays one back. */
function DepositDialog({ appointment, refund, onClose, onDone }) {
  const { t } = useTranslation()
  const methods = usePaymentMethods(appointment.company_id)
  const [values, setValues] = useState({ method: '', amount: '', phone: '', reference: '' })
  const method = methods.find((entry) => entry.id === values.method)
  const push = !refund && method?.type === 'mobile_money' && Boolean(method?.provider) && values.phone.trim() !== ''
  const save = useMutation({
    mutationFn: () =>
      api.post(`salon/appointments/${appointment.id}/deposit${refund ? '/refund' : ''}`, {
        payment_method_id: values.method,
        amount_minor: values.amount,
        ...(push ? { mode: 'stk', phone: values.phone.trim() } : {}),
        ...(values.reference.trim() ? { reference: values.reference.trim() } : {}),
      }),
    onSuccess: (response) => {
      if (response?.data?.status === 'pending') toast.info(t('salon.deposit.pending'))
      else toast.success(refund ? t('salon.deposit.refunded') : t('salon.deposit.taken'))
      onDone()
    },
  })
  return (
    <Dialog
      open
      title={refund ? t('salon.deposit.refundTitle') : t('salon.deposit.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.method || !values.amount || values.amount === '0'} onClick={() => save.mutate()}>
            {refund ? t('salon.deposit.refund') : push ? t('salon.deposit.send') : t('salon.deposit.take')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {save.isError ? <Alert tone="danger" title={salonError(save.error)} /> : null}
        <Select label={t('salon.deposit.method')} placeholder={t('salon.deposit.pickMethod')} options={methods.map((entry) => ({ value: entry.id, label: entry.name }))} value={values.method} onChange={(event) => setValues((current) => ({ ...current, method: event.target.value }))} />
        <MoneyInput label={t('salon.deposit.amount')} currency={appointment.currency} value={values.amount} onChange={(next) => setValues((current) => ({ ...current, amount: next ?? '' }))} />
        {!refund && method?.type === 'mobile_money' ? <TextField label={t('salon.deposit.phone')} help={t('salon.deposit.phoneHelp')} inputMode="tel" value={values.phone} onChange={(event) => setValues((current) => ({ ...current, phone: event.target.value }))} /> : null}
        <TextField label={t('salon.deposit.reference')} value={values.reference} onChange={(event) => setValues((current) => ({ ...current, reference: event.target.value }))} />
      </div>
    </Dialog>
  )
}

/** What the till rings for the appointment (SAL-16): lines, fee, deposit and packages. */
function Checkout({ id }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...salonKey, 'checkout', id], queryFn: () => api.get(`salon/appointments/${id}/checkout`) })
  const data = query.data?.data
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) return <Alert tone="danger" title={salonError(query.error)} />
  return (
    <div className="flex flex-col gap-2 rounded-md border border-border p-3">
      <h3 className="text-label text-ink">{t('salon.appointment.checkout')}</h3>
      <ul className="flex flex-col gap-1">
        {data.lines.map((line) => (
          <li key={line.line_id} className="flex justify-between gap-3">
            <span>{line.name}</span>
            <Money amount={line.price_minor} currency={data.currency} />
          </li>
        ))}
        {data.fee ? (
          <li className="flex justify-between gap-3">
            <span>{t(`salon.appointment.feeReasons.${data.fee.reason}`, { defaultValue: t('salon.appointment.fee') })}</span>
            <Money amount={data.fee.amount_minor} currency={data.currency} />
          </li>
        ) : null}
      </ul>
      <p className="flex justify-between gap-3 text-ink-muted">
        <span>{t('salon.appointment.depositHeld')}</span>
        <Money amount={data.deposit_balance_minor} currency={data.currency} />
      </p>
      {data.packages.map((pack) => (
        <p key={pack.id} className="text-caption text-ink-muted">
          {t('salon.appointment.packageLeft', { name: pack.name, count: pack.sessions_left })}
        </p>
      ))}
    </div>
  )
}

/** SAL-13, SAL-10, SAL-11: one appointment: its day (check in, start, finish), cancel or no-show, fee, deposit and notes. */
export function AppointmentDialog({ id, zone, branchId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...salonKey, 'appointment', id], queryFn: () => api.get(`salon/appointments/${id}`) })
  const [step, setStep] = useState(null)
  const [showCheckout, setShowCheckout] = useState(false)
  const [notes, setNotes] = useState(null)
  const appointment = query.data?.data
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: salonKey })
    setStep(null)
  }
  const act = useMutation({ mutationFn: (verb) => api.post(`salon/appointments/${id}/${verb}`, {}), onSuccess: refresh })
  const saveNotes = useMutation({
    mutationFn: () => api.put(`salon/appointments/${id}/notes`, { service_notes: notes.trim() || null }),
    onSuccess: () => {
      toast.success(t('salon.appointment.notesSaved'))
      setNotes(null)
      refresh()
    },
  })
  const open = appointment && ['booked', 'checked_in'].includes(appointment.status)
  const payColumns = [
    { key: 'kind', label: t('salon.appointment.paymentKind'), render: (row) => t(`salon.appointment.paymentKinds.${row.kind}`) },
    { key: 'amount', label: t('salon.appointment.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
  ]
  return (
    <>
      <Dialog open title={appointment ? t('salon.appointment.title', { number: appointment.number }) : t('salon.appointment.titleLoading')} onClose={onClose} footer={<Button onClick={onClose}>{t('salon.close')}</Button>}>
        <div className="flex flex-col gap-4 pt-1">
          {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
          {query.isError ? <Alert tone="danger" title={salonError(query.error)} /> : null}
          {act.isError ? <Alert tone="danger" title={salonError(act.error)} /> : null}
          {appointment ? (
            <>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-body-lg text-ink">{appointment.client_name}</p>
                  {appointment.guest_phone ? <p className="text-ink-muted">{appointment.guest_phone}</p> : null}
                </div>
                <Status status={appointment.status} />
              </div>
              <ul className="flex flex-col gap-2">
                {appointment.lines.map((line) => (
                  <li key={line.id} className="flex flex-wrap justify-between gap-3">
                    <span>
                      <span className="tabular-nums text-ink-muted">{timeIn(line.starts_at, zone)}</span> {line.service_name}
                      <span className="text-ink-muted">{` · ${line.staff_name}${line.resource_name ? ` · ${line.resource_name}` : ''}`}</span>
                    </span>
                    <Money amount={line.price_minor} currency={appointment.currency} />
                  </li>
                ))}
              </ul>
              <div className="flex flex-col gap-1">
                <p className="flex justify-between gap-3">
                  <span>{t('salon.appointment.total')}</span>
                  <Money amount={appointment.total_minor} currency={appointment.currency} />
                </p>
                {appointment.deposit_required_minor !== '0' ? (
                  <p className="flex justify-between gap-3 text-ink-muted">
                    <span>{t('salon.appointment.depositRequired')}</span>
                    <Money amount={appointment.deposit_required_minor} currency={appointment.currency} />
                  </p>
                ) : null}
                <p className="flex justify-between gap-3 text-ink-muted">
                  <span>{t('salon.appointment.depositHeld')}</span>
                  <Money amount={appointment.deposit_balance_minor} currency={appointment.currency} />
                </p>
                {appointment.fee_status !== 'none' ? (
                  <p className="flex items-center justify-between gap-3">
                    <span>
                      {t('salon.appointment.fee')} <Status status={appointment.fee_status} />
                    </span>
                    <Money amount={appointment.fee_minor} currency={appointment.currency} />
                  </p>
                ) : null}
              </div>
              <div className="flex flex-wrap gap-2">
                {can(APPOINTMENT_SERVE) && appointment.status === 'booked' ? (
                  <Button loading={act.isPending} onClick={() => act.mutate('check-in')}>
                    {t('salon.appointment.checkIn')}
                  </Button>
                ) : null}
                {can(APPOINTMENT_SERVE) && open ? (
                  <Button loading={act.isPending} onClick={() => act.mutate('start')}>
                    {t('salon.appointment.start')}
                  </Button>
                ) : null}
                {can(APPOINTMENT_SERVE) && appointment.status === 'in_service' ? (
                  <Button variant="primary" loading={act.isPending} onClick={() => act.mutate('finish')}>
                    {t('salon.appointment.finish')}
                  </Button>
                ) : null}
                {can(APPOINTMENT_EDIT) && open ? <Button onClick={() => setStep('move')}>{t('salon.appointment.move')}</Button> : null}
                {can(APPOINTMENT_CANCEL) && open ? <Button onClick={() => setStep('cancel')}>{t('salon.appointment.cancel')}</Button> : null}
                {can(APPOINTMENT_CANCEL) && appointment.status === 'booked' ? <Button onClick={() => setStep('no-show')}>{t('salon.appointment.noShow')}</Button> : null}
                {can(FEE_WAIVE) && appointment.fee_status === 'due' ? <Button onClick={() => setStep('waive')}>{t('salon.appointment.waiveFee')}</Button> : null}
                {can(DEPOSIT_TAKE) && open ? <Button onClick={() => setStep('deposit')}>{t('salon.appointment.takeDeposit')}</Button> : null}
                {can(DEPOSIT_REFUND) && Number(appointment.deposit_balance_minor) > 0 ? <Button onClick={() => setStep('refund')}>{t('salon.appointment.refundDeposit')}</Button> : null}
                <Button variant="ghost" onClick={() => setShowCheckout((current) => !current)}>
                  {showCheckout ? t('salon.appointment.hideCheckout') : t('salon.appointment.showCheckout')}
                </Button>
              </div>
              {showCheckout ? <Checkout id={id} /> : null}
              {(appointment.payments ?? []).length ? <DataTable caption={t('salon.appointment.payments')} columns={payColumns} rows={appointment.payments} /> : null}
              {can(CLIENT_NOTES) ? (
                <div className="flex flex-col gap-2">
                  <TextAreaField label={t('salon.appointment.notes')} help={t('salon.appointment.notesHelp')} value={notes ?? appointment.service_notes ?? ''} maxLength={2000} onChange={(event) => setNotes(event.target.value)} />
                  <div>
                    <Button loading={saveNotes.isPending} disabled={notes === null} onClick={() => saveNotes.mutate()}>
                      {t('salon.appointment.saveNotes')}
                    </Button>
                  </div>
                  {saveNotes.isError ? <Alert tone="danger" title={salonError(saveNotes.error)} /> : null}
                </div>
              ) : null}
            </>
          ) : null}
        </div>
      </Dialog>
      {step === 'move' ? <MoveDialog appointment={appointment} branchId={branchId} zone={zone} onClose={() => setStep(null)} onDone={refresh} /> : null}
      {step === 'cancel' ? <ReasonDialog title={t('salon.appointment.cancelTitle')} help={t('salon.appointment.cancelHelp')} label={t('salon.appointment.reason')} confirm={t('salon.appointment.cancelConfirm')} variant="danger" path={`salon/appointments/${id}/cancel`} onClose={() => setStep(null)} onDone={refresh} /> : null}
      {step === 'no-show' ? <ReasonDialog title={t('salon.appointment.noShowTitle')} help={t('salon.appointment.noShowHelp')} label={t('salon.appointment.reason')} confirm={t('salon.appointment.noShowConfirm')} variant="danger" path={`salon/appointments/${id}/no-show`} onClose={() => setStep(null)} onDone={refresh} /> : null}
      {step === 'waive' ? <ReasonDialog title={t('salon.appointment.waiveTitle')} label={t('salon.appointment.reason')} confirm={t('salon.appointment.waiveConfirm')} path={`salon/appointments/${id}/waive-fee`} onClose={() => setStep(null)} onDone={refresh} /> : null}
      {step === 'deposit' || step === 'refund' ? <DepositDialog appointment={appointment} refund={step === 'refund'} onClose={() => setStep(null)} onDone={refresh} /> : null}
    </>
  )
}
