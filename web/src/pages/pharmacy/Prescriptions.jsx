import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { CalendarDate, RecordStatus } from './PharmacyParts'
import { choiceField, dateField, locationFilter, locationOptions, pharmacyKey, PRESCRIPTION_CREATE, usePlaces } from './pharmacyData'

const BLANK = { location_id: '', prescriber_name: '', prescriber_licence: '', facility: '', patient_name: '', patient_phone: '', patient_birth_date: '', prescribed_on: '', notes: '' }
const REQUIRED = ['location_id', 'prescriber_name', 'prescriber_licence', 'facility', 'patient_name', 'prescribed_on']

/** PHA-14: record a prescription in the back office: who prescribed, for whom, when. The image is added on its page. */
function NewPrescription({ places, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(BLANK)
  const [submitted, setSubmitted] = useState(false)
  const set = (name) => (event) => setForm((current) => ({ ...current, [name]: event.target.value }))
  const save = useMutation({
    mutationFn: () => api.post('pharmacy/prescriptions', Object.fromEntries(Object.entries(form).filter(([, value]) => String(value).trim() !== '').map(([key, value]) => [key, String(value).trim()]))),
    onSuccess: async (response) => {
      toast.success(t('pharmacy.prescriptions.saved'))
      await queryClient.invalidateQueries({ queryKey: pharmacyKey })
      onClose()
      navigate(`/pharmacy/prescriptions/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, Object.keys(BLANK))
  const missing = (name) => (submitted && !String(form[name]).trim() ? t('pharmacy.validation.required') : undefined)
  const submit = () => {
    setSubmitted(true)
    if (REQUIRED.every((name) => String(form[name]).trim())) save.mutate()
  }
  return (
    <Dialog
      open
      size="lg"
      title={t('pharmacy.prescriptions.newTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={submit}>
            {t('pharmacy.prescriptions.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t('pharmacy.fields.location')} placeholder={t('pharmacy.pickers.location')} options={locationOptions(places.activeLocations)} value={form.location_id} required error={errors.fields.location_id ?? missing('location_id')} onChange={set('location_id')} className="sm:col-span-2" />
          <TextField label={t('pharmacy.columns.prescriber_name')} value={form.prescriber_name} maxLength={150} required error={errors.fields.prescriber_name ?? missing('prescriber_name')} onChange={set('prescriber_name')} />
          <TextField label={t('pharmacy.columns.prescriber_licence')} help={t('pharmacy.prescriptions.licenceHelp')} value={form.prescriber_licence} maxLength={60} required error={errors.fields.prescriber_licence ?? missing('prescriber_licence')} onChange={set('prescriber_licence')} />
          <TextField label={t('pharmacy.columns.facility')} value={form.facility} maxLength={150} required error={errors.fields.facility ?? missing('facility')} onChange={set('facility')} className="sm:col-span-2" />
          <TextField label={t('pharmacy.columns.patient_name')} value={form.patient_name} maxLength={150} required error={errors.fields.patient_name ?? missing('patient_name')} onChange={set('patient_name')} />
          <TextField label={t('pharmacy.prescriptions.patientPhone')} value={form.patient_phone} maxLength={30} error={errors.fields.patient_phone} onChange={set('patient_phone')} />
          <TextField type="date" label={t('pharmacy.prescriptions.patientBirthDate')} value={form.patient_birth_date} error={errors.fields.patient_birth_date} onChange={set('patient_birth_date')} />
          <TextField type="date" label={t('pharmacy.columns.prescribed_on')} value={form.prescribed_on} required error={errors.fields.prescribed_on ?? missing('prescribed_on')} onChange={set('prescribed_on')} />
          <TextField label={t('pharmacy.fields.note')} value={form.notes} maxLength={2000} error={errors.fields.notes} onChange={set('notes')} className="sm:col-span-2" />
        </div>
      </div>
    </Dialog>
  )
}

/** PHA-14, PHA-28: prescriptions at the places the user sees (personal data), newest first, with search, status and date filters, sort and export. */
export default function Prescriptions() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()
  const [adding, setAdding] = useState(false)
  const columns = [
    { key: 'prescribed_on', label: t('pharmacy.columns.prescribed_on'), sortKey: 'prescribed_on', hideable: false, render: (row) => <CalendarDate value={row.prescribed_on} /> },
    { key: 'patient_name', label: t('pharmacy.columns.patient_name'), sortKey: 'patient_name', render: (row) => <span className="font-medium text-ink">{row.patient_name}</span> },
    { key: 'prescriber_name', label: t('pharmacy.columns.prescriber_name'), render: (row) => row.prescriber_name },
    { key: 'prescriber_licence', label: t('pharmacy.columns.prescriber_licence'), render: (row) => <span className="font-mono">{row.prescriber_licence}</span> },
    { key: 'facility', label: t('pharmacy.columns.facility'), render: (row) => row.facility },
    { key: 'status', label: t('pharmacy.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    { key: 'source', label: t('pharmacy.columns.source'), render: (row) => t(`pharmacy.sources.${row.source}`) },
    { key: 'location', label: t('pharmacy.columns.location'), render: (row) => row.location?.name ?? '' },
  ]
  const list = useServerList({
    id: 'pharmacy-prescriptions',
    endpoint: 'pharmacy/prescriptions',
    queryKey: [...pharmacyKey, 'prescriptions'],
    filters: { status: '', location: '', from: '', to: '' },
    defaultSort: '-prescribed_on',
    columns,
  })
  return (
    <>
      <PageHeader
        title={t('pharmacy.prescriptions.title')}
        description={t('pharmacy.prescriptions.description')}
        actions={
          can(PRESCRIPTION_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
              {t('pharmacy.prescriptions.new')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('pharmacy.prescriptions.title')}
        searchLabel={t('pharmacy.prescriptions.search')}
        filterFields={[choiceField(t, 'status', ['valid', 'cancelled'], t('pharmacy.columns.status'), 'pharmacy.states'), ...locationFilter(t, places), dateField('from', t('pharmacy.filters.from')), dateField('to', t('pharmacy.filters.to'))]}
        emptyText={list.term || Object.values(list.filters).some(Boolean) ? t('pharmacy.prescriptions.emptyFiltered') : t('pharmacy.prescriptions.empty')}
        onRowClick={(row) => navigate(`/pharmacy/prescriptions/${row.id}`)}
      />
      {adding ? <NewPrescription places={places} onClose={() => setAdding(false)} /> : null}
    </>
  )
}
