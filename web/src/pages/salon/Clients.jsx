import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Money, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { ActionsLabel, PlaceBar, QueryNotes, ReasonDialog, Status, When } from './SalonParts'
import { CLIENT_MANAGE, CLIENT_NOTES, EXCEPTION_RESOLVE, PACKAGE_ADJUST, queryString, salonError, salonKey, useSalonPlace, useStaff } from './salonData'

/** SAL-25: a client's profile and visit history. Allergies and formulas show only when the API sends them. */
function ClientDialog({ partyId, company, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const staff = useStaff(company.id).rows.filter((entry) => !entry.archived_at)
  const profile = useQuery({ queryKey: [...salonKey, 'client', partyId], queryFn: () => api.get(`salon/clients/${partyId}?company_id=${company.id}`) })
  const visits = useQuery({ queryKey: [...salonKey, 'visits', partyId], queryFn: () => api.get(`salon/clients/${partyId}/visits?company_id=${company.id}`) })
  const [edits, setEdits] = useState({})
  const client = profile.data?.data
  const value = (field) => edits[field] ?? client?.[field] ?? ''
  const shows = (field) => client && field in client && (!['allergies', 'formulas'].includes(field) || can(CLIENT_NOTES))
  const save = useMutation({
    mutationFn: () => api.put(`salon/clients/${partyId}`, { company_id: company.id, ...edits, ...(edits.preferred_staff_id === '' ? { preferred_staff_id: null } : {}) }),
    onSuccess: () => {
      toast.success(t('salon.clients.saved'))
      setEdits({})
      queryClient.invalidateQueries({ queryKey: salonKey })
    },
  })
  const errors = formErrors(save.error, ['preferred_staff_id', 'preferences', 'allergies', 'formulas', 'notes'])
  const canEdit = can(CLIENT_MANAGE)
  const set = (field) => (event) => setEdits((current) => ({ ...current, [field]: event.target.value }))
  const visitColumns = [
    { key: 'when', label: t('salon.clients.visit'), render: (row) => <When value={row.starts_at} /> },
    { key: 'services', label: t('salon.book.services'), render: (row) => row.lines.map((line) => line.service_name).join(', ') },
    { key: 'staff', label: t('salon.book.staff'), render: (row) => [...new Set(row.lines.map((line) => line.staff_name))].join(', ') },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
    { key: 'formula', label: t('salon.clients.formulaUsed'), render: (row) => row.service_notes ?? '' },
  ].filter((column) => column.key !== 'formula' || (visits.data?.data ?? []).some((row) => row.service_notes))
  return (
    <Dialog
      open
      title={client ? client.name : t('salon.clients.loading')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('salon.close')}
          </Button>
          {canEdit ? (
            <Button variant="primary" loading={save.isPending} disabled={!Object.keys(edits).length} onClick={() => save.mutate()}>
              {t('salon.clients.save')}
            </Button>
          ) : null}
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <QueryNotes query={profile} />
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {client ? (
          <>
            {shows('phone') || shows('email') ? <p className="text-ink-muted">{[client.phone, client.email].filter(Boolean).join(' · ')}</p> : null}
            <Select label={t('salon.clients.preferredStaff')} options={[{ value: '', label: t('salon.book.anyStaff') }, ...staff.map((entry) => ({ value: entry.id, label: entry.name }))]} value={value('preferred_staff_id')} disabled={!canEdit} error={errors.fields.preferred_staff_id} onChange={set('preferred_staff_id')} />
            <TextAreaField label={t('salon.clients.preferences')} value={value('preferences')} disabled={!canEdit} error={errors.fields.preferences} onChange={set('preferences')} />
            {shows('allergies') ? <TextAreaField label={t('salon.clients.allergies')} help={t('salon.clients.privateHelp')} value={value('allergies')} disabled={!canEdit} error={errors.fields.allergies} onChange={set('allergies')} /> : null}
            {shows('formulas') ? <TextAreaField label={t('salon.clients.formulas')} help={t('salon.clients.privateHelp')} value={value('formulas')} disabled={!canEdit} error={errors.fields.formulas} onChange={set('formulas')} /> : null}
            {shows('notes') ? <TextAreaField label={t('salon.clients.notes')} value={value('notes')} disabled={!canEdit} error={errors.fields.notes} onChange={set('notes')} /> : null}
          </>
        ) : null}
        <h3 className="text-label text-ink">{t('salon.clients.visits')}</h3>
        <QueryNotes query={visits} />
        {!visits.isError ? <DataTable caption={t('salon.clients.visits')} columns={visitColumns} rows={visits.data?.data ?? []} emptyText={t('salon.clients.noVisits')} /> : null}
      </div>
    </Dialog>
  )
}

/** SAL-25: a new client (a customer with a salon profile). */
function NewClientDialog({ company, onClose, onCreated }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ name: '', phone: '', email: '' })
  const save = useMutation({
    mutationFn: () => api.post('salon/clients', { company_id: company.id, name: values.name.trim(), phone: values.phone.trim() || undefined, email: values.email.trim() || undefined }),
    onSuccess: (response) => {
      toast.success(t('salon.clients.created'))
      queryClient.invalidateQueries({ queryKey: salonKey })
      onCreated(response.data.party_id)
    },
  })
  const errors = formErrors(save.error, ['name', 'phone', 'email'])
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  return (
    <Dialog
      open
      title={t('salon.clients.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.name.trim()} onClick={() => save.mutate()}>
            {t('salon.clients.add')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('salon.fields.name')} value={values.name} error={errors.fields.name} onChange={set('name')} />
        <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={values.phone} error={errors.fields.phone} onChange={set('phone')} />
        <TextField label={t('salon.clients.email')} type="email" value={values.email} error={errors.fields.email} onChange={set('email')} />
      </div>
    </Dialog>
  )
}

/** SAL-18: corrects the sessions used of a package, with a reason. */
function AdjustDialog({ pack, onClose, onDone }) {
  const { t } = useTranslation()
  const [used, setUsed] = useState(String(pack.sessions_used))
  const [reason, setReason] = useState('')
  const save = useMutation({ mutationFn: () => api.post(`salon/packages/${pack.id}/adjust`, { sessions_used: Number(used), reason: reason.trim() }), onSuccess: onDone })
  return (
    <Dialog
      open
      title={t('salon.packages.adjustTitle', { name: pack.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!reason.trim() || used === ''} onClick={() => save.mutate()}>
            {t('salon.packages.adjust')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('salon.packages.adjustHelp', { total: pack.sessions_total })}</p>
        {save.isError ? <Alert tone="danger" title={salonError(save.error)} /> : null}
        <TextField label={t('salon.packages.used')} inputMode="numeric" value={used} onChange={(event) => setUsed(event.target.value)} className="max-w-32" />
        <TextAreaField label={t('salon.appointment.reason')} value={reason} maxLength={200} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** SAL-18, SAL-25: clients and their notes, the packages sold and the credit exceptions. */
export default function Clients() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const place = useSalonPlace()
  const company = place.company
  const [tab, setTab] = useState('clients')
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState(null)
  const enabled = Boolean(company)
  const clients = useQuery({ queryKey: [...salonKey, 'clients', company?.id, search], queryFn: () => api.get(`salon/clients${queryString({ company_id: company.id, search })}`), enabled })
  const packages = useQuery({ queryKey: [...salonKey, 'packages', company?.id], queryFn: () => api.get(`salon/packages${queryString({ company_id: company.id, per_page: 50 })}`), enabled: enabled && tab === 'packages' })
  const exceptions = useQuery({ queryKey: [...salonKey, 'exceptions', company?.id], queryFn: () => api.get(`salon/exceptions${queryString({ company_id: company.id, per_page: 50 })}`), enabled: enabled && tab === 'exceptions' })
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: salonKey })
    setDialog(null)
  }
  const clientColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'phone', label: t('salon.book.guestPhone'), render: (row) => row.phone ?? '' },
    { key: 'alerts', label: t('salon.clients.alerts'), render: (row) => (row.has_alerts ? t('salon.clients.hasAlerts') : '') },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) => (
        <Button variant="ghost" aria-label={t('salon.clients.openNamed', { name: row.name })} onClick={() => setDialog({ type: 'client', id: row.party_id })}>
          {t('salon.clients.open')}
        </Button>
      ),
    },
  ]
  const packageColumns = [
    { key: 'name', label: t('salon.fields.name'), render: (row) => row.name },
    { key: 'customer', label: t('salon.clients.client'), render: (row) => row.customer_name },
    { key: 'left', label: t('salon.packages.left'), align: 'end', render: (row) => `${row.sessions_left} / ${row.sessions_total}` },
    { key: 'value', label: t('salon.packages.value'), align: 'end', render: (row) => <Money amount={row.remaining_minor} currency={row.currency} /> },
    { key: 'expires', label: t('salon.packages.expires'), render: (row) => row.expires_on ?? '' },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        can(PACKAGE_ADJUST) && row.status !== 'void' ? (
          <Button variant="ghost" aria-label={t('salon.packages.adjustNamed', { name: row.name })} onClick={() => setDialog({ type: 'adjust', pack: row })}>
            {t('salon.packages.adjust')}
          </Button>
        ) : null,
    },
  ]
  const exceptionColumns = [
    { key: 'when', label: t('salon.commissions.when'), render: (row) => <When value={row.created_at} /> },
    { key: 'kind', label: t('salon.exceptions.kind'), render: (row) => t(`salon.exceptions.kinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'amount', label: t('salon.commissions.amount'), align: 'end', render: (row) => (row.amount_minor == null ? '' : <Money amount={row.amount_minor} currency={row.currency} />) },
    { key: 'status', label: t('salon.fields.status'), render: (row) => <Status status={row.status} /> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        can(EXCEPTION_RESOLVE) && row.status === 'open' ? (
          <Button variant="ghost" aria-label={t('salon.exceptions.resolveNamed', { kind: t(`salon.exceptions.kinds.${row.kind}`, { defaultValue: row.kind }) })} onClick={() => setDialog({ type: 'resolve', id: row.id })}>
            {t('salon.exceptions.resolve')}
          </Button>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader
        title={t('salon.clients.title')}
        description={t('salon.clients.description')}
        actions={
          tab === 'clients' && can(CLIENT_MANAGE) && company ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ type: 'new' })}>
              {t('salon.clients.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} branch={false} />
        <Tabs items={[{ value: 'clients', label: t('salon.clients.tab') }, { value: 'packages', label: t('salon.packages.soldTab') }, { value: 'exceptions', label: t('salon.exceptions.tab') }]} value={tab} onChange={setTab} />
        {tab === 'clients' ? (
          <>
            <TextField label={t('salon.clients.search')} value={search} onChange={(event) => setSearch(event.target.value)} className="max-w-field" />
            <QueryNotes query={clients} />
            {!clients.isError ? <DataTable caption={t('salon.clients.title')} columns={clientColumns} rows={clients.data?.data ?? []} loading={clients.isPending && enabled} emptyText={t('salon.clients.empty')} /> : null}
          </>
        ) : null}
        {tab === 'packages' ? (
          <>
            <QueryNotes query={packages} />
            {!packages.isError ? <DataTable caption={t('salon.packages.soldTab')} columns={packageColumns} rows={packages.data?.data ?? []} loading={packages.isPending && enabled} emptyText={t('salon.packages.soldEmpty')} /> : null}
          </>
        ) : null}
        {tab === 'exceptions' ? (
          <>
            <p className="text-caption text-ink-muted">{t('salon.exceptions.help')}</p>
            <QueryNotes query={exceptions} />
            {!exceptions.isError ? <DataTable caption={t('salon.exceptions.tab')} columns={exceptionColumns} rows={exceptions.data?.data ?? []} loading={exceptions.isPending && enabled} emptyText={t('salon.exceptions.empty')} /> : null}
          </>
        ) : null}
      </div>
      {dialog?.type === 'client' ? <ClientDialog partyId={dialog.id} company={company} onClose={() => setDialog(null)} /> : null}
      {dialog?.type === 'new' ? <NewClientDialog company={company} onClose={() => setDialog(null)} onCreated={(id) => setDialog({ type: 'client', id })} /> : null}
      {dialog?.type === 'adjust' ? <AdjustDialog pack={dialog.pack} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog?.type === 'resolve' ? <ReasonDialog title={t('salon.exceptions.resolveTitle')} label={t('salon.exceptions.note')} field="note" required={false} confirm={t('salon.exceptions.resolve')} path={`salon/exceptions/${dialog.id}/resolve`} onClose={() => setDialog(null)} onDone={refresh} /> : null}
    </>
  )
}
