import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, ListView, Money, MoneyInput, Select, StageTracker, Tabs, TextField } from '@/components/ds'
import { CustomFieldControl } from '@/components/CustomFieldControl'
import { PageHeader } from '@/layouts/PageHeader'
import { customErrors, useCustomFieldSchema, useCustomValues } from '@/lib/customFields'
import { todayIn } from '@/lib/dates'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { ActivityDialog, ActivityList, Amount, Back, Board, CalendarDate, CustomerPicker, Detail, DetailState, PerCurrency, Status, TextAreaField, When } from './CrmParts'
import { useCrmAction, useViewToggle } from './crmHelpers'
import {
  ACTIVITY_MANAGE,
  companyById,
  companyOfBranch,
  crmKey,
  fieldError,
  namedOptions,
  OPPORTUNITY_CREATE,
  OPPORTUNITY_STATUSES,
  QUOTE_CREATE,
  stagesOf,
  statusLabel,
  useAssignees,
  useCrmCompany,
  useCrmOptions,
  useCrmSettings,
  useCurrencies,
  usePipelines,
  usePlaces,
} from './crmData'

const opportunitiesKey = [...crmKey, 'opportunities']

/** An opportunity's value and its weighted value (value × probability), currency first. */
function Weighted({ opportunity }) {
  const { t } = useTranslation()
  if (!opportunity.value) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-col">
      <Amount value={opportunity.value} />
      {opportunity.weighted_minor !== null && opportunity.weighted_minor !== undefined ? (
        <span className="text-caption text-ink-muted">
          {t('crm.opportunity.weighted')} <Money amount={opportunity.weighted_minor} currency={opportunity.value.currency} />
        </span>
      ) : null}
    </span>
  )
}

/** CRM-16: a stage's count is the board's; its value and weighted value per currency come from the pipeline summary. */
function StageMeta({ summary }) {
  const { t } = useTranslation()
  if (!summary?.values?.length) return null
  return (
    <span className="flex flex-col gap-1">
      {summary.values.map((value) => (
        <span key={value.currency} className="flex flex-wrap items-baseline gap-x-2">
          <Money amount={value.amount_minor} currency={value.currency} />
          <span>{t('crm.opportunity.weighted')}</span>
          <Money amount={value.weighted_minor} currency={value.currency} />
        </span>
      ))}
    </span>
  )
}

/** CRM-16: open opportunities of a pipeline by stage, with each stage's count, value and weighted value; dragging a card moves its stage. */
function OpportunityBoard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { company, companies, choose } = useCrmCompany()
  const pipelines = usePipelines(company?.id)
  const [chosen, setChosen] = useState('')
  const pipeline = pipelines.active.find((entry) => entry.id === chosen) ?? pipelines.active.find((entry) => entry.is_default) ?? pipelines.active[0] ?? null
  const cards = useQuery({
    queryKey: [...opportunitiesKey, 'board', pipeline?.id],
    queryFn: () => api.get(`crm/opportunities?pipeline=${pipeline.id}&status=open&per_page=200`),
    enabled: Boolean(pipeline),
  })
  const summary = useQuery({
    queryKey: [...opportunitiesKey, 'summary', pipeline?.id],
    queryFn: () => api.get(`crm/pipeline-summary?pipeline=${pipeline.id}`),
    enabled: Boolean(pipeline),
  })
  const move = useMutation({
    mutationFn: ({ opportunity, stageId }) => api.patch(`crm/opportunities/${opportunity.id}`, { stage_id: stageId }),
    onSuccess: (_, { opportunity, stageName }) => toast.success(t('crm.opportunities.moved', { name: opportunity.name, stage: stageName })),
    onError: (error) => toast.error(errorMessage(error)),
    onSettled: () => queryClient.invalidateQueries({ queryKey: crmKey }),
  })
  const stages = stagesOf(pipeline)
  const stageSummary = (id) => summary.data?.data?.stages?.find((stage) => stage.id === id)
  const won = summary.data?.data?.won

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-4">
        {companies.length > 1 ? (
          <Select label={t('crm.fields.company')} options={namedOptions(companies)} value={company?.id ?? ''} onChange={(event) => { choose(event.target.value); setChosen('') }} className="max-w-field" />
        ) : null}
        <Select label={t('crm.fields.pipeline')} options={namedOptions(pipelines.active)} value={pipeline?.id ?? ''} disabled={!pipelines.active.length} onChange={(event) => setChosen(event.target.value)} className="max-w-field" />
        {won ? (
          <p className="flex flex-wrap items-baseline gap-2 text-caption text-ink-muted">
            <span>{t('crm.opportunities.wonCount', { count: won.count })}</span>
            <PerCurrency values={won.values} />
            <span>{t('crm.opportunities.lostCount', { count: summary.data.data.lost?.count ?? 0 })}</span>
          </p>
        ) : null}
      </div>
      {pipelines.isError ? <Alert tone="danger" title={errorMessage(pipelines.error)} /> : null}
      {cards.isError ? <Alert tone="danger" title={errorMessage(cards.error)} /> : null}
      {!pipelines.isPending && !pipeline ? <p className="text-body text-ink-muted">{t('crm.opportunities.noPipeline')}</p> : null}
      {pipeline && cards.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {pipeline && cards.data ? (
        <Board
          label={t('crm.opportunities.board', { pipeline: pipeline.name })}
          columns={stages.map((stage) => ({ id: stage.id, title: stage.name, meta: <StageMeta summary={stageSummary(stage.id)} /> }))}
          cards={cards.data.data ?? []}
          columnOf={(opportunity) => opportunity.stage?.id}
          cardLabel={(opportunity) => opportunity.name}
          busyId={move.isPending ? move.variables?.opportunity.id : null}
          onMove={(opportunity, stageId) => {
            if (opportunity.can?.edit === false || !can('crm.opportunity.edit')) {
              toast.error(t('crm.opportunities.cannotMove'))
              return
            }
            move.mutate({ opportunity, stageId, stageName: stages.find((stage) => stage.id === stageId)?.name })
          }}
          renderCard={(opportunity) => (
            <div className="flex flex-col gap-1">
              <Link to={`/crm/opportunities/${opportunity.id}`} className="font-medium text-ink hover:text-primary">
                {opportunity.name}
              </Link>
              <span className="text-caption text-ink-muted">{opportunity.party?.name}</span>
              <Weighted opportunity={opportunity} />
              <span className="flex flex-wrap gap-x-2 text-caption text-ink-muted">
                <span>{opportunity.owner?.name ?? t('crm.unassigned')}</span>
                {opportunity.expected_close_on ? (
                  <span>
                    {t('crm.opportunity.closes')} <CalendarDate value={opportunity.expected_close_on} />
                  </span>
                ) : null}
              </span>
            </div>
          )}
        />
      ) : null}
    </div>
  )
}

/** CRM-16, CRM-32: opportunities as a list with search, filters, sort, saved views and export. */
function OpportunityList() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const places = usePlaces()
  const { company } = useCrmCompany()
  const pipelines = usePipelines(company?.id, 'all')
  const columns = [
    { key: 'name', label: t('crm.columns.name'), sortKey: 'name', hideable: false, render: (row) => <span className="flex flex-col"><span className="font-medium text-ink">{row.name}</span><span className="text-caption text-ink-muted">{row.party?.name}</span></span> },
    { key: 'status', label: t('crm.columns.status'), sortKey: 'status', render: (row) => <Status status={row.status} /> },
    { key: 'stage', label: t('crm.columns.stage'), render: (row) => row.stage?.name ?? '' },
    { key: 'value', label: t('crm.columns.value'), sortKey: 'value_minor', align: 'end', render: (row) => <Amount value={row.value} /> },
    { key: 'weighted', label: t('crm.columns.weighted'), align: 'end', render: (row) => (row.value && row.weighted_minor != null ? <Money amount={row.weighted_minor} currency={row.value.currency} /> : '') },
    { key: 'probability', label: t('crm.columns.probability'), align: 'end', numeric: true, render: (row) => `${row.probability} %` },
    { key: 'expected_close_on', label: t('crm.columns.expectedCloseOn'), sortKey: 'expected_close_on', render: (row) => <CalendarDate value={row.expected_close_on} /> },
    { key: 'owner', label: t('crm.columns.owner'), render: (row) => row.owner?.name ?? t('crm.unassigned') },
    { key: 'branch', label: t('crm.columns.branch'), defaultHidden: true, render: (row) => row.branch?.name ?? '' },
    { key: 'created_at', label: t('crm.columns.createdAt'), sortKey: 'created_at', defaultHidden: true, render: (row) => <When value={row.created_at} companyId={row.company?.id} /> },
  ]
  const list = useServerList({ id: 'crm-opportunities', endpoint: 'crm/opportunities', queryKey: opportunitiesKey, filters: { status: '', pipeline: '', branch: '' }, defaultSort: '-created_at', columns })
  const all = (name) => ({ value: '', label: t(`crm.filters.all.${name}`) })
  const filterFields = [
    { name: 'status', label: t('crm.filters.status'), options: [all('status'), ...OPPORTUNITY_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
    ...(pipelines.all.length ? [{ name: 'pipeline', label: t('crm.filters.pipeline'), options: [all('pipeline'), ...namedOptions(pipelines.all)] }] : []),
    ...(places.branches.length ? [{ name: 'branch', label: t('crm.filters.branch'), options: [all('branch'), ...namedOptions(places.branches)] }] : []),
  ]
  const filtered = list.term || Object.values(list.filters).some(Boolean)
  return (
    <ListView
      list={list}
      title={t('crm.opportunities.title')}
      searchLabel={t('crm.opportunities.search')}
      filterFields={filterFields}
      emptyText={filtered ? t('crm.list.emptyFiltered') : t('crm.opportunities.empty')}
      onRowClick={(row) => navigate(`/crm/opportunities/${row.id}`)}
    />
  )
}

/** CRM-14, CRM-16: opportunities as a board by stage or a list, and "New opportunity". */
export default function Opportunities() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [view, setView] = useViewToggle('board')
  return (
    <>
      <PageHeader
        title={t('crm.opportunities.title')}
        description={t('crm.opportunities.description')}
        actions={can(OPPORTUNITY_CREATE) ? <Button variant="primary" icon="plus" onClick={() => navigate('/crm/opportunities/new')}>{t('crm.opportunities.new')}</Button> : null}
      />
      <div className="flex flex-col gap-5">
        <Tabs items={[{ value: 'board', label: t('crm.views.board') }, { value: 'list', label: t('crm.views.list') }]} value={view} onChange={setView} />
        {view === 'board' ? <OpportunityBoard /> : <OpportunityList />}
      </div>
    </>
  )
}

const OPPORTUNITY_FIELDS = ['branch_id', 'party_id', 'name', 'pipeline_id', 'stage_id', 'value_minor', 'currency', 'expected_close_on', 'probability', 'owner_id', 'notes']

function OpportunityFormBody({ opportunity }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const editing = Boolean(opportunity)
  const [branch, setBranch] = useState(opportunity?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch) ?? opportunity?.company?.id ?? null
  const currencies = useCurrencies(companyId)
  const pipelines = usePipelines(companyId)
  const owners = useAssignees(branch, Boolean(branch))
  const [party, setParty] = useState(opportunity?.party ?? null)
  const [name, setName] = useState(opportunity?.name ?? '')
  const [pipelineId, setPipelineId] = useState(opportunity?.pipeline?.id ?? '')
  const pipeline = pipelines.active.find((entry) => entry.id === pipelineId) ?? (editing ? null : (pipelines.active.find((entry) => entry.is_default) ?? pipelines.active[0] ?? null))
  const stages = stagesOf(pipeline)
  const [stageId, setStageId] = useState(opportunity?.stage?.id ?? '')
  const stage = stages.find((entry) => entry.id === stageId) ?? (editing ? null : stages[0]) ?? null
  const [value, setValue] = useState(opportunity?.value?.amount_minor ?? '')
  const [currency, setCurrency] = useState(opportunity?.value?.currency ?? '')
  const shownCurrency = currency || currencies.base
  const [closeOn, setCloseOn] = useState(opportunity?.expected_close_on ?? '')
  const [probability, setProbability] = useState(opportunity ? String(opportunity.probability ?? '') : '')
  const [owner, setOwner] = useState(opportunity?.owner?.id ?? '')
  const [notes, setNotes] = useState(opportunity?.notes ?? '')
  const [submitted, setSubmitted] = useState(false)
  const customSchema = useCustomFieldSchema('crm_opportunity')
  const custom = useCustomValues(customSchema.fields, opportunity, useTimeZone(companyId ?? undefined))
  const probabilityValid = probability === '' || (/^\d{1,3}$/.test(probability) && Number(probability) <= 100)

  const save = useMutation({
    mutationFn: () => {
      const body = {
        party_id: party.id,
        name: name.trim(),
        ...(stage ? { stage_id: stage.id } : {}),
        value_minor: value,
        currency: shownCurrency,
        expected_close_on: closeOn || null,
        ...(probability !== '' ? { probability: Number(probability) } : {}),
        ...(owner ? { owner_id: owner } : {}),
        notes: notes.trim() || null,
        ...(custom.body() ? { custom: custom.body() } : {}),
      }
      if (editing) return api.patch(`crm/opportunities/${opportunity.id}`, body)
      return api.post('crm/opportunities', { company_id: companyId, branch_id: branch, ...(pipeline ? { pipeline_id: pipeline.id } : {}), ...body })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'crm.opportunity.saved' : 'crm.opportunity.created'))
      await queryClient.invalidateQueries({ queryKey: crmKey })
      navigate(`/crm/opportunities/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...OPPORTUNITY_FIELDS, ...custom.errorFields])
  const ready = branch && party && name.trim() && value !== '' && value !== null && probabilityValid && !custom.invalid
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('crm.fields.branch')}
            placeholder={t('crm.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('crm.validation.branch') : undefined)}
            onChange={(event) => {
              setBranch(event.target.value)
              setOwner('')
              setPipelineId('')
              setStageId('')
            }}
          />
          <CustomerPicker value={party} onChange={setParty} required error={errors.fields.party_id ?? (submitted && !party ? t('crm.validation.customer') : undefined)} />
          <TextField
            label={t('crm.fields.opportunityName')}
            value={name}
            required
            maxLength={200}
            error={errors.fields.name ?? (submitted && !name.trim() ? t('crm.validation.name') : undefined)}
            onChange={(event) => setName(event.target.value)}
            className="sm:col-span-2"
          />
          <Select
            label={t('crm.fields.pipeline')}
            options={namedOptions(editing && opportunity.pipeline ? [opportunity.pipeline] : pipelines.active)}
            value={editing ? (opportunity.pipeline?.id ?? '') : (pipeline?.id ?? '')}
            disabled={editing || !companyId}
            error={errors.fields.pipeline_id}
            onChange={(event) => {
              setPipelineId(event.target.value)
              setStageId('')
            }}
          />
          <Select
            label={t('crm.fields.stage')}
            options={stages.length ? stages.map((entry) => ({ value: entry.id, label: `${entry.name} · ${entry.probability} %` })) : opportunity?.stage ? [{ value: opportunity.stage.id, label: opportunity.stage.name }] : []}
            value={stage?.id ?? opportunity?.stage?.id ?? ''}
            disabled={opportunity && opportunity.status !== 'open'}
            error={errors.fields.stage_id}
            onChange={(event) => setStageId(event.target.value)}
          />
          <MoneyInput
            key={shownCurrency ?? 'none'}
            label={t('crm.fields.value')}
            currency={shownCurrency}
            value={value}
            required
            showErrors={submitted}
            error={errors.fields.value_minor ?? (submitted && value === '' ? t('crm.validation.value') : undefined)}
            onChange={setValue}
          />
          <Select
            label={t('crm.fields.currency')}
            options={currencies.options}
            value={shownCurrency}
            error={errors.fields.currency}
            onChange={(event) => {
              if (event.target.value !== shownCurrency) setValue('')
              setCurrency(event.target.value)
            }}
          />
          <TextField type="date" label={t('crm.fields.expectedCloseOn')} value={closeOn} error={errors.fields.expected_close_on} onChange={(event) => setCloseOn(event.target.value)} />
          <TextField
            label={t('crm.fields.probability')}
            help={t('crm.opportunity.probabilityHelp')}
            inputMode="numeric"
            suffix="%"
            value={probability}
            placeholder={stage ? String(stage.probability) : undefined}
            error={errors.fields.probability ?? (!probabilityValid ? t('crm.validation.probability') : undefined)}
            onChange={(event) => setProbability(event.target.value.trim())}
          />
          <Select
            label={t('crm.fields.owner')}
            options={[{ value: '', label: editing ? t('crm.pickers.keep') : t('crm.pickers.me') }, ...namedOptions(owners)]}
            value={owner}
            disabled={!branch}
            error={errors.fields.owner_id}
            onChange={(event) => setOwner(event.target.value)}
          />
          <TextAreaField label={t('crm.fields.notes')} value={notes} maxLength={5000} error={errors.fields.notes} onChange={(event) => setNotes(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      {customSchema.fields.length ? (
        <Card title={t('customFields.section.title')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {customSchema.fields.map((field) => (
              <CustomFieldControl
                key={field.key}
                field={field}
                entity="crm_opportunity"
                value={custom.values[field.key]}
                onChange={(next) => custom.set(field.key, next)}
                error={customErrors(errors.fields)[field.key]}
                showErrors={submitted}
              />
            ))}
          </div>
        </Card>
      ) : null}
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/crm/opportunities/${opportunity.id}` : '/crm/opportunities')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t(editing ? 'crm.opportunity.save' : 'crm.opportunity.create')}
        </Button>
      </div>
    </form>
  )
}

/** CRM-14, CRM-33: a new opportunity or an opportunity's changes. */
export function OpportunityForm() {
  const { t } = useTranslation()
  const { opportunityId } = useParams()
  const query = useQuery({ queryKey: [...crmKey, 'opportunity', opportunityId], queryFn: () => api.get(`crm/opportunities/${opportunityId}`), enabled: Boolean(opportunityId) })
  const back = <Back to={opportunityId ? `/crm/opportunities/${opportunityId}` : '/crm/opportunities'}>{t('crm.opportunities.back')}</Back>
  if (opportunityId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('crm.opportunity.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t(opportunityId ? 'crm.opportunity.editTitle' : 'crm.opportunity.newTitle')} description={t('crm.opportunity.formHelp')} />
      <OpportunityFormBody key={opportunityId ?? 'new'} opportunity={query.data?.data} />
    </>
  )
}

/** CRM-15: win (optional won reason, note, close date) or lose (lost reason, required when the settings say so). */
function CloseDialog({ opportunity, outcome, onClose, onDone }) {
  const { t } = useTranslation()
  const companyId = opportunity.company?.id
  const places = usePlaces()
  const reasons = useCrmOptions(companyId, outcome === 'win' ? 'won_reason' : 'lost_reason')
  const settings = useCrmSettings(companyId)
  const required = outcome === 'lose' && settings.data?.data?.lost_reason_required !== false
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [closedOn, setClosedOn] = useState(() => todayIn(companyById(places, companyId)?.timezone))
  const save = useMutation({
    mutationFn: () => api.post(`crm/opportunities/${opportunity.id}/${outcome}`, { ...(reason ? { reason_id: reason } : {}), ...(note.trim() ? { note: note.trim() } : {}), ...(outcome === 'win' && closedOn ? { closed_on: closedOn } : {}) }),
    onSuccess: onDone,
  })
  const generic = save.isError && !fieldError(save.error, 'reason_id') && !fieldError(save.error, 'note') && !fieldError(save.error, 'closed_on')
  return (
    <Dialog
      open
      title={t(outcome === 'win' ? 'crm.opportunity.winTitle' : 'crm.opportunity.loseTitle', { name: opportunity.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant={outcome === 'win' ? 'primary' : 'danger'} loading={save.isPending} disabled={required && !reason} onClick={() => save.mutate()}>
            {t(outcome === 'win' ? 'crm.opportunity.win' : 'crm.opportunity.lose')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {generic ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <Select
          label={t(outcome === 'win' ? 'crm.fields.wonReason' : 'crm.fields.lostReason')}
          options={[...(required ? [] : [{ value: '', label: t('crm.pickers.none') }]), ...namedOptions(reasons.active)]}
          placeholder={required ? t('crm.pickers.reason') : undefined}
          value={reason}
          required={required}
          error={fieldError(save.error, 'reason_id')}
          onChange={(event) => setReason(event.target.value)}
        />
        {outcome === 'win' ? <TextField type="date" label={t('crm.fields.closedOn')} value={closedOn} error={fieldError(save.error, 'closed_on')} onChange={(event) => setClosedOn(event.target.value)} /> : null}
        <TextAreaField label={t('crm.fields.note')} value={note} maxLength={1000} error={fieldError(save.error, 'note')} onChange={(event) => setNote(event.target.value)} />
      </div>
    </Dialog>
  )
}

/**
 * CRM-14, CRM-15: one opportunity: its stage in the pipeline, value,
 * probability, expected close and owner; Win, Lose, Reopen, Edit and
 * Archive as the reader may (`can`); its activities and quotes.
 */
export function OpportunityDetail() {
  const { t } = useTranslation()
  const { opportunityId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const key = [...crmKey, 'opportunity', opportunityId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/opportunities/${opportunityId}`) })
  const action = useCrmAction(key)
  const opportunity = query.data?.data
  const pipelines = usePipelines(opportunity?.company?.id, 'all')
  const back = <Back to="/crm/opportunities">{t('crm.opportunities.back')}</Back>
  if (!opportunity) return <DetailState query={query} back={back} notFound={t('crm.opportunity.notFound')} />

  const archived = Boolean(opportunity.archived_at)
  const open = opportunity.status === 'open'
  const pipeline = pipelines.all.find((entry) => entry.id === opportunity.pipeline?.id)
  const stageNames = stagesOf(pipeline).map((stage) => stage.name)
  if (opportunity.stage && !stageNames.includes(opportunity.stage.name)) stageNames.push(opportunity.stage.name)
  const outcome = opportunity.status === 'lost' ? statusLabel(t, 'lost') : statusLabel(t, 'won')
  const tracker = [...stageNames, outcome]
  const done = async (response, message) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    toast.success(t(message))
    await queryClient.invalidateQueries({ queryKey: crmKey })
  }
  const quoteColumns = [
    { key: 'number', label: t('crm.columns.number'), render: (row) => <Link to={`/crm/quotes/${row.id}`} className="font-medium text-primary hover:text-primary-hover tabular-nums">{row.number ?? t('crm.quote.draftNumber')}</Link> },
    { key: 'status', label: t('crm.columns.status'), render: (row) => <Status status={row.status} /> },
    { key: 'quote_date', label: t('crm.columns.quoteDate'), render: (row) => <CalendarDate value={row.quote_date} /> },
    { key: 'total', label: t('crm.columns.total'), align: 'end', render: (row) => <Money amount={row.totals?.total_minor} currency={row.currency} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('crm.opportunity.eyebrow')}
        title={opportunity.name}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Status status={opportunity.status} />
            {archived ? <Status status="archived" /> : null}
            <span className="text-ink-muted">{opportunity.party?.name}</span>
          </span>
        }
        actions={
          <>
            {opportunity.can?.edit && open && !archived ? <Button icon="edit" onClick={() => navigate(`/crm/opportunities/${opportunity.id}/edit`)}>{t('crm.actions.edit')}</Button> : null}
            {opportunity.can?.archive ? (
              archived ? (
                <Button icon="restore" loading={action.isPending} onClick={() => action.mutate({ path: `crm/opportunities/${opportunity.id}/restore`, done: 'crm.opportunity.restored' })}>{t('crm.actions.restore')}</Button>
              ) : (
                <Button icon="archive" loading={action.isPending} onClick={() => action.mutate({ path: `crm/opportunities/${opportunity.id}/archive`, done: 'crm.opportunity.archived' })}>{t('crm.actions.archive')}</Button>
              )
            ) : null}
            {opportunity.can?.close && !open && !archived ? (
              <Button loading={action.isPending} onClick={() => action.mutate({ path: `crm/opportunities/${opportunity.id}/reopen`, done: 'crm.opportunity.reopened' })}>{t('crm.opportunity.reopen')}</Button>
            ) : null}
            {opportunity.can?.close && open && !archived ? (
              <>
                <Button variant="danger" onClick={() => setDialog('lose')}>{t('crm.opportunity.lose')}</Button>
                <Button variant="pay" onClick={() => setDialog('win')}>{t('crm.opportunity.win')}</Button>
              </>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Card>
          <div className="flex flex-col gap-5">
            <StageTracker stages={tracker} current={open ? opportunity.stage?.name : outcome} blocked={opportunity.status === 'lost'} />
            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Detail label={t('crm.fields.value')}><Weighted opportunity={opportunity} /></Detail>
              <Detail label={t('crm.fields.probability')}><span className="tabular-nums">{`${opportunity.probability} %`}</span></Detail>
              <Detail label={t('crm.fields.expectedCloseOn')}><CalendarDate value={opportunity.expected_close_on} /></Detail>
              <Detail label={t('crm.fields.owner')}>{opportunity.owner?.name ?? t('crm.unassigned')}</Detail>
            </dl>
          </div>
        </Card>
        <Card title={t('crm.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('crm.fields.customer')}>
              {opportunity.party ? <Link to={`/contacts/customers/${opportunity.party.id}?tab=sales`} className="text-primary hover:text-primary-hover">{opportunity.party.name}</Link> : '—'}
            </Detail>
            <Detail label={t('crm.fields.pipeline')}>{opportunity.pipeline?.name}</Detail>
            <Detail label={t('crm.fields.team')}>{opportunity.team?.name ?? '—'}</Detail>
            <Detail label={t('crm.fields.branch')}>
              <span className="flex flex-col">
                <span>{opportunity.branch?.name}</span>
                <span className="text-caption text-ink-muted">{opportunity.company?.name}</span>
              </span>
            </Detail>
            {opportunity.lead ? <Detail label={t('crm.fields.lead')}><Link to={`/crm/leads/${opportunity.lead.id}`} className="text-primary hover:text-primary-hover">{opportunity.lead.name}</Link></Detail> : null}
            {!open ? (
              <Detail label={t(opportunity.status === 'won' ? 'crm.fields.wonReason' : 'crm.fields.lostReason')}>
                <span className="flex flex-col">
                  <span>{opportunity.reason?.name ?? '—'}</span>
                  {opportunity.close_note ? <span className="text-caption text-ink-muted">{opportunity.close_note}</span> : null}
                </span>
              </Detail>
            ) : null}
            {opportunity.closed_on ? <Detail label={t('crm.fields.closedOn')}><CalendarDate value={opportunity.closed_on} /></Detail> : null}
            {opportunity.base_value && opportunity.base_value.currency !== opportunity.value?.currency ? <Detail label={t('crm.fields.baseValue')}><Amount value={opportunity.base_value} /></Detail> : null}
            <Detail label={t('crm.columns.createdAt')}><When value={opportunity.created_at} companyId={opportunity.company?.id} /></Detail>
            {opportunity.notes ? <Detail label={t('crm.fields.notes')}><span className="whitespace-pre-line">{opportunity.notes}</span></Detail> : null}
          </dl>
        </Card>
        <Card title={t('crm.activity.title')} actions={can(ACTIVITY_MANAGE) && !archived ? <Button icon="plus" onClick={() => setDialog('activity')}>{t('crm.activity.log')}</Button> : null}>
          <ActivityList activities={opportunity.activities ?? []} onChanged={() => queryClient.invalidateQueries({ queryKey: key })} />
        </Card>
        <Card title={t('crm.quotes.title')} actions={can(QUOTE_CREATE) && open && !archived ? <Button icon="plus" onClick={() => navigate(`/crm/quotes/new?opportunity=${opportunity.id}`)}>{t('crm.quotes.new')}</Button> : null}>
          <DataTable caption={t('crm.quotes.title')} columns={quoteColumns} rows={opportunity.quotes ?? []} emptyText={t('crm.quotes.none')} />
        </Card>
      </div>
      {dialog === 'win' || dialog === 'lose' ? (
        <CloseDialog opportunity={opportunity} outcome={dialog} onClose={() => setDialog(null)} onDone={(response) => done(response, dialog === 'win' ? 'crm.opportunity.won' : 'crm.opportunity.lost')} />
      ) : null}
      {dialog === 'activity' ? (
        <ActivityDialog
          branchId={opportunity.branch?.id}
          links={{ opportunity_id: opportunity.id, ...(opportunity.party ? { party_id: opportunity.party.id } : {}) }}
          onClose={() => setDialog(null)}
          onSaved={() => {
            setDialog(null)
            queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
    </>
  )
}
