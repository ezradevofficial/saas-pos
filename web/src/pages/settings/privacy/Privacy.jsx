import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { downloadFile } from '@/lib/files'
import { useLocale } from '@/lib/useLocale'
import { ConsentPanel } from './ConsentPanel'
import { SubjectPicker } from './SubjectPicker'

const REQUESTS_KEY = ['privacy', 'requests']
const TABS = ['requests', 'consent']
const FILTERS = ['open', 'completed', 'rejected', 'all']
const KINDS = ['access', 'correction', 'deletion']
// Corrections offered here are the plain text fields; lists (phones, emails) are edited on the record.
const TEXT_FIELDS = ['name', 'legal_name', 'tax_id', 'first_name', 'middle_name', 'last_name', 'personal_email', 'personal_phone', 'work_email', 'work_phone']

const TONES = { received: 'neutral', approved: 'info', completed: 'success', rejected: 'neutral' }

function RequestRow({ request, can, onReject }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const act = useMutation({
    mutationFn: (action) => api.post(`privacy/requests/${request.id}/${action}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: REQUESTS_KEY }),
  })
  const download = useMutation({ mutationFn: () => downloadFile(`privacy/requests/${request.id}/report`, `access-report-${request.id}.json`) })
  const decide = can.approve && request.status === 'received' && !request.own
  const status = request.overdue ? t('privacy.status.overdue') : t(`privacy.status.${request.status}`)

  return (
    <li className="flex flex-col gap-3 py-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-3">
            <span className="font-medium text-ink">{request.subject_name || t('privacy.subject.unknown')}</span>
            <StatusBadge tone={request.overdue ? 'danger' : TONES[request.status]}>{status}</StatusBadge>
          </div>
          <p className="text-caption text-ink-muted">
            {[
              t(`privacy.kinds.${request.kind}`),
              t(`privacy.subjectTypes.${request.subject_type}`),
              t('privacy.row.received', { date: formatDate(request.received_at, locale) }),
              request.status === 'completed' || request.status === 'rejected' ? null : t('privacy.row.due', { date: formatDate(request.due_at, locale) }),
              request.requested_by ? t('privacy.row.loggedBy', { name: request.requested_by.name }) : null,
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
          {request.rejection_reason ? <p className="text-caption text-ink-muted">{t('privacy.row.rejected', { reason: request.rejection_reason })}</p> : null}
          {request.status === 'received' && request.own && can.approve ? <p className="text-caption text-ink-muted">{t('privacy.row.ownRequest')}</p> : null}
        </div>
        <div className="flex flex-wrap gap-2">
          {decide ? (
            <>
              <Button variant="ghost" onClick={() => onReject(request)}>
                {t('privacy.actions.reject')}
              </Button>
              <Button variant="primary" loading={act.isPending && act.variables === 'approve'} onClick={() => act.mutate('approve')}>
                {t('privacy.actions.approve')}
              </Button>
            </>
          ) : null}
          {can.manage && request.status === 'approved' ? (
            <Button variant={request.kind === 'deletion' ? 'danger' : 'primary'} loading={act.isPending && act.variables === 'execute'} onClick={() => act.mutate('execute')}>
              {t(`privacy.actions.execute.${request.kind}`)}
            </Button>
          ) : null}
          {can.manage && request.kind === 'access' && request.status === 'completed' ? (
            <Button icon="download" loading={download.isPending} onClick={() => download.mutate()}>
              {t('privacy.actions.download')}
            </Button>
          ) : null}
        </div>
      </div>
      {act.isError || download.isError ? <Alert tone="danger" title={errorMessage(act.error ?? download.error)} /> : null}
    </li>
  )
}

function CreateDialog({ open, onClose, meta }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const types = meta?.subject_types ?? ['customer']
  const [state, setState] = useState({ type: types[0], subjectId: '', kind: 'access', note: '', corrections: {} })
  const set = (patch) => setState((current) => ({ ...current, ...patch }))
  const fields = (meta?.correctable?.[state.type] ?? []).filter((field) => TEXT_FIELDS.includes(field))

  const save = useMutation({
    mutationFn: () => {
      const corrections = Object.fromEntries(Object.entries(state.corrections).filter(([, value]) => String(value).trim() !== ''))
      return api.post('privacy/requests', {
        subject_type: state.type,
        subject_id: state.subjectId,
        kind: state.kind,
        note: state.note.trim() || null,
        ...(state.kind === 'correction' ? { corrections } : {}),
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: REQUESTS_KEY })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['subject_type', 'subject_id', 'kind', 'note', 'corrections'])

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('privacy.create.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" form="privacy-request-form" variant="primary" loading={save.isPending} disabled={!state.subjectId}>
            {t('privacy.create.submit')}
          </Button>
        </>
      }
    >
      <form
        id="privacy-request-form"
        noValidate
        className="flex flex-col gap-5 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('privacy.fields.subjectType')}
            value={state.type}
            onChange={(event) => set({ type: event.target.value, subjectId: '', corrections: {} })}
            options={types.map((type) => ({ value: type, label: t(`privacy.subjectTypes.${type}`) }))}
          />
          <Select
            label={t('privacy.fields.kind')}
            help={t(`privacy.kindHelp.${state.kind}`)}
            value={state.kind}
            onChange={(event) => set({ kind: event.target.value })}
            options={KINDS.map((kind) => ({ value: kind, label: t(`privacy.kinds.${kind}`) }))}
          />
        </div>
        <SubjectPicker type={state.type} value={state.subjectId} onChange={(id) => set({ subjectId: id })} error={errors.fields.subject_id} />
        <TextField
          label={t('privacy.fields.note')}
          help={t('privacy.fields.noteHelp')}
          value={state.note}
          onChange={(event) => set({ note: event.target.value })}
          error={errors.fields.note}
          maxLength={2000}
        />
        {state.kind === 'correction' ? (
          <fieldset className="flex flex-col gap-3">
            <legend className="text-label text-ink">{t('privacy.fields.corrections')}</legend>
            <p className="text-caption text-ink-muted">{t('privacy.fields.correctionsHelp')}</p>
            <div className="grid gap-4 sm:grid-cols-2">
              {fields.map((field) => (
                <TextField
                  key={field}
                  label={t(`privacy.correctable.${field}`)}
                  value={state.corrections[field] ?? ''}
                  onChange={(event) => set({ corrections: { ...state.corrections, [field]: event.target.value } })}
                  error={errors.fields[`corrections.${field}`]}
                />
              ))}
            </div>
            {errors.fields.corrections ? <p className="text-caption text-danger">{errors.fields.corrections}</p> : null}
          </fieldset>
        ) : null}
      </form>
    </Dialog>
  )
}

function RejectDialog({ request, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const reject = useMutation({
    mutationFn: () => api.post(`privacy/requests/${request.id}/reject`, { reason }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: REQUESTS_KEY })
      onClose()
    },
  })
  const errors = formErrors(reject.error, ['reason'])

  return (
    <Dialog
      open
      title={t('privacy.reject.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={reject.isPending} disabled={reason.trim().length < 3} onClick={() => reject.mutate()}>
            {t('privacy.actions.reject')}
          </Button>
        </>
      }
    >
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <TextField label={t('privacy.reject.reason')} help={t('privacy.reject.reasonHelp')} value={reason} onChange={(event) => setReason(event.target.value)} error={errors.fields.reason} maxLength={1000} />
    </Dialog>
  )
}

function Requests() {
  const { t } = useTranslation()
  const { tenantWide } = usePermissions()
  const can = { manage: tenantWide('core.privacy_request.manage'), approve: tenantWide('core.privacy_request.approve') }
  const [filter, setFilter] = useState('open')
  const [creating, setCreating] = useState(false)
  const [rejecting, setRejecting] = useState(null)
  const query = useQuery({
    queryKey: [...REQUESTS_KEY, filter],
    queryFn: () => api.get(filter === 'all' ? 'privacy/requests' : `privacy/requests?status=${filter}`),
  })
  const requests = query.data?.data ?? []

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="w-full sm:w-64">
          <Select label={t('privacy.filter.label')} value={filter} onChange={(event) => setFilter(event.target.value)} options={FILTERS.map((value) => ({ value, label: t(`privacy.filter.${value}`) }))} />
        </div>
        {can.manage ? (
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            {t('privacy.create.open')}
          </Button>
        ) : null}
      </div>
      <Card>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
        {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {!query.isPending && !query.isError && requests.length === 0 ? <p className="text-ink-muted">{t('privacy.empty')}</p> : null}
        {requests.length ? (
          <ul aria-label={t('privacy.listLabel')} className="flex flex-col divide-y divide-border">
            {requests.map((request) => (
              <RequestRow key={request.id} request={request} can={can} onReject={setRejecting} />
            ))}
          </ul>
        ) : null}
      </Card>
      {creating ? <CreateDialog open onClose={() => setCreating(false)} meta={query.data?.meta} /> : null}
      {rejecting ? <RejectDialog request={rejecting} onClose={() => setRejecting(null)} /> : null}
    </>
  )
}

/**
 * NFR-08: Settings → Privacy. Data subject requests (access, correction,
 * deletion by anonymisation) with their legal deadlines and four-eyes
 * approval, and customers' marketing consent. The API checks every action.
 */
export default function Privacy() {
  const { t } = useTranslation()
  const { tenantWide } = usePermissions()
  const [params, setParams] = useSearchParams()
  const tabs = TABS.filter((tab) => (tab === 'requests' ? tenantWide('core.privacy_request.view') : tenantWide('core.consent.view')))
  const tab = tabs.includes(params.get('tab')) ? params.get('tab') : tabs[0]

  return (
    <>
      <PageHeader title={t('privacy.title')} description={t('privacy.description')} />
      <div className="flex flex-col gap-5">
        {tabs.length > 1 ? (
          <Tabs
            value={tab}
            onChange={(next) => setParams(next === 'requests' ? {} : { tab: next }, { replace: true })}
            items={tabs.map((value) => ({ value, label: t(`privacy.tabs.${value}`) }))}
          />
        ) : null}
        {tab === 'requests' ? <Requests /> : null}
        {tab === 'consent' ? <ConsentPanel /> : null}
      </div>
    </>
  )
}
