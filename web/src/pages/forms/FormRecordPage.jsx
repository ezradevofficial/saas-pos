import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { FormLayoutRenderer } from '@/components/FormLayoutRenderer'
import { LineTable } from '@/components/LineTable'
import { Alert, Button, Card, Icon, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanySelection } from '@/layouts/companySelection'
import { customErrors, useCustomFieldSchema, useCustomValues } from '@/lib/customFields'
import { linesBody, newLine, orderedLineFields } from '@/lib/customForms'
import { formatBytes } from '@/lib/format'
import { fallbackLayout, useFormLayout } from '@/lib/formLayout'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { useScopes } from '@/pages/settings/users/assignments'
import { RecordStatus, useFormType } from './FormRecords'

/** CF-04: files attached to a record: upload (before saving), the list with links, and remove (before saving). */
function Attachments({ type, files, onChange, readOnly, label, help, error }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const input = useRef(null)
  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('file', file)
      return api.upload(`custom-form-types/${type.id}/attachments`, form)
    },
    onSuccess: (response) => onChange([...files, response.data]),
  })
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <span className="text-label text-ink">{label ?? t('customForms.attachments.title')}</span>
        {help ? <span className="text-caption text-ink-muted">{help}</span> : null}
      </div>
      {upload.isError ? <Alert tone="danger" title={errorMessage(upload.error)} /> : null}
      {error ? <p className="text-caption text-danger">{error}</p> : null}
      {files.length === 0 ? <p className="text-ink-muted">{t('customForms.attachments.none')}</p> : null}
      <ul className="flex flex-col gap-2" aria-label={label ?? t('customForms.attachments.title')}>
        {files.map((file) => (
          <li key={file.id} className="flex flex-wrap items-center gap-2">
            <Icon name="attach" className="text-ink-muted" />
            {file.url ? (
              <a href={file.url} className="font-medium text-primary hover:text-primary-hover" target="_blank" rel="noopener noreferrer">
                {file.name}
              </a>
            ) : (
              <span className="font-medium text-ink">{file.name}</span>
            )}
            <span className="text-caption text-ink-muted">{formatBytes(file.size, locale)}</span>
            {readOnly ? null : (
              <Button variant="ghost" icon="remove" aria-label={t('customForms.attachments.remove', { name: file.name })} onClick={() => onChange(files.filter((entry) => entry.id !== file.id))} />
            )}
          </li>
        ))}
      </ul>
      {readOnly ? null : (
        <div>
          <input
            ref={input}
            type="file"
            className="sr-only"
            aria-label={t('customForms.attachments.choose')}
            onChange={(event) => {
              const file = event.target.files?.[0]
              if (file) upload.mutate(file)
              event.target.value = ''
            }}
          />
          <Button icon="attach" loading={upload.isPending} onClick={() => input.current?.click()}>
            {t('customForms.attachments.add')}
          </Button>
        </div>
      )}
    </div>
  )
}

/** WF-10: where the record's flow is, who holds it, and the way to the approval and the full history. */
function FlowCard({ type, record, workflow }) {
  const { t } = useTranslation()
  if (!workflow) return null
  return (
    <Card title={t('customForms.flow.title')}>
      <div className="flex flex-col gap-3">
        {(workflow.current ?? []).length === 0 ? <p className="text-ink-muted">{t('customForms.flow.done', { outcome: t(`customForms.statuses.${record.status}`) })}</p> : null}
        {(workflow.current ?? []).map((step) => (
          <div key={step.token_id} className="flex flex-col gap-1 rounded-md border border-border p-3">
            <span className="font-medium text-ink">{step.name}</span>
            <span className="text-ink-muted">{t('customForms.flow.holders', { names: (step.holders?.users ?? []).map((user) => user.name).join(', ') || t('customForms.flow.nobody') })}</span>
          </div>
        ))}
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          {record.approval_id ? (
            <Link to={`/approvals/${record.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
              {t('customForms.flow.openApproval')}
            </Link>
          ) : null}
          <Link to={`/document-workflows/${type.document_type}/${record.id}`} className="w-fit text-label text-primary hover:text-primary-hover">
            {t('customForms.flow.history')}
          </Link>
        </div>
      </div>
    </Card>
  )
}

/**
 * CF-04, CF-05: a record of a custom form: written and sent from its form
 * layout (LAY-03) while a draft, read afterwards with its status and flow.
 */
function RecordForm({ type, record, workflow }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { company: selected } = useCompanySelection()
  const scopes = useScopes()
  const creating = !record
  const editable = creating || Boolean(record.can?.edit)
  const [place, setPlace] = useState({ company_id: record?.company?.id ?? '', branch_id: record?.branch?.id ?? '', location_id: record?.location?.id ?? '' })
  const activeCompanies = scopes.company.filter((company) => !company.archived_at)
  const companyId = place.company_id || selected?.id || (activeCompanies.length === 1 ? activeCompanies[0].id : '')
  const timeZone = useTimeZone(companyId || undefined)
  const headerSchema = useCustomFieldSchema(type.entity)
  const lineSchema = useCustomFieldSchema(type.line_entity, { enabled: type.has_lines })
  const lineFields = orderedLineFields(lineSchema.fields, type.line_fields ?? [])
  const custom = useCustomValues(headerSchema.fields, record, timeZone)
  const [lines, setLines] = useState(null)
  const [linesTouched, setLinesTouched] = useState(false)
  const shownLines = lines ?? (lineSchema.isSuccess ? (record?.lines ?? []).map((line) => newLine(lineFields, line, timeZone)) : [])
  const [files, setFiles] = useState(record?.attachments ?? [])
  const [submitted, setSubmitted] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')

  const save = useMutation({
    mutationFn: ({ submit }) => {
      const body = { submit }
      const changes = custom.body()
      if (creating || changes) body.custom = changes ?? {}
      if (type.has_lines && (creating || linesTouched)) body.lines = linesBody(lineFields, shownLines, timeZone)
      if (type.attachments) body.attachments = files.map((file) => file.id)
      if (creating) Object.assign(body, { company_id: companyId || null, branch_id: place.branch_id || null, location_id: place.location_id || null })
      return creating ? api.post(`custom-form-types/${type.id}/records`, body) : api.patch(`custom-form-records/${record.id}`, body)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['custom-form-records', type.id] })
      queryClient.setQueryData(['custom-form-record', response.data.id], response)
      setLines(null)
      setLinesTouched(false)
      if (creating) navigate(`/forms/${type.key}/${response.data.id}`, { replace: true })
    },
  })
  const action = useMutation({
    mutationFn: (path) => api.post(`custom-form-records/${record.id}/${path}`, path === 'cancel' ? { reason } : {}),
    onSuccess: async (response) => {
      queryClient.setQueryData(['custom-form-record', record.id], response)
      await queryClient.invalidateQueries({ queryKey: ['custom-form-records', type.id] })
      setCancelling(false)
    },
  })
  const placeFields = ['company_id', 'branch_id', 'location_id']
  const errors = formErrors(save.error, [...placeFields, 'lines', 'attachments', ...custom.errorFields])
  const lineErrors = Object.fromEntries(Object.entries(save.error?.errors ?? {}).filter(([key]) => key.startsWith('lines.')).map(([key, messages]) => [key, messages[0]]))
  const formMessage = Object.keys(lineErrors).length && errors.form ? null : errors.form

  const branches = scopes.branch.filter((branch) => branch.company_id === companyId && !branch.archived_at)
  const locations = scopes.location.filter((location) => location.branch_id === place.branch_id && !location.archived_at)
  const placeText = record ? [record.location?.name, record.branch?.name, record.company?.name].filter(Boolean).join(' · ') : ''

  const fieldRenderers = {
    place: ({ label, help }) =>
      creating ? (
        <div className="grid gap-4 sm:grid-cols-3">
          <Select
            label={label ?? t('customForms.place.company')}
            help={help}
            options={activeCompanies.map((company) => ({ value: company.id, label: company.name }))}
            placeholder={t('customForms.place.chooseCompany')}
            value={companyId}
            required
            error={errors.fields.company_id}
            onChange={(event) => setPlace({ company_id: event.target.value, branch_id: '', location_id: '' })}
          />
          <Select
            label={t('customForms.place.branch')}
            options={[{ value: '', label: t('customForms.place.noBranch') }, ...branches.map((branch) => ({ value: branch.id, label: branch.name }))]}
            value={place.branch_id}
            error={errors.fields.branch_id}
            onChange={(event) => setPlace((current) => ({ ...current, company_id: companyId, branch_id: event.target.value, location_id: '' }))}
          />
          <Select
            label={t('customForms.place.location')}
            options={[{ value: '', label: t('customForms.place.noLocation') }, ...locations.map((location) => ({ value: location.id, label: location.name }))]}
            value={place.location_id}
            disabled={!place.branch_id}
            error={errors.fields.location_id}
            onChange={(event) => setPlace((current) => ({ ...current, location_id: event.target.value }))}
          />
        </div>
      ) : (
        <div className="flex flex-col gap-1">
          <span className="text-label text-ink-muted">{label ?? t('customForms.place.label')}</span>
          <span className="text-ink">{placeText}</span>
        </div>
      ),
    lines: ({ label, help }) =>
      type.has_lines ? (
        <LineTable
          entity={type.line_entity}
          fields={lineFields}
          lines={shownLines}
          onChange={(next) => {
            setLines(next)
            setLinesTouched(true)
          }}
          readOnly={!editable}
          errors={{ ...lineErrors, lines: errors.fields.lines }}
          showErrors={submitted}
          label={label}
          help={help}
        />
      ) : null,
    attachments: ({ label, help }) =>
      type.attachments ? <Attachments type={type} files={files} onChange={setFiles} readOnly={!editable} label={label} help={help} error={errors.fields.attachments} /> : null,
  }
  const fallback = fallbackLayout(
    [
      { id: 'main', title: t('customForms.sections.details'), columns: 2, fields: [{ id: 'place', wide: true }] },
      ...(type.has_lines ? [{ id: 'lines', title: t('customForms.lines.title'), columns: 1, fields: [{ id: 'lines', wide: true }] }] : []),
      ...(type.attachments ? [{ id: 'attachments', title: t('customForms.attachments.title'), columns: 1, fields: [{ id: 'attachments', wide: true }] }] : []),
    ],
    headerSchema.fields,
    'main',
  )
  const { layout } = useFormLayout(type.layout_key, fallback)
  const send = (submit) => {
    setSubmitted(true)
    if (custom.invalid) return
    save.mutate({ submit })
  }

  return (
    <div className="flex flex-col gap-5">
      <Link to={`/forms/${type.key}`} className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('customForms.records.back', { name: type.name })}
      </Link>
      <PageHeader
        eyebrow={type.name}
        title={record ? record.number : t('customForms.records.newTitle', { name: type.name })}
        description={record ? undefined : t('customForms.records.newText')}
        actions={record ? <RecordStatus status={record.status} /> : null}
      />
      {formMessage ? <Alert tone="danger" title={formMessage} /> : null}
      {action.isError ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
      {record ? <FlowCard type={type} record={record} workflow={workflow} /> : null}
      <fieldset disabled={!editable} className="flex min-w-0 flex-col gap-5">
        <FormLayoutRenderer
          layout={layout}
          fields={fieldRenderers}
          custom={{ entity: type.entity, fields: headerSchema.fields, values: custom, errors: customErrors(errors.fields) }}
          readOnly={!editable}
          showErrors={submitted}
        />
      </fieldset>
      {cancelling ? (
        <div className="flex flex-col gap-3 rounded-md border border-border p-3">
          <TextField label={t('customForms.records.cancelReason')} value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
          <div className="flex flex-wrap gap-2">
            <Button variant="ghost" onClick={() => setCancelling(false)}>
              {t('customForms.records.keep')}
            </Button>
            <Button variant="danger" loading={action.isPending} disabled={!reason.trim()} onClick={() => action.mutate('cancel')}>
              {t('customForms.records.confirmCancel')}
            </Button>
          </div>
        </div>
      ) : null}
      <div className="flex flex-wrap gap-2">
        {editable ? (
          <>
            <Button variant="pay" loading={save.isPending && save.variables?.submit} disabled={save.isPending} onClick={() => send(true)}>
              {type.workflow ? t('customForms.records.sendForApproval') : t('customForms.records.send')}
            </Button>
            <Button loading={save.isPending && !save.variables?.submit} disabled={save.isPending} onClick={() => send(false)}>
              {t('customForms.records.saveDraft')}
            </Button>
          </>
        ) : null}
        {record?.can?.cancel && !cancelling ? (
          <Button variant="ghost" onClick={() => setCancelling(true)}>
            {t('customForms.records.cancel')}
          </Button>
        ) : null}
        {record?.can?.archive ? (
          <Button variant="ghost" icon={record.archived_at ? 'restore' : 'archive'} loading={action.isPending} onClick={() => action.mutate(record.archived_at ? 'restore' : 'archive')}>
            {record.archived_at ? t('customForms.records.restore') : t('customForms.records.archive')}
          </Button>
        ) : null}
      </div>
    </div>
  )
}

/** CF-04: `/forms/:formKey/new` and `/forms/:formKey/:recordId`. */
export default function FormRecordPage() {
  const { t } = useTranslation()
  const { recordId } = useParams()
  const { type, isPending } = useFormType()
  const record = useQuery({
    queryKey: ['custom-form-record', recordId],
    queryFn: () => api.get(`custom-form-records/${recordId}`),
    enabled: Boolean(recordId),
  })
  if (isPending || (recordId && record.isPending)) return null
  if (!type) return <Alert tone="warning" title={t('customForms.records.notFound')} />
  if (recordId && record.isError) return <Alert tone="danger" title={errorMessage(record.error)} />
  const data = record.data?.data ?? null
  return <RecordForm key={`${data?.id ?? 'new'}|${data?.status ?? ''}|${data?.archived_at ?? ''}`} type={type} record={data} workflow={record.data?.meta?.workflow ?? null} />
}
