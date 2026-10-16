import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, ListView, MoneyInput, Select, Tabs, TextField } from '@/components/ds'
import { CustomFieldControl } from '@/components/CustomFieldControl'
import { PageHeader } from '@/layouts/PageHeader'
import { customErrors, useCustomFieldSchema, useCustomValues } from '@/lib/customFields'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { PARTY_KINDS } from '@/pages/contacts/partyData'
import { ActivityDialog, Amount, Back, Board, CustomerPicker, Detail, DetailState, ReasonDialog, Status, TextAreaField, When } from './CrmParts'
import { useCrmAction, useViewToggle } from './crmHelpers'
import {
  ACTIVITY_MANAGE,
  companyOfBranch,
  crmKey,
  LEAD_ASSIGN,
  LEAD_BOARD,
  LEAD_CREATE,
  LEAD_STATUSES,
  namedOptions,
  stagesOf,
  statusLabel,
  useAssignees,
  useCrmCompany,
  useCrmOptions,
  useCurrencies,
  usePipelines,
  usePlaces,
  useTeams,
} from './crmData'

const leadsKey = [...crmKey, 'leads']

/** CRM-13: a lead's name with its organisation under it. */
function LeadName({ lead }) {
  return (
    <span className="flex flex-col">
      <span className="font-medium text-ink">{lead.name}</span>
      {lead.organisation ? <span className="text-caption text-ink-muted">{lead.organisation}</span> : null}
    </span>
  )
}

/** CRM-13, CRM-32: leads as a list with search, filters, sort, saved views and export. */
function LeadList() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const places = usePlaces()
  const { company } = useCrmCompany()
  const sources = useCrmOptions(company?.id, 'source', 'all')
  const columns = [
    { key: 'name', label: t('crm.columns.name'), sortKey: 'name', hideable: false, render: (row) => <LeadName lead={row} /> },
    { key: 'status', label: t('crm.columns.status'), sortKey: 'status', render: (row) => <Status status={row.status} /> },
    { key: 'owner', label: t('crm.columns.owner'), render: (row) => row.owner?.name ?? t('crm.unassigned') },
    { key: 'source', label: t('crm.columns.source'), render: (row) => row.source?.name ?? '' },
    { key: 'branch', label: t('crm.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'estimated_value', label: t('crm.columns.estimatedValue'), align: 'end', render: (row) => <Amount value={row.estimated_value} /> },
    { key: 'email', label: t('crm.columns.email'), defaultHidden: true, render: (row) => row.email ?? '' },
    { key: 'phone', label: t('crm.columns.phone'), defaultHidden: true, render: (row) => row.phone ?? '' },
    { key: 'created_at', label: t('crm.columns.createdAt'), sortKey: 'created_at', render: (row) => <When value={row.created_at} companyId={row.company?.id} /> },
  ]
  const list = useServerList({ id: 'crm-leads', endpoint: 'crm/leads', queryKey: leadsKey, filters: { status: '', branch: '', owner: '', source: '' }, defaultSort: '-created_at', columns })
  const owners = useAssignees(list.filters.branch, Boolean(list.filters.branch))
  const all = (name) => ({ value: '', label: t(`crm.filters.all.${name}`) })
  const filterFields = [
    { name: 'status', label: t('crm.filters.status'), options: [all('status'), ...LEAD_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
    ...(places.branches.length ? [{ name: 'branch', label: t('crm.filters.branch'), options: [all('branch'), ...namedOptions(places.branches)] }] : []),
    ...(owners.length ? [{ name: 'owner', label: t('crm.filters.owner'), options: [all('owner'), ...namedOptions(owners)] }] : []),
    ...(sources.all.length ? [{ name: 'source', label: t('crm.filters.source'), options: [all('source'), ...namedOptions(sources.all)] }] : []),
  ]
  const filtered = list.term || Object.values(list.filters).some(Boolean)
  return (
    <ListView
      list={list}
      title={t('crm.leads.title')}
      searchLabel={t('crm.leads.search')}
      filterFields={filterFields}
      emptyText={filtered ? t('crm.list.emptyFiltered') : t('crm.leads.empty')}
      onRowClick={(row) => navigate(`/crm/leads/${row.id}`)}
    />
  )
}

/** CRM-13: open leads by status (new, contacted, qualified); moving a card changes its status. */
function LeadBoard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const queries = useQueries({
    queries: LEAD_BOARD.map((status) => ({ queryKey: [...leadsKey, 'board', status], queryFn: () => api.get(`crm/leads?status=${status}&per_page=200&sort=-created_at`) })),
  })
  const cards = queries.flatMap((query) => query.data?.data ?? [])
  const move = useMutation({
    mutationFn: ({ lead, status }) => api.patch(`crm/leads/${lead.id}`, { status }),
    onSuccess: (_, { lead, status }) => toast.success(t('crm.leads.moved', { name: lead.name, status: statusLabel(t, status) })),
    onError: (error) => toast.error(errorMessage(error)),
    onSettled: () => queryClient.invalidateQueries({ queryKey: crmKey }),
  })
  const failed = queries.find((query) => query.isError)
  if (failed) return <Alert tone="danger" title={errorMessage(failed.error)} />
  if (queries.some((query) => query.isPending)) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <Board
      label={t('crm.leads.board')}
      columns={LEAD_BOARD.map((status) => ({ id: status, title: statusLabel(t, status) }))}
      cards={cards}
      columnOf={(lead) => lead.status}
      cardLabel={(lead) => lead.name}
      busyId={move.isPending ? move.variables?.lead.id : null}
      onMove={(lead, status) => {
        if (lead.can?.edit === false || !can('crm.lead.edit')) {
          toast.error(t('crm.leads.cannotMove'))
          return
        }
        move.mutate({ lead, status })
      }}
      renderCard={(lead) => (
        <div className="flex flex-col gap-1">
          <Link to={`/crm/leads/${lead.id}`} className="font-medium text-ink hover:text-primary">
            {lead.name}
          </Link>
          {lead.organisation ? <span className="text-caption text-ink-muted">{lead.organisation}</span> : null}
          {lead.estimated_value ? <Amount value={lead.estimated_value} /> : null}
          <span className="text-caption text-ink-muted">{[lead.owner?.name ?? t('crm.unassigned'), lead.source?.name].filter(Boolean).join(' · ')}</span>
        </div>
      )}
    />
  )
}

/** CRM-05, CRM-13: leads as a board by status or a list, and "New lead". */
export default function Leads() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [view, setView] = useViewToggle('list')
  return (
    <>
      <PageHeader
        title={t('crm.leads.title')}
        description={t('crm.leads.description')}
        actions={can(LEAD_CREATE) ? <Button variant="primary" icon="plus" onClick={() => navigate('/crm/leads/new')}>{t('crm.leads.new')}</Button> : null}
      />
      <div className="flex flex-col gap-5">
        <Tabs items={[{ value: 'list', label: t('crm.views.list') }, { value: 'board', label: t('crm.views.board') }]} value={view} onChange={setView} />
        {view === 'board' ? <LeadBoard /> : <LeadList />}
      </div>
    </>
  )
}

const LEAD_FIELDS = ['branch_id', 'name', 'organisation', 'title', 'email', 'phone', 'source_id', 'estimated_value_minor', 'currency', 'notes', 'owner_id', 'status']

function LeadFormBody({ lead }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const places = usePlaces()
  const editing = Boolean(lead)
  const [branch, setBranch] = useState(lead?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch) ?? lead?.company?.id ?? null
  const currencies = useCurrencies(companyId)
  const sources = useCrmOptions(companyId, 'source')
  const owners = useAssignees(branch, Boolean(branch) && !editing && can(LEAD_ASSIGN))
  const [values, setValues] = useState(() => ({
    name: lead?.name ?? '',
    organisation: lead?.organisation ?? '',
    title: lead?.title ?? '',
    email: lead?.email ?? '',
    phone: lead?.phone ?? '',
    source_id: lead?.source?.id ?? '',
    notes: lead?.notes ?? '',
    owner_id: '',
    status: lead?.status ?? 'new',
  }))
  const [value, setValue] = useState(lead?.estimated_value?.amount_minor ?? '')
  const [currency, setCurrency] = useState(lead?.estimated_value?.currency ?? '')
  const [submitted, setSubmitted] = useState(false)
  const shownCurrency = currency || currencies.base
  const customSchema = useCustomFieldSchema('crm_lead')
  const custom = useCustomValues(customSchema.fields, lead, useTimeZone(companyId ?? undefined))
  const set = (name, next) => setValues((current) => ({ ...current, [name]: next }))
  const text = (name) => values[name].trim() || null

  const save = useMutation({
    mutationFn: () => {
      const body = {
        name: values.name.trim(),
        organisation: text('organisation'),
        title: text('title'),
        email: text('email'),
        phone: text('phone'),
        source_id: values.source_id || null,
        estimated_value_minor: value === '' ? null : value,
        currency: value === '' ? null : shownCurrency,
        notes: text('notes'),
        ...(custom.body() ? { custom: custom.body() } : {}),
      }
      if (editing) return api.patch(`crm/leads/${lead.id}`, { ...body, status: values.status })
      return api.post('crm/leads', { company_id: companyId, branch_id: branch, channel: 'manual', ...body, ...(values.owner_id ? { owner_id: values.owner_id } : {}) })
    },
    onSuccess: async (response) => {
      toast.success(t(editing ? 'crm.lead.saved' : 'crm.lead.created'))
      await queryClient.invalidateQueries({ queryKey: crmKey })
      navigate(`/crm/leads/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...LEAD_FIELDS, ...custom.errorFields])
  const ready = branch && values.name.trim() && value !== null && !custom.invalid
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
              set('owner_id', '')
              set('source_id', '')
            }}
          />
          {editing ? (
            <Select
              label={t('crm.fields.status')}
              options={LEAD_BOARD.map((status) => ({ value: status, label: statusLabel(t, status) }))}
              value={values.status}
              error={errors.fields.status}
              onChange={(event) => set('status', event.target.value)}
            />
          ) : can(LEAD_ASSIGN) ? (
            <Select
              label={t('crm.fields.owner')}
              help={t('crm.lead.ownerHelp')}
              options={[{ value: '', label: t('crm.pickers.me') }, ...namedOptions(owners)]}
              value={values.owner_id}
              disabled={!branch}
              error={errors.fields.owner_id}
              onChange={(event) => set('owner_id', event.target.value)}
            />
          ) : (
            <span />
          )}
          <TextField
            label={t('crm.fields.name')}
            value={values.name}
            required
            maxLength={200}
            error={errors.fields.name ?? (submitted && !values.name.trim() ? t('crm.validation.name') : undefined)}
            onChange={(event) => set('name', event.target.value)}
          />
          <TextField label={t('crm.fields.organisation')} value={values.organisation} maxLength={200} error={errors.fields.organisation} onChange={(event) => set('organisation', event.target.value)} />
          <TextField label={t('crm.fields.title')} value={values.title} maxLength={120} error={errors.fields.title} onChange={(event) => set('title', event.target.value)} />
          <Select
            label={t('crm.fields.source')}
            options={[{ value: '', label: t('crm.pickers.none') }, ...namedOptions(sources.active)]}
            value={values.source_id}
            disabled={!companyId}
            error={errors.fields.source_id}
            onChange={(event) => set('source_id', event.target.value)}
          />
          <TextField type="email" label={t('crm.fields.email')} value={values.email} maxLength={254} error={errors.fields.email} onChange={(event) => set('email', event.target.value)} />
          <TextField type="tel" label={t('crm.fields.phone')} value={values.phone} maxLength={40} error={errors.fields.phone} onChange={(event) => set('phone', event.target.value)} />
          <MoneyInput
            key={shownCurrency ?? 'none'}
            label={t('crm.fields.estimatedValue')}
            currency={shownCurrency}
            value={value}
            showErrors={submitted}
            error={errors.fields.estimated_value_minor}
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
          <TextAreaField label={t('crm.fields.notes')} value={values.notes} maxLength={5000} error={errors.fields.notes} onChange={(event) => set('notes', event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      {customSchema.fields.length ? (
        <Card title={t('customFields.section.title')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {customSchema.fields.map((field) => (
              <CustomFieldControl
                key={field.key}
                field={field}
                entity="crm_lead"
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
        <Button variant="ghost" onClick={() => navigate(editing ? `/crm/leads/${lead.id}` : '/crm/leads')}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {t(editing ? 'crm.lead.save' : 'crm.lead.create')}
        </Button>
      </div>
    </form>
  )
}

/** CRM-05, CRM-10, CRM-33: a new lead or a lead's changes. */
export function LeadForm() {
  const { t } = useTranslation()
  const { leadId } = useParams()
  const query = useQuery({ queryKey: [...crmKey, 'lead', leadId], queryFn: () => api.get(`crm/leads/${leadId}`), enabled: Boolean(leadId) })
  const back = <Back to={leadId ? `/crm/leads/${leadId}` : '/crm/leads'}>{t('crm.leads.back')}</Back>
  if (leadId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('crm.lead.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t(leadId ? 'crm.lead.editTitle' : 'crm.lead.newTitle')} description={t('crm.lead.formHelp')} />
      <LeadFormBody key={leadId ?? 'new'} lead={query.data?.data} />
    </>
  )
}

/** CRM-10, CRM-11: give a lead to an owner or a team. */
function AssignDialog({ lead, onClose, onDone }) {
  const { t } = useTranslation()
  const owners = useAssignees(lead.branch?.id)
  const teams = useTeams(lead.company?.id)
  const [owner, setOwner] = useState(lead.owner?.id ?? '')
  const [team, setTeam] = useState(lead.team?.id ?? '')
  const save = useMutation({ mutationFn: () => api.post(`crm/leads/${lead.id}/assign`, { owner_id: owner || null, team_id: team || null }), onSuccess: onDone })
  const errors = formErrors(save.error, ['owner_id', 'team_id'])
  return (
    <Dialog
      open
      title={t('crm.lead.assignTitle', { name: lead.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!owner && !team} onClick={() => save.mutate()}>{t('crm.lead.assign')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('crm.fields.owner')} options={[{ value: '', label: t('crm.unassigned') }, ...namedOptions(owners)]} value={owner} error={errors.fields.owner_id} onChange={(event) => setOwner(event.target.value)} />
        <Select label={t('crm.fields.team')} options={[{ value: '', label: t('crm.pickers.none') }, ...namedOptions(teams.active)]} value={team} error={errors.fields.team_id} onChange={(event) => setTeam(event.target.value)} />
      </div>
    </Dialog>
  )
}

const CONVERT_FIELDS = ['party_id', 'party.kind', 'party.name', 'party.phones.0.number', 'party.emails.0.address', 'opportunity.name', 'opportunity.pipeline_id', 'opportunity.stage_id', 'opportunity.value_minor', 'opportunity.currency', 'opportunity.expected_close_on']

/**
 * CRM-12: convert a lead to a customer (an existing one, or a new one made
 * through the core party rules) and, optionally, an opportunity in a
 * pipeline and stage (its first by default).
 */
export function ConvertDialog({ lead, onClose, onDone }) {
  const { t } = useTranslation()
  const companyId = lead.company?.id
  const pipelines = usePipelines(companyId)
  const currencies = useCurrencies(companyId)
  const [mode, setMode] = useState('new')
  const [party, setParty] = useState(null)
  const [kind, setKind] = useState(lead.organisation ? 'organisation' : 'person')
  const [name, setName] = useState(lead.organisation || lead.name)
  const [phone, setPhone] = useState(lead.phone ?? '')
  const [email, setEmail] = useState(lead.email ?? '')
  const [withOpportunity, setWithOpportunity] = useState(true)
  const [pipelineId, setPipelineId] = useState('')
  const pipeline = pipelines.active.find((entry) => entry.id === pipelineId) ?? pipelines.active.find((entry) => entry.is_default) ?? pipelines.active[0] ?? null
  const stages = stagesOf(pipeline)
  const [stageId, setStageId] = useState('')
  const stage = stages.find((entry) => entry.id === stageId) ?? stages[0] ?? null
  const [opportunityName, setOpportunityName] = useState(lead.organisation || lead.name)
  const [value, setValue] = useState(lead.estimated_value?.amount_minor ?? '')
  const [currency, setCurrency] = useState(lead.estimated_value?.currency ?? '')
  const shownCurrency = currency || currencies.base
  const [closeOn, setCloseOn] = useState('')

  const save = useMutation({
    mutationFn: () =>
      api.post(`crm/leads/${lead.id}/convert`, {
        ...(mode === 'existing'
          ? { party_id: party.id }
          : {
              party: {
                kind,
                name: name.trim(),
                company_id: companyId,
                ...(phone.trim() ? { phones: [{ number: phone.trim() }] } : {}),
                ...(email.trim() ? { emails: [{ address: email.trim() }] } : {}),
              },
            }),
        ...(withOpportunity
          ? {
              opportunity: {
                name: opportunityName.trim(),
                ...(pipeline ? { pipeline_id: pipeline.id } : {}),
                ...(stage ? { stage_id: stage.id } : {}),
                ...(value !== '' && value !== null ? { value_minor: value, currency: shownCurrency } : {}),
                ...(closeOn ? { expected_close_on: closeOn } : {}),
              },
            }
          : {}),
      }),
    onSuccess: onDone,
  })
  const errors = formErrors(save.error, CONVERT_FIELDS)
  const ready = (mode === 'existing' ? Boolean(party) : Boolean(name.trim())) && (!withOpportunity || (opportunityName.trim() && value !== null))

  return (
    <Dialog
      open
      size="lg"
      title={t('crm.convert.title', { name: lead.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!ready} onClick={() => save.mutate()}>{t('crm.convert.confirm')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-5 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <section className="grid gap-4 sm:grid-cols-2">
          <h3 className="text-h3 text-ink sm:col-span-2">{t('crm.convert.customer')}</h3>
          <Select
            label={t('crm.convert.customerMode')}
            options={[{ value: 'new', label: t('crm.convert.modes.new') }, { value: 'existing', label: t('crm.convert.modes.existing') }]}
            value={mode}
            onChange={(event) => setMode(event.target.value)}
            className="sm:col-span-2"
          />
          {mode === 'existing' ? (
            <CustomerPicker value={party} onChange={setParty} required error={errors.fields.party_id} className="sm:col-span-2" />
          ) : (
            <>
              <Select label={t('crm.fields.kind')} options={PARTY_KINDS.map((entry) => ({ value: entry, label: t(`crm.partyKinds.${entry}`) }))} value={kind} error={errors.fields['party.kind']} onChange={(event) => setKind(event.target.value)} />
              <TextField label={t('crm.fields.customerName')} value={name} required maxLength={200} error={errors.fields['party.name']} onChange={(event) => setName(event.target.value)} />
              <TextField type="tel" label={t('crm.fields.phone')} value={phone} maxLength={40} error={errors.fields['party.phones.0.number']} onChange={(event) => setPhone(event.target.value)} />
              <TextField type="email" label={t('crm.fields.email')} value={email} maxLength={254} error={errors.fields['party.emails.0.address']} onChange={(event) => setEmail(event.target.value)} />
            </>
          )}
        </section>
        <section className="grid gap-4 sm:grid-cols-2">
          <Checkbox label={t('crm.convert.withOpportunity')} checked={withOpportunity} onChange={(event) => setWithOpportunity(event.target.checked)} className="sm:col-span-2" />
          {withOpportunity ? (
            <>
              <TextField label={t('crm.fields.opportunityName')} value={opportunityName} required maxLength={200} error={errors.fields['opportunity.name']} onChange={(event) => setOpportunityName(event.target.value)} className="sm:col-span-2" />
              <Select
                label={t('crm.fields.pipeline')}
                options={namedOptions(pipelines.active)}
                value={pipeline?.id ?? ''}
                error={errors.fields['opportunity.pipeline_id']}
                onChange={(event) => {
                  setPipelineId(event.target.value)
                  setStageId('')
                }}
              />
              <Select label={t('crm.fields.stage')} options={namedOptions(stages)} value={stage?.id ?? ''} error={errors.fields['opportunity.stage_id']} onChange={(event) => setStageId(event.target.value)} />
              <MoneyInput key={shownCurrency} label={t('crm.fields.value')} currency={shownCurrency} value={value} error={errors.fields['opportunity.value_minor']} onChange={setValue} />
              <Select
                label={t('crm.fields.currency')}
                options={currencies.options}
                value={shownCurrency}
                error={errors.fields['opportunity.currency']}
                onChange={(event) => {
                  if (event.target.value !== shownCurrency) setValue('')
                  setCurrency(event.target.value)
                }}
              />
              <TextField type="date" label={t('crm.fields.expectedCloseOn')} value={closeOn} error={errors.fields['opportunity.expected_close_on']} onChange={(event) => setCloseOn(event.target.value)} />
            </>
          ) : null}
        </section>
      </div>
    </Dialog>
  )
}

/**
 * CRM-05, CRM-10..CRM-12: one lead: contact, source, value, owner and
 * status, and Edit, Assign, Disqualify, Archive or Restore and Convert as
 * the reader may (`can`).
 */
export function LeadDetail() {
  const { t } = useTranslation()
  const { leadId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [dialog, setDialog] = useState(null)
  const key = [...crmKey, 'lead', leadId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/leads/${leadId}`) })
  const action = useCrmAction(key)
  const lead = query.data?.data
  const back = <Back to="/crm/leads">{t('crm.leads.back')}</Back>
  if (!lead) return <DetailState query={query} back={back} notFound={t('crm.lead.notFound')} />

  const archived = Boolean(lead.archived_at)
  const live = !archived && LEAD_BOARD.includes(lead.status)
  const done = async (response, message) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    toast.success(t(message))
    await queryClient.invalidateQueries({ queryKey: crmKey })
  }

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('crm.lead.eyebrow')}
        title={lead.name}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Status status={lead.status} />
            {archived ? <Status status="archived" /> : null}
          </span>
        }
        actions={
          <>
            {lead.can?.edit && live ? <Button icon="edit" onClick={() => navigate(`/crm/leads/${lead.id}/edit`)}>{t('crm.actions.edit')}</Button> : null}
            {lead.can?.assign && live ? <Button onClick={() => setDialog('assign')}>{t('crm.lead.assign')}</Button> : null}
            {lead.can?.archive && live ? <Button variant="danger" onClick={() => setDialog('disqualify')}>{t('crm.lead.disqualify')}</Button> : null}
            {lead.can?.archive ? (
              archived ? (
                <Button icon="restore" loading={action.isPending} onClick={() => action.mutate({ path: `crm/leads/${lead.id}/restore`, done: 'crm.lead.restored' })}>{t('crm.actions.restore')}</Button>
              ) : (
                <Button icon="archive" loading={action.isPending} onClick={() => action.mutate({ path: `crm/leads/${lead.id}/archive`, done: 'crm.lead.archived' })}>{t('crm.actions.archive')}</Button>
              )
            ) : null}
            {can(ACTIVITY_MANAGE) && !archived ? <Button icon="plus" onClick={() => setDialog('activity')}>{t('crm.activity.log')}</Button> : null}
            {lead.can?.convert && live ? <Button variant="pay" onClick={() => setDialog('convert')}>{t('crm.lead.convert')}</Button> : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {lead.status === 'converted' ? (
          <Alert tone="success" title={t('crm.lead.convertedTitle')}>
            <span className="flex flex-wrap gap-4">
              {lead.party ? <Link to={`/contacts/customers/${lead.party.id}?tab=sales`} className="text-primary hover:text-primary-hover">{t('crm.lead.openCustomer', { name: lead.party.name })}</Link> : null}
              {lead.opportunity ? <Link to={`/crm/opportunities/${lead.opportunity.id}`} className="text-primary hover:text-primary-hover">{t('crm.lead.openOpportunity', { name: lead.opportunity.name })}</Link> : null}
            </span>
          </Alert>
        ) : null}
        {lead.status === 'disqualified' && lead.disqualify_reason ? <Alert tone="info" title={t('crm.lead.disqualifiedTitle')}>{lead.disqualify_reason}</Alert> : null}
        <Card title={t('crm.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('crm.fields.organisation')}>{lead.organisation || '—'}</Detail>
            <Detail label={t('crm.fields.title')}>{lead.title || '—'}</Detail>
            <Detail label={t('crm.fields.email')}>{lead.email || '—'}</Detail>
            <Detail label={t('crm.fields.phone')}>{lead.phone || '—'}</Detail>
            <Detail label={t('crm.fields.source')}>{lead.source?.name ?? '—'}</Detail>
            <Detail label={t('crm.fields.channel')}>{t(`crm.channels.${lead.channel}`, { defaultValue: lead.channel })}</Detail>
            <Detail label={t('crm.fields.estimatedValue')}><Amount value={lead.estimated_value} /></Detail>
            <Detail label={t('crm.fields.owner')}>{lead.owner?.name ?? t('crm.unassigned')}</Detail>
            <Detail label={t('crm.fields.team')}>{lead.team?.name ?? '—'}</Detail>
            <Detail label={t('crm.fields.branch')}>
              <span className="flex flex-col">
                <span>{lead.branch?.name}</span>
                <span className="text-caption text-ink-muted">{lead.company?.name}</span>
              </span>
            </Detail>
            <Detail label={t('crm.columns.createdAt')}><When value={lead.created_at} companyId={lead.company?.id} /></Detail>
            {lead.converted_at ? <Detail label={t('crm.lead.convertedAt')}><When value={lead.converted_at} companyId={lead.company?.id} /></Detail> : null}
            {lead.notes ? <Detail label={t('crm.fields.notes')}><span className="whitespace-pre-line">{lead.notes}</span></Detail> : null}
          </dl>
        </Card>
      </div>
      {dialog === 'assign' ? <AssignDialog lead={lead} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.lead.assigned')} /> : null}
      {dialog === 'disqualify' ? (
        <ReasonDialog
          title={t('crm.lead.disqualifyTitle', { name: lead.name })}
          label={t('crm.fields.reason')}
          confirm={t('crm.lead.disqualify')}
          variant="danger"
          path={`crm/leads/${lead.id}/disqualify`}
          onClose={() => setDialog(null)}
          onDone={(response) => done(response, 'crm.lead.disqualified')}
        />
      ) : null}
      {dialog === 'convert' ? <ConvertDialog lead={lead} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.lead.converted')} /> : null}
      {dialog === 'activity' ? (
        <ActivityDialog branchId={lead.branch?.id} links={{ lead_id: lead.id }} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); queryClient.invalidateQueries({ queryKey: crmKey }) }} />
      ) : null}
    </>
  )
}

