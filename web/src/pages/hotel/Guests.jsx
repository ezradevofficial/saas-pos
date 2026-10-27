import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { Back, Day, Detail, DetailState } from './HotelParts'
import { GUEST_IDENTITY, GUEST_MANAGE, hotelKey, ID_TYPES } from './hotelData'

/** HOT-16: guests of the company the user sees; the ID number is masked here (HOT-38). */
export default function Guests() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const term = useDebounced(search.trim(), 300)
  const query = useQuery({ queryKey: [...hotelKey, 'guests', term], queryFn: () => api.get(`hotel/guests?per_page=50${term ? `&search=${encodeURIComponent(term)}` : ''}`), placeholderData: (previous) => previous })
  const columns = [
    { key: 'name', label: t('hotel.columns.name'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'phone', label: t('hotel.fields.phone'), render: (row) => row.phone ?? '' },
    { key: 'email', label: t('hotel.fields.email'), render: (row) => row.email ?? '' },
    { key: 'nationality', label: t('hotel.fields.nationality'), render: (row) => row.nationality ?? '' },
    { key: 'identity', label: t('hotel.fields.idNumber'), render: (row) => <span className="font-mono">{row.identity_document ?? ''}</span> },
  ]
  return (
    <>
      <PageHeader title={t('hotel.guests.title')} description={t('hotel.guests.description')} />
      <TextField type="search" label={t('hotel.guests.search')} value={search} autoComplete="off" onChange={(event) => setSearch(event.target.value)} className="max-w-sm" />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable caption={t('hotel.guests.title')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} stale={query.isPlaceholderData} emptyText={term ? t('hotel.guests.emptyFiltered') : t('hotel.guests.empty')} onRowClick={(row) => navigate(`/hotel/guests/${row.id}`)} />
    </>
  )
}

const FIELDS = ['name', 'phone', 'email', 'nationality', 'id_type', 'id_number', 'date_of_birth', 'address', 'notes']

/** HOT-16: change a guest. The ID number stays as it is unless a new one is typed. */
function EditGuest({ guest, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ name: guest.name ?? '', phone: guest.phone ?? '', email: guest.email ?? '', nationality: guest.nationality ?? '', id_type: guest.id_type ?? '', id_number: '', date_of_birth: guest.date_of_birth ?? '', address: guest.address ?? '', notes: guest.notes ?? '' })
  const set = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { name: form.name.trim() }
      for (const key of ['phone', 'email', 'nationality', 'date_of_birth', 'address', 'notes']) {
        if (key in guest || key === 'nationality' || key === 'notes') body[key] = form[key].trim() || null
      }
      if (form.id_type) body.id_type = form.id_type
      if (form.id_number.trim()) body.id_number = form.id_number.trim()
      return api.patch(`hotel/guests/${guest.id}`, body)
    },
    onSuccess: async () => {
      toast.success(t('hotel.guests.saved'))
      await queryClient.invalidateQueries({ queryKey: hotelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)
  return (
    <Dialog
      open
      size="lg"
      title={t('hotel.guests.edit')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.name.trim()} onClick={() => save.mutate()}>
            {t('hotel.guests.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('hotel.fields.guestName')} value={form.name} maxLength={160} required error={errors.fields.name} onChange={set('name')} className="sm:col-span-2" />
          {'phone' in guest ? <TextField label={t('hotel.fields.phone')} value={form.phone} maxLength={30} error={errors.fields.phone} onChange={set('phone')} /> : null}
          {'email' in guest ? <TextField type="email" label={t('hotel.fields.email')} value={form.email} maxLength={190} error={errors.fields.email} onChange={set('email')} /> : null}
          <TextField label={t('hotel.fields.nationality')} value={form.nationality} maxLength={60} error={errors.fields.nationality} onChange={set('nationality')} />
          <Select label={t('hotel.fields.idType')} placeholder={t('hotel.pickers.idType')} options={ID_TYPES.map((type) => ({ value: type, label: t(`hotel.idTypes.${type}`) }))} value={form.id_type} error={errors.fields.id_type} onChange={set('id_type')} />
          {'identity_document' in guest ? <TextField label={t('hotel.fields.idNumber')} help={t('hotel.guests.idKeep')} value={form.id_number} maxLength={60} autoComplete="off" error={errors.fields.id_number} onChange={set('id_number')} /> : null}
          {guest.date_of_birth !== null && guest.date_of_birth !== undefined ? <TextField type="date" label={t('hotel.fields.dateOfBirth')} value={form.date_of_birth} error={errors.fields.date_of_birth} onChange={set('date_of_birth')} /> : null}
          {guest.address !== null && guest.address !== undefined ? <TextField label={t('hotel.fields.address')} value={form.address} maxLength={500} error={errors.fields.address} onChange={set('address')} className="sm:col-span-2" /> : null}
          <TextField label={t('hotel.fields.notes')} value={form.notes} maxLength={1000} error={errors.fields.notes} onChange={set('notes')} className="sm:col-span-2" />
        </div>
      </div>
    </Dialog>
  )
}

/**
 * HOT-16, HOT-38: a guest. The ID number is masked; "Reveal" asks for it in
 * full and needs `hotel.guest.identity` (the API records who looked). The full
 * number is kept in this page's state only, never in the query cache.
 */
export function GuestDetail() {
  const { t } = useTranslation()
  const { guestId } = useParams()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(false)
  const [revealed, setRevealed] = useState(null)
  const query = useQuery({ queryKey: [...hotelKey, 'guest', guestId], queryFn: () => api.get(`hotel/guests/${guestId}`) })
  const reveal = useMutation({
    mutationFn: () => api.get(`hotel/guests/${guestId}?reveal=1`),
    onSuccess: (response) => setRevealed(response.data.identity_document),
  })
  const back = <Back to="/hotel/guests">{t('hotel.guests.back')}</Back>
  const guest = query.data?.data
  if (!guest) return <DetailState query={query} back={back} notFound={t('hotel.guests.notFound')} />
  return (
    <>
      {back}
      <PageHeader
        title={guest.name}
        description={t('hotel.guests.detailDescription')}
        actions={
          can(GUEST_MANAGE) ? (
            <Button icon="edit" onClick={() => setEditing(true)}>
              {t('hotel.guests.edit')}
            </Button>
          ) : null
        }
      />
      <Card title={t('hotel.guests.details')}>
        <dl className="grid gap-4 sm:grid-cols-3">
          {'phone' in guest ? <Detail label={t('hotel.fields.phone')}>{guest.phone ?? '—'}</Detail> : null}
          {'email' in guest ? <Detail label={t('hotel.fields.email')}>{guest.email ?? '—'}</Detail> : null}
          <Detail label={t('hotel.fields.nationality')}>{guest.nationality ?? '—'}</Detail>
          {'identity_document' in guest ? (
            <Detail label={t('hotel.fields.idNumber')}>
              <span className="flex flex-wrap items-center gap-3">
                <span className="font-mono">{revealed ?? guest.identity_document ?? '—'}</span>
                {guest.has_identity_document && can(GUEST_IDENTITY) && revealed === null ? (
                  <Button variant="secondary" loading={reveal.isPending} onClick={() => reveal.mutate()}>
                    {t('hotel.guests.reveal')}
                  </Button>
                ) : null}
                {revealed !== null ? (
                  <Button variant="ghost" onClick={() => setRevealed(null)}>
                    {t('hotel.guests.hide')}
                  </Button>
                ) : null}
              </span>
              {reveal.error ? <span className="mt-1 block text-caption text-danger">{errorMessage(reveal.error)}</span> : null}
              {revealed !== null ? <span className="mt-1 block text-caption text-ink-muted">{t('hotel.guests.revealAudited')}</span> : null}
            </Detail>
          ) : null}
          <Detail label={t('hotel.fields.idType')}>{guest.id_type ? t(`hotel.idTypes.${guest.id_type}`) : '—'}</Detail>
          {guest.date_of_birth !== null && guest.date_of_birth !== undefined ? <Detail label={t('hotel.fields.dateOfBirth')}><Day value={guest.date_of_birth} /></Detail> : null}
          {guest.address ? <Detail label={t('hotel.fields.address')}>{guest.address}</Detail> : null}
          {guest.notes ? <Detail label={t('hotel.fields.notes')}>{guest.notes}</Detail> : null}
        </dl>
        <Link to={`/hotel/reservations?search=${encodeURIComponent(guest.name)}&status=all`} className="mt-4 block w-fit text-label text-primary hover:text-primary-hover">
          {t('hotel.guests.stays')}
        </Link>
      </Card>
      {editing ? <EditGuest guest={guest} onClose={() => setEditing(false)} /> : null}
    </>
  )
}
