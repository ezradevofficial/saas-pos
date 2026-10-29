import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, MoneyInput, MultiSelect, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { SlotPicker } from './AppointmentDialogs'
import { PlaceBar } from './SalonParts'
import { CLIENT_MANAGE, DEPOSIT_TAKE, queryString, salonError, salonKey, timeIn, today, usePaymentMethods, useSalonPlace, useServices, useStaff } from './salonData'

/** Staff who can do every chosen service (a service that names no staff is open to all; the server checks again). */
function qualifiedStaff(staff, services, serviceIds) {
  const chosen = services.filter((service) => serviceIds.includes(service.id))
  return staff.filter((member) => !member.archived_at && chosen.every((service) => !service.staff_ids?.length || service.staff_ids.includes(member.id)))
}

/** SAL-25: a client picked from the search, or added on the spot with a name and a phone or email. */
function ClientPicker({ company, client, onPick, canAdd }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [adding, setAdding] = useState(false)
  const [fresh, setFresh] = useState({ name: '', phone: '', email: '' })
  const found = useQuery({
    queryKey: [...salonKey, 'client-search', company?.id, search],
    queryFn: () => api.get(`salon/clients${queryString({ company_id: company?.id, search })}`),
    enabled: search.trim().length >= 2 && Boolean(company?.id) && !client,
  })
  const create = useMutation({
    mutationFn: () => api.post('salon/clients', { company_id: company.id, name: fresh.name.trim(), phone: fresh.phone.trim() || undefined, email: fresh.email.trim() || undefined }),
    onSuccess: (response) => {
      toast.success(t('salon.clients.created'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onPick({ id: response.data.party_id, name: response.data.name })
      setAdding(false)
    },
  })
  const errors = formErrors(create.error, ['name', 'phone', 'email'])
  const set = (field) => (event) => setFresh((current) => ({ ...current, [field]: event.target.value }))

  if (client) {
    return (
      <div className="flex items-center justify-between gap-3">
        <p className="text-ink">{client.name}</p>
        <Button variant="ghost" onClick={() => onPick(null)}>
          {t('salon.newAppointment.changeClient')}
        </Button>
      </div>
    )
  }
  return (
    <div className="flex flex-col gap-3">
      <TextField label={t('salon.book.findClient')} help={t('salon.newAppointment.findClientHelp')} value={search} onChange={(event) => setSearch(event.target.value)} />
      {(found.data?.data ?? []).length ? (
        <ul aria-label={t('salon.newAppointment.matches')} className="flex flex-col gap-1">
          {found.data.data.map((entry) => (
            <li key={entry.party_id}>
              <Button variant="ghost" aria-label={t('salon.newAppointment.pickNamed', { name: entry.name })} onClick={() => onPick({ id: entry.party_id, name: entry.name })}>
                {[entry.name, entry.phone].filter(Boolean).join(' · ')}
              </Button>
            </li>
          ))}
        </ul>
      ) : search.trim().length >= 2 && !found.isPending ? (
        <p className="text-caption text-ink-muted">{t('salon.clients.empty')}</p>
      ) : null}
      {canAdd && !adding ? (
        <div>
          <Button variant="secondary" icon="plus" onClick={() => setAdding(true)}>
            {t('salon.newAppointment.quickAdd')}
          </Button>
        </div>
      ) : null}
      {adding ? (
        <div className="flex flex-col gap-3 rounded-md border border-border p-3">
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <TextField label={t('salon.fields.name')} value={fresh.name} error={errors.fields.name} onChange={set('name')} />
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={fresh.phone} error={errors.fields.phone} onChange={set('phone')} />
            <TextField label={t('salon.clients.email')} type="email" value={fresh.email} error={errors.fields.email} onChange={set('email')} />
          </div>
          <div className="flex gap-2">
            <Button variant="primary" loading={create.isPending} disabled={!fresh.name.trim()} onClick={() => create.mutate()}>
              {t('salon.clients.add')}
            </Button>
            <Button variant="ghost" onClick={() => setAdding(false)}>
              {t('common.cancel')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}

/**
 * SAL-06, SAL-07, SAL-08, SAL-11: books an appointment in the back office. The open times come from
 * GET salon/availability (the staff, hours, breaks, time off, resources and existing bookings the online page uses);
 * the booking goes through POST salon/appointments, which refuses a taken time with 409 `slot_taken`, so two people
 * booking the same time get one booking. An optional deposit is taken right after, with the company's payment methods.
 */
export default function NewAppointment() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [params] = useSearchParams()
  const place = useSalonPlace()
  const { company, branch } = place
  const services = useServices(company?.id)
  const staff = useStaff(company?.id)
  const methods = usePaymentMethods(company?.id)
  const zone = company?.timezone
  const presetClient = params.get('client') ?? ''
  const [client, setClient] = useState(null)
  const [values, setValues] = useState(() => ({
    service_ids: (params.get('services') ?? '').split(',').filter(Boolean),
    staff_id: params.get('staff') ?? '',
    date: params.get('date') ?? '',
    notes: '',
    guest_name: '',
    guest_phone: '',
    deposit_method: '',
    deposit_amount: '',
  }))
  const [slot, setSlot] = useState(null)
  const profile = useQuery({
    queryKey: [...salonKey, 'client', presetClient],
    queryFn: () => api.get(`salon/clients/${presetClient}${queryString({ company_id: company?.id })}`),
    enabled: Boolean(presetClient && company?.id && !client),
  })
  const picked = client ?? (profile.data?.data ? { id: profile.data.data.party_id, name: profile.data.data.name } : null)
  const date = values.date || today(zone)
  const menu = services.rows.filter((service) => !service.archived_at)
  const stylists = qualifiedStaff(staff.rows, menu, values.service_ids)
  const staffId = stylists.some((member) => member.id === values.staff_id) ? values.staff_id : ''
  const change = (patch) => {
    setSlot(null)
    setValues((current) => ({ ...current, ...patch }))
  }
  const hasClient = Boolean(picked || values.guest_name.trim())
  const withDeposit = can(DEPOSIT_TAKE) && values.deposit_method && values.deposit_amount && values.deposit_amount !== '0'

  const book = useMutation({
    mutationFn: async () => {
      const made = await api.post('salon/appointments', {
        branch_id: branch.id,
        salon_client_id: picked?.id || undefined,
        guest_name: picked ? undefined : values.guest_name.trim(),
        guest_phone: picked || !values.guest_phone.trim() ? undefined : values.guest_phone.trim(),
        notes: values.notes.trim() || undefined,
        starts_at: slot.starts_at,
        lines: slot.lines.map((line) => ({ service_id: line.service_id, staff_id: line.staff_id, resource_id: line.resource_id })),
      })
      if (withDeposit) {
        try {
          await api.post(`salon/appointments/${made.data.id}/deposit`, { payment_method_id: values.deposit_method, amount_minor: values.deposit_amount })
        } catch (error) {
          // The booking stands; say what to do about the deposit.
          toast.error(t('salon.newAppointment.depositFailed', { reason: salonError(error) }))
        }
      }
      return made
    },
    onSuccess: () => {
      toast.success(t('salon.book.booked'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      navigate('/salon/calendar')
    },
    onError: () => {
      // A refusal (the time was just taken) clears the choice and reloads the open times.
      setSlot(null)
      queryClient.invalidateQueries({ queryKey: [...salonKey, 'availability'] })
    },
  })

  if (!company) return <p className="text-ink-muted">{t('salon.calendar.noBranch')}</p>

  return (
    <>
      <PageHeader title={t('salon.newAppointment.title')} description={t('salon.newAppointment.description')} />
      <div className="flex max-w-3xl flex-col gap-5">
        <PlaceBar place={place} />
        {!branch ? <p className="text-ink-muted">{t('salon.calendar.noBranch')}</p> : null}
        {book.isError ? <Alert tone="danger" title={salonError(book.error)} /> : null}
        <Card title={t('salon.newAppointment.clientStep')}>
          <ClientPicker company={company} client={picked} onPick={(next) => setClient(next)} canAdd={can(CLIENT_MANAGE)} />
          {picked ? null : (
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label={t('salon.book.guestName')} help={t('salon.newAppointment.guestHelp')} value={values.guest_name} onChange={(event) => setValues({ ...values, guest_name: event.target.value })} />
              <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={values.guest_phone} onChange={(event) => setValues({ ...values, guest_phone: event.target.value })} />
            </div>
          )}
        </Card>
        <Card title={t('salon.newAppointment.whenStep')}>
          <MultiSelect label={t('salon.book.services')} options={menu.map((service) => ({ value: service.id, label: service.name }))} value={values.service_ids} onChange={(next) => change({ service_ids: next })} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('salon.book.staff')}
              help={values.service_ids.length ? undefined : t('salon.newAppointment.staffHelp')}
              options={[{ value: '', label: t('salon.book.anyStaff') }, ...stylists.map((member) => ({ value: member.id, label: member.name }))]}
              value={staffId}
              onChange={(event) => change({ staff_id: event.target.value })}
            />
            <TextField label={t('salon.book.date')} type="date" value={date} onChange={(event) => change({ date: event.target.value })} />
          </div>
          {branch ? <SlotPicker branchId={branch.id} date={date} serviceIds={values.service_ids} staffId={staffId} zone={zone} slot={slot} onPick={setSlot} /> : null}
          {slot ? (
            <p className="text-caption text-ink-muted" data-testid="slot-summary">
              {slot.lines.map((line) => `${menu.find((service) => service.id === line.service_id)?.name ?? ''} · ${staff.rows.find((member) => member.id === line.staff_id)?.name ?? ''} · ${timeIn(line.starts_at, zone)}–${timeIn(line.ends_at, zone)}`).join('; ')}
            </p>
          ) : null}
        </Card>
        <Card title={t('salon.newAppointment.detailsStep')}>
          <TextAreaField label={t('salon.book.notes')} value={values.notes} maxLength={500} onChange={(event) => setValues({ ...values, notes: event.target.value })} />
          {can(DEPOSIT_TAKE) ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <Select label={t('salon.newAppointment.depositMethod')} placeholder={t('salon.newAppointment.noDeposit')} options={methods.map((entry) => ({ value: entry.id, label: entry.name }))} value={values.deposit_method} onChange={(event) => setValues({ ...values, deposit_method: event.target.value })} />
              <MoneyInput label={t('salon.newAppointment.depositAmount')} currency={company.base_currency} value={values.deposit_amount} onChange={(next) => setValues({ ...values, deposit_amount: next ?? '' })} />
            </div>
          ) : null}
        </Card>
        <div className="flex gap-2">
          <Button variant="primary" loading={book.isPending} disabled={!slot || !hasClient || !branch} onClick={() => book.mutate()}>
            {slot ? t('salon.book.confirmAt', { time: timeIn(slot.starts_at, zone) }) : t('salon.book.confirm')}
          </Button>
          <Button variant="ghost" onClick={() => navigate('/salon/calendar')}>
            {t('common.cancel')}
          </Button>
        </div>
      </div>
    </>
  )
}
