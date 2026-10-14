import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, ListView, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { CaseDialog } from './EmployeeTabs'
import { Back, CalendarDate, ChecklistCard, ConfirmDialog, Days, Detail, DetailGrid, DetailState, Empty, FormDialog, HrStatus, NoCompany, TextAreaField } from './HrParts'
import { APPRAISAL_STATUSES, CASE_STATUSES, CHECKLIST_KINDS, employeeLabel, enumLabel, enumOptions, hrKey, PERM, RATINGS, statusLabel, useHrCompany } from './hrData'
import { useDay, useHrMutation } from './hrForms'

/** HR-28: open onboarding and offboarding checklists, and the company's templates. */
export function Onboarding() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const company = useHrCompany()
  const [tab, setTab] = useState('open')
  const [kind, setKind] = useState('')
  const [dialog, setDialog] = useState(null)
  const checklists = useQuery({ queryKey: [...hrKey, 'checklists', 'open', kind], queryFn: () => api.get(`hr/checklists?status=open&per_page=50${kind ? `&kind=${kind}` : ''}`), enabled: tab === 'open' })
  const templates = useQuery({ queryKey: [...hrKey, 'checklist-templates', company?.id], queryFn: () => api.get(`hr/checklist-templates?company=${company.id}&per_page=100`), enabled: Boolean(company) && tab === 'templates' })
  const archive = useHrMutation((row) => api.post(`hr/checklist-templates/${row.id}/archive`, {}), { success: t('hr.templates.archived'), onDone: () => setDialog(null) })
  const manage = can(PERM.checklistManage)
  const rows = checklists.data?.data ?? []
  return (
    <>
      <PageHeader
        title={t('hr.onboarding.title')}
        description={t('hr.onboarding.description')}
        actions={
          manage && tab === 'templates' && company ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'edit' })}>
              {t('hr.templates.add')}
            </Button>
          ) : null
        }
      />
      <Tabs value={tab} onChange={setTab} items={[{ value: 'open', label: t('hr.onboarding.open') }, { value: 'templates', label: t('hr.onboarding.templates') }]} />
      {tab === 'open' ? (
        <>
          <Select label={t('hr.checklists.kind')} options={[{ value: '', label: t('hr.filters.allKinds') }, ...enumOptions(t, 'checklistKinds', CHECKLIST_KINDS)]} value={kind} onChange={(event) => setKind(event.target.value)} className="w-64" />
          {checklists.isError ? <Alert tone="danger" title={errorMessage(checklists.error)} /> : null}
          {!checklists.isLoading && rows.length === 0 ? <Empty>{t('hr.onboarding.none')}</Empty> : null}
          {rows.map((checklist) => (
            <ChecklistCard key={checklist.id} checklist={checklist} canManage={manage} showEmployee />
          ))}
        </>
      ) : !company ? (
        <NoCompany />
      ) : (
        <DataTable
          caption={t('hr.onboarding.templates')}
          loading={templates.isLoading}
          rows={templates.data?.data ?? []}
          emptyText={t('hr.templates.none')}
          columns={[
            { key: 'name', label: t('hr.templates.name'), render: (row) => <span className="font-medium">{row.name}</span> },
            { key: 'kind', label: t('hr.checklists.kind'), render: (row) => enumLabel(t, 'checklistKinds', row.kind) },
            { key: 'items', label: t('hr.templates.items'), align: 'end', render: (row) => <span className="tabular-nums">{row.items?.length ?? 0}</span> },
            { key: 'default', label: t('hr.templates.default'), render: (row) => (row.is_default ? t('hr.templates.defaultYes') : '') },
            {
              key: 'actions',
              label: '',
              align: 'end',
              render: (row) =>
                manage ? (
                  <span className="flex justify-end gap-2">
                    <Button variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', row })}>
                      {t('hr.actions.edit')}
                    </Button>
                    <Button variant="ghost" icon="archive" onClick={() => setDialog({ kind: 'archive', row })}>
                      {t('hr.actions.archive')}
                    </Button>
                  </span>
                ) : null,
            },
          ]}
        />
      )}
      {dialog?.kind === 'edit' ? <TemplateDialog company={company} row={dialog.row} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'archive' ? (
        <ConfirmDialog open title={t('hr.templates.archiveTitle', { name: dialog.row.name })} text={t('hr.templates.archiveHelp')} confirmLabel={t('hr.actions.archive')} tone="danger" pending={archive.isPending} error={archive.error} onConfirm={() => archive.mutate(dialog.row)} onClose={() => setDialog(null)} />
      ) : null}
    </>
  )
}

/** HR-28: a template's ordered items (title, days after the start, optional responsible role key). */
function TemplateDialog({ company, row, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => ({
    kind: row?.kind ?? 'onboarding',
    name: row?.name ?? '',
    is_default: row?.is_default ?? false,
    items: (row?.items ?? [{ title: '', due_days: '0', role_key: '' }]).map((item) => ({ title: item.title ?? '', due_days: String(item.due_days ?? ''), role_key: item.role_key ?? '' })),
  }))
  const save = useHrMutation((body) => (row ? api.patch(`hr/checklist-templates/${row.id}`, body) : api.post('hr/checklist-templates', { company_id: company.id, kind: form.kind, ...body })), { success: t('hr.templates.saved'), onDone: onClose })
  const errors = save.errors(['name', 'kind', 'items', ...form.items.flatMap((_, index) => [`items.${index}.title`, `items.${index}.due_days`, `items.${index}.role_key`])])
  const setItem = (index, key, value) => setForm({ ...form, items: form.items.map((item, at) => (at === index ? { ...item, [key]: value } : item)) })
  const ready = form.name.trim() && form.items.length > 0 && form.items.every((item) => item.title.trim())
  const submit = () =>
    save.mutate({
      name: form.name.trim(),
      is_default: form.is_default,
      items: form.items.map((item) => ({ title: item.title.trim(), due_days: item.due_days === '' ? null : Number(item.due_days), role_key: item.role_key.trim() || null })),
    })
  return (
    <FormDialog open size="lg" title={row ? t('hr.templates.editTitle') : t('hr.templates.addTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} disabled={!ready} errors={errors} onSubmit={submit}>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label={t('hr.templates.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
        <Select label={t('hr.checklists.kind')} options={enumOptions(t, 'checklistKinds', CHECKLIST_KINDS)} value={form.kind} disabled={Boolean(row)} required error={errors.fields.kind} onChange={(event) => setForm({ ...form, kind: event.target.value })} />
      </div>
      <Checkbox label={t('hr.templates.makeDefault')} help={t('hr.templates.defaultHelp')} checked={form.is_default} onChange={(event) => setForm({ ...form, is_default: event.target.checked })} />
      <fieldset className="flex flex-col gap-3">
        <legend className="mb-2 text-label text-ink">{t('hr.templates.items')}</legend>
        {errors.fields.items ? <Alert tone="danger" title={errors.fields.items} /> : null}
        {form.items.map((item, index) => (
          <div key={index} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-4">
            <TextField label={t('hr.templates.itemTitle', { number: index + 1 })} value={item.title} required maxLength={200} error={errors.fields[`items.${index}.title`]} onChange={(event) => setItem(index, 'title', event.target.value)} className="sm:col-span-2" />
            <TextField type="number" label={t('hr.templates.dueDays')} help={t('hr.templates.dueDaysHelp')} min={-365} max={365} value={item.due_days} error={errors.fields[`items.${index}.due_days`]} onChange={(event) => setItem(index, 'due_days', event.target.value)} />
            <TextField label={t('hr.templates.roleKey')} value={item.role_key} maxLength={60} error={errors.fields[`items.${index}.role_key`]} onChange={(event) => setItem(index, 'role_key', event.target.value)} />
            {form.items.length > 1 ? (
              <div className="sm:col-span-4">
                <Button variant="ghost" icon="remove" onClick={() => setForm({ ...form, items: form.items.filter((_, at) => at !== index) })}>
                  {t('hr.templates.removeItem')}
                </Button>
              </div>
            ) : null}
          </div>
        ))}
        <div>
          <Button icon="plus" onClick={() => setForm({ ...form, items: [...form.items, { title: '', due_days: '0', role_key: '' }] })}>
            {t('hr.templates.addItem')}
          </Button>
        </div>
      </fieldset>
    </FormDialog>
  )
}

/** HR-29, HR-35: disciplinary cases (list `hr-disciplinary-cases`), seen only with `hr.disciplinary.view`. */
export function DisciplinaryCases() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useHrCompany()
  const day = useDay()
  const [opening, setOpening] = useState(false)
  const columns = [
    { key: 'number', label: t('hr.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'employee', label: t('hr.columns.employee'), render: (row) => row.employee?.name ?? '' },
    { key: 'category', label: t('hr.cases.category'), render: (row) => enumLabel(t, 'caseCategories', row.category) },
    { key: 'incident_date', label: t('hr.cases.incidentDate'), sortKey: 'incident_date', render: (row) => <span className="tabular-nums">{day(row.incident_date)}</span> },
    { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
  ]
  const list = useServerList({ id: 'hr-disciplinary-cases', endpoint: 'hr/disciplinary-cases', queryKey: [...hrKey, 'cases'], filters: { status: '' }, defaultSort: '-incident_date', columns })
  return (
    <>
      <PageHeader
        title={t('hr.cases.title')}
        description={t('hr.cases.description')}
        actions={
          can(PERM.disciplinaryManage) ? (
            <Button variant="primary" icon="plus" onClick={() => setOpening(true)}>
              {t('hr.cases.open')}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t('hr.cases.title')}
        searchLabel={t('hr.cases.search')}
        filterFields={[{ name: 'status', label: t('hr.filters.status'), options: [{ value: '', label: t('hr.filters.allStatuses') }, ...CASE_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] }]}
        emptyText={t('hr.cases.none')}
        onRowClick={(row) => navigate(`/hr/disciplinary/${row.id}`)}
      />
      {opening ? <CaseDialog companyId={company?.id} onClose={() => setOpening(false)} onDone={(response) => navigate(`/hr/disciplinary/${response.data.id}`)} /> : null}
    </>
  )
}

/** HR-29: one case: its record, edit and close with an outcome. */
export function CaseDetail() {
  const { t } = useTranslation()
  const { caseId } = useParams()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'case', caseId], queryFn: () => api.get(`hr/disciplinary-cases/${caseId}`) })
  const [outcome, setOutcome] = useState('')
  const close = useHrMutation((body) => api.post(`hr/disciplinary-cases/${caseId}/close`, body), { success: t('hr.cases.closed'), onDone: () => setDialog(null) })
  const back = <Back to="/hr/disciplinary">{t('hr.cases.back')}</Back>
  if (!query.data) return <DetailState query={query} back={back} notFound={t('hr.cases.notFound')} />
  const record = query.data.data
  const manage = can(PERM.disciplinaryManage) && record.status === 'open'
  return (
    <>
      {back}
      <PageHeader
        title={record.number}
        eyebrow={employeeLabel(record.employee)}
        actions={
          <>
            <HrStatus status={record.status} />
            {manage ? (
              <>
                <Button icon="edit" onClick={() => setDialog('edit')}>
                  {t('hr.actions.edit')}
                </Button>
                <Button variant="primary" onClick={() => setDialog('close')}>
                  {t('hr.cases.close')}
                </Button>
              </>
            ) : null}
          </>
        }
      />
      <Card title={t('hr.cases.record')}>
        <DetailGrid>
          <Detail label={t('hr.cases.category')}>{enumLabel(t, 'caseCategories', record.category)}</Detail>
          <Detail label={t('hr.cases.incidentDate')}>
            <CalendarDate value={record.incident_date} />
          </Detail>
          {record.closed_at ? <Detail label={t('hr.cases.closedAt')}>{<CalendarDate value={record.closed_at.slice(0, 10)} />}</Detail> : null}
        </DetailGrid>
        <dl className="mt-5 flex flex-col gap-4">
          <Detail label={t('hr.cases.descriptionField')}>
            <span className="whitespace-pre-line">{record.description}</span>
          </Detail>
          <Detail label={t('hr.cases.actionTaken')}>
            <span className="whitespace-pre-line">{record.action_taken ?? '—'}</span>
          </Detail>
          {record.outcome ? (
            <Detail label={t('hr.cases.outcome')}>
              <span className="whitespace-pre-line">{record.outcome}</span>
            </Detail>
          ) : null}
        </dl>
      </Card>
      {dialog === 'edit' ? <CaseDialog record={record} onClose={() => setDialog(null)} /> : null}
      {dialog === 'close' ? (
        <FormDialog open title={t('hr.cases.closeTitle', { number: record.number })} onClose={() => setDialog(null)} submitLabel={t('hr.cases.close')} pending={close.isPending} disabled={!outcome.trim()} errors={close.errors(['outcome'])} onSubmit={() => close.mutate({ outcome: outcome.trim() })}>
          <TextAreaField label={t('hr.cases.outcome')} value={outcome} required rows={4} maxLength={5000} error={close.errors(['outcome']).fields.outcome} onChange={(event) => setOutcome(event.target.value)} />
        </FormDialog>
      ) : null}
    </>
  )
}

/** HR-30: appraisal cycles (draft → open → closed) and the appraisals they make. */
export function Appraisals() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useHrCompany()
  const day = useDay()
  const [tab, setTab] = useState('appraisals')
  const [dialog, setDialog] = useState(null)
  const cycles = useQuery({ queryKey: [...hrKey, 'cycles', company?.id], queryFn: () => api.get(`hr/appraisal-cycles?company=${company.id}&per_page=100`), enabled: Boolean(company) && tab === 'cycles' })
  const act = useHrMutation(({ row, action }) => api.post(`hr/appraisal-cycles/${row.id}/${action}`, {}), { success: (_, { action }) => t(`hr.cycles.${action}Done`), onDone: () => setDialog(null) })
  const manage = can(PERM.appraisalManage)
  const columns = [
    { key: 'employee', label: t('hr.columns.employee'), hideable: false, render: (row) => row.employee?.name ?? '' },
    { key: 'cycle', label: t('hr.appraisals.cycle'), render: (row) => row.cycle?.name ?? '' },
    { key: 'reviewer', label: t('hr.appraisals.reviewer'), render: (row) => row.reviewer?.name ?? '—' },
    { key: 'status', label: t('hr.columns.status'), sortKey: 'status', render: (row) => <HrStatus status={row.status} /> },
    { key: 'overall_rating', label: t('hr.appraisals.overall'), align: 'end', render: (row) => <Days value={row.overall_rating} /> },
  ]
  const list = useServerList({ id: 'hr-appraisals', endpoint: 'hr/appraisals', queryKey: [...hrKey, 'appraisals'], filters: { status: '' }, defaultSort: '-created_at', columns })
  return (
    <>
      <PageHeader
        title={t('hr.appraisals.title')}
        description={t('hr.appraisals.description')}
        actions={
          manage && tab === 'cycles' && company ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'cycle' })}>
              {t('hr.cycles.add')}
            </Button>
          ) : null
        }
      />
      <Tabs value={tab} onChange={setTab} items={[{ value: 'appraisals', label: t('hr.appraisals.list') }, { value: 'cycles', label: t('hr.cycles.title') }]} />
      {tab === 'appraisals' ? (
        <ListView
          list={list}
          title={t('hr.appraisals.list')}
          searchable={false}
          filterFields={[{ name: 'status', label: t('hr.filters.status'), options: [{ value: '', label: t('hr.filters.allStatuses') }, ...APPRAISAL_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] }]}
          emptyText={t('hr.appraisals.none')}
          onRowClick={(row) => navigate(`/hr/appraisals/${row.id}`)}
        />
      ) : !company ? (
        <NoCompany />
      ) : (
        <DataTable
          caption={t('hr.cycles.title')}
          loading={cycles.isLoading}
          rows={cycles.data?.data ?? []}
          emptyText={t('hr.cycles.none')}
          columns={[
            { key: 'name', label: t('hr.cycles.name'), render: (row) => <span className="font-medium">{row.name}</span> },
            { key: 'period', label: t('hr.cycles.period'), render: (row) => <span className="tabular-nums">{`${day(row.period_start)} – ${day(row.period_end)}`}</span> },
            { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
            {
              key: 'actions',
              label: '',
              align: 'end',
              render: (row) =>
                manage && row.status !== 'closed' ? (
                  <Button onClick={() => setDialog({ kind: 'confirm', row, action: row.status === 'draft' ? 'open' : 'close' })}>{row.status === 'draft' ? t('hr.cycles.open') : t('hr.cycles.close')}</Button>
                ) : null,
            },
          ]}
        />
      )}
      {dialog?.kind === 'cycle' ? <CycleDialog company={company} onClose={() => setDialog(null)} /> : null}
      {dialog?.kind === 'confirm' ? (
        <ConfirmDialog
          open
          title={t(`hr.cycles.${dialog.action}Title`, { name: dialog.row.name })}
          text={t(`hr.cycles.${dialog.action}Help`)}
          confirmLabel={t(`hr.cycles.${dialog.action}`)}
          pending={act.isPending}
          error={act.error}
          onConfirm={() => act.mutate({ row: dialog.row, action: dialog.action })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </>
  )
}

function CycleDialog({ company, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ name: '', period_start: '', period_end: '' })
  const save = useHrMutation((body) => api.post('hr/appraisal-cycles', { company_id: company.id, ...body }), { success: t('hr.cycles.added'), onDone: onClose })
  const errors = save.errors(['name', 'period_start', 'period_end'])
  return (
    <FormDialog open title={t('hr.cycles.addTitle')} onClose={onClose} submitLabel={t('hr.cycles.add')} pending={save.isPending} disabled={!form.name.trim() || !form.period_start || !form.period_end} errors={errors} onSubmit={() => save.mutate({ ...form, name: form.name.trim() })}>
      <TextField label={t('hr.cycles.name')} value={form.name} required maxLength={120} error={errors.fields.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('hr.cycles.periodStart')} value={form.period_start} required error={errors.fields.period_start} onChange={(event) => setForm({ ...form, period_start: event.target.value })} />
        <TextField type="date" label={t('hr.cycles.periodEnd')} value={form.period_end} required error={errors.fields.period_end} onChange={(event) => setForm({ ...form, period_end: event.target.value })} />
      </div>
    </FormDialog>
  )
}

const ratingOptions = (t) => [{ value: '', label: t('hr.appraisals.noRating') }, ...RATINGS.map((value) => ({ value, label: t(`hr.ratings.${value}`) }))]

/**
 * HR-30: the manager's review of an appraisal: goals with weights and 1 to
 * 5 ratings, the manager's rating and comments; Complete ends the review.
 * The employee's self review shows as written.
 */
export function AppraisalReview() {
  const { t } = useTranslation()
  const { appraisalId } = useParams()
  const query = useQuery({ queryKey: [...hrKey, 'appraisal', appraisalId], queryFn: () => api.get(`hr/appraisals/${appraisalId}`) })
  const back = <Back to="/hr/appraisals">{t('hr.appraisals.back')}</Back>
  if (!query.data) return <DetailState query={query} back={back} notFound={t('hr.appraisals.notFound')} />
  return (
    <>
      {back}
      <ReviewForm key={query.dataUpdatedAt} appraisal={query.data.data} />
    </>
  )
}

function ReviewForm({ appraisal }) {
  const { t } = useTranslation()
  const [goals, setGoals] = useState(() => (appraisal.goals ?? []).map((goal) => ({ title: goal.title ?? '', weight: String(goal.weight ?? ''), rating: goal.rating ? String(goal.rating) : '', comment: goal.comment ?? '' })))
  const [rating, setRating] = useState(appraisal.manager_rating ? String(appraisal.manager_rating) : '')
  const [comments, setComments] = useState(appraisal.manager_comments ?? '')
  const [completing, setCompleting] = useState(false)
  const editable = appraisal.status === 'self_review' || appraisal.status === 'manager_review'
  const save = useHrMutation((body) => api.patch(`hr/appraisals/${appraisal.id}`, body), { success: t('hr.appraisals.saved') })
  const complete = useHrMutation(() => api.post(`hr/appraisals/${appraisal.id}/complete`, {}), { success: t('hr.appraisals.completed'), onDone: () => setCompleting(false) })
  const errors = save.errors(['manager_rating', 'manager_comments', 'goals', ...goals.flatMap((_, index) => ['title', 'weight', 'rating', 'comment'].map((key) => `goals.${index}.${key}`))])
  const setGoal = (index, key, value) => setGoals(goals.map((goal, at) => (at === index ? { ...goal, [key]: value } : goal)))
  const body = () => ({
    goals: goals.filter((goal) => goal.title.trim()).map((goal) => ({ title: goal.title.trim(), weight: goal.weight === '' ? 0 : Number(goal.weight), rating: goal.rating ? Number(goal.rating) : null, comment: goal.comment.trim() || null })),
    manager_rating: rating ? Number(rating) : null,
    manager_comments: comments.trim() || null,
  })
  return (
    <>
      <PageHeader
        title={appraisal.employee?.name ?? ''}
        eyebrow={appraisal.cycle?.name}
        description={appraisal.reviewer ? t('hr.appraisals.reviewedBy', { name: appraisal.reviewer.name }) : t('hr.appraisals.noReviewer')}
        actions={
          <>
            <HrStatus status={appraisal.status} />
            {editable ? (
              <>
                <Button loading={save.isPending} onClick={() => save.mutate(body())}>
                  {t('hr.actions.saveChanges')}
                </Button>
                <Button variant="pay" onClick={() => setCompleting(true)}>
                  {t('hr.appraisals.complete')}
                </Button>
              </>
            ) : null}
          </>
        }
      />
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card title={t('hr.appraisals.selfReview')}>
        <DetailGrid>
          <Detail label={t('hr.appraisals.selfRating')}>{appraisal.self_rating ? t(`hr.ratings.${appraisal.self_rating}`) : '—'}</Detail>
        </DetailGrid>
        <p className="mt-4 text-body whitespace-pre-line text-ink">{appraisal.self_comments || t('hr.appraisals.noSelfComments')}</p>
      </Card>
      <Card title={t('hr.appraisals.goals')} subtitle={appraisal.overall_rating ? t('hr.appraisals.overallValue', { value: appraisal.overall_rating }) : t('hr.appraisals.overallHelp')}>
        <div className="flex flex-col gap-3">
          {goals.length === 0 ? <Empty>{t('hr.appraisals.noGoals')}</Empty> : null}
          {goals.map((goal, index) => (
            <div key={index} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-4">
              <TextField label={t('hr.appraisals.goal', { number: index + 1 })} value={goal.title} disabled={!editable} required maxLength={200} error={errors.fields[`goals.${index}.title`]} onChange={(event) => setGoal(index, 'title', event.target.value)} className="sm:col-span-2" />
              <TextField type="number" label={t('hr.appraisals.weight')} suffix="%" min={0} max={100} value={goal.weight} disabled={!editable} error={errors.fields[`goals.${index}.weight`]} onChange={(event) => setGoal(index, 'weight', event.target.value)} />
              <Select label={t('hr.appraisals.rating')} options={ratingOptions(t)} value={goal.rating} disabled={!editable} error={errors.fields[`goals.${index}.rating`]} onChange={(event) => setGoal(index, 'rating', event.target.value)} />
              <TextField label={t('hr.appraisals.comment')} value={goal.comment} disabled={!editable} maxLength={1000} error={errors.fields[`goals.${index}.comment`]} onChange={(event) => setGoal(index, 'comment', event.target.value)} className="sm:col-span-3" />
              {editable ? (
                <div className="flex items-end">
                  <Button variant="ghost" icon="remove" onClick={() => setGoals(goals.filter((_, at) => at !== index))}>
                    {t('hr.appraisals.removeGoal')}
                  </Button>
                </div>
              ) : null}
            </div>
          ))}
          {editable && goals.length < 20 ? (
            <div>
              <Button icon="plus" onClick={() => setGoals([...goals, { title: '', weight: '', rating: '', comment: '' }])}>
                {t('hr.appraisals.addGoal')}
              </Button>
            </div>
          ) : null}
        </div>
      </Card>
      <Card title={t('hr.appraisals.managerReview')}>
        <div className="flex flex-col gap-3">
          <Select label={t('hr.appraisals.managerRating')} options={ratingOptions(t)} value={rating} disabled={!editable} error={errors.fields.manager_rating} onChange={(event) => setRating(event.target.value)} className="sm:max-w-xs" />
          <TextAreaField label={t('hr.appraisals.managerComments')} value={comments} disabled={!editable} rows={4} maxLength={5000} error={errors.fields.manager_comments} onChange={(event) => setComments(event.target.value)} />
        </div>
      </Card>
      {completing ? (
        <ConfirmDialog
          open
          title={t('hr.appraisals.completeTitle')}
          text={t('hr.appraisals.completeHelp')}
          confirmLabel={t('hr.appraisals.complete')}
          pending={complete.isPending || save.isPending}
          error={complete.error ?? save.error}
          onConfirm={() => save.mutate(body(), { onSuccess: () => complete.mutate() })}
          onClose={() => setCompleting(false)}
        />
      ) : null}
    </>
  )
}
