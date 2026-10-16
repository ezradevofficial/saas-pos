import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, Dialog, MoneyInput, MultiSelect, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Status, When } from './CrmParts'
import { useCrmAction } from './crmHelpers'
import { crmKey, LEAD_CHANNELS, LEAD_FORM_FIELDS, namedOptions, OPTION_KINDS, SETTINGS_MANAGE, stagesOf, useAssignees, useCrmCompany, useCrmOptions, useCrmSettings, usePipelines, usePlaces, useTeams } from './crmData'

const TABS = ['general', 'pipelines', 'options', 'teams', 'rules', 'forms']

const filled = (value) => (Array.isArray(value) ? value.length > 0 : value !== '' && value !== null && value !== undefined)

/**
 * A small form in a dialog. `fields`: [{name, label, type (text, number,
 * select, multi, checkbox, money), options, required, help, currency,
 * when(values), resets: [names]}]; `submit(values)` saves and `onDone`
 * gets the API's answer.
 */
function FormDialog({ title, fields, initial, confirm, submit, onClose, onDone }) {
  const { t } = useTranslation()
  const [values, setValues] = useState(initial)
  const save = useMutation({ mutationFn: () => submit(values), onSuccess: onDone })
  const shown = fields.filter((field) => !field.when || field.when(values))
  const errors = formErrors(save.error, shown.map((field) => field.api ?? field.name))
  const ready = shown.every((field) => !field.required || filled(values[field.name])) && shown.every((field) => values[field.name] !== null || field.type !== 'money')
  const set = (field, value) => setValues((current) => ({ ...current, ...Object.fromEntries((field.resets ?? []).map((name) => [name, Array.isArray(current[name]) ? [] : ''])), [field.name]: value }))
  return (
    <Dialog
      open
      size="lg"
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!ready} onClick={() => save.mutate()}>{confirm ?? t('crm.actions.save')}</Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1 sm:grid-cols-2">
        {errors.form ? <Alert tone="danger" title={errors.form} className="sm:col-span-2" /> : null}
        {shown.map((field) => {
          const error = errors.fields[field.api ?? field.name]
          const common = { label: field.label, help: field.help, required: field.required, error, className: field.wide ? 'sm:col-span-2' : undefined }
          if (field.type === 'select') return <Select key={field.name} {...common} options={field.options} placeholder={field.placeholder} value={values[field.name]} onChange={(event) => set(field, event.target.value)} />
          if (field.type === 'multi') return <MultiSelect key={field.name} {...common} options={field.options} placeholder={field.placeholder} value={values[field.name]} onChange={(next) => set(field, next)} />
          if (field.type === 'checkbox') return <Checkbox key={field.name} label={field.label} help={field.help} checked={Boolean(values[field.name])} onChange={(event) => set(field, event.target.checked)} className={common.className} />
          if (field.type === 'money') return <MoneyInput key={field.name} {...common} currency={field.currency} value={values[field.name]} onChange={(next) => set(field, next)} />
          return <TextField key={field.name} {...common} inputMode={field.type === 'number' ? 'numeric' : undefined} suffix={field.suffix} maxLength={field.maxLength ?? 200} value={values[field.name]} onChange={(event) => set(field, event.target.value)} />
        })}
      </div>
    </Dialog>
  )
}

/** A whole number typed in a field (a probability, a position, days), or null when empty. */
const whole = (text) => (String(text ?? '').trim() === '' ? null : Number(String(text).trim()))
const probabilityValid = (text) => /^\d{1,3}$/.test(String(text ?? '').trim()) && Number(text) <= 100

/** CRM-01: approval threshold, quote validity, default team and whether a lost reason is required. */
function General({ company, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const query = useCrmSettings(company.id)
  const teams = useTeams(company.id)
  const settings = query.data?.data
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!settings) return <p className="text-ink-muted">{t('common.loading')}</p>
  return <GeneralForm key={JSON.stringify(settings)} settings={settings} teams={teams.active} company={company} canManage={canManage && settings.can?.manage !== false} onSaved={(response) => queryClient.setQueryData([...crmKey, 'settings', company.id], response)} />
}

function GeneralForm({ settings, teams, company, canManage, onSaved }) {
  const { t } = useTranslation()
  const [threshold, setThreshold] = useState(settings.quote_approval_minor ?? '')
  const [days, setDays] = useState(String(settings.quote_validity_days ?? 30))
  const [team, setTeam] = useState(settings.default_team?.id ?? '')
  const [lostRequired, setLostRequired] = useState(settings.lost_reason_required !== false)
  const save = useMutation({
    mutationFn: () => api.put(`crm/companies/${company.id}/settings`, { quote_approval_minor: threshold === '' ? null : threshold, quote_validity_days: Number(days), default_team_id: team || null, lost_reason_required: lostRequired }),
    onSuccess: (response) => {
      toast.success(t('crm.settings.saved'))
      onSaved(response)
    },
  })
  const errors = formErrors(save.error, ['quote_approval_minor', 'quote_validity_days', 'default_team_id', 'lost_reason_required'])
  const daysValid = /^\d{1,4}$/.test(days.trim()) && Number(days) >= 1
  return (
    <Card title={company.name}>
      <div className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <MoneyInput
            label={t('crm.settings.threshold')}
            help={t('crm.settings.thresholdHelp')}
            currency={settings.currency ?? company.base_currency}
            value={threshold}
            disabled={!canManage}
            error={errors.fields.quote_approval_minor}
            onChange={setThreshold}
          />
          <TextField
            label={t('crm.settings.validityDays')}
            help={t('crm.settings.validityHelp')}
            inputMode="numeric"
            value={days}
            disabled={!canManage}
            error={errors.fields.quote_validity_days ?? (!daysValid ? t('crm.validation.days') : undefined)}
            onChange={(event) => setDays(event.target.value)}
          />
          <Select
            label={t('crm.settings.defaultTeam')}
            help={t('crm.settings.defaultTeamHelp')}
            options={[{ value: '', label: t('crm.settings.noDefaultTeam') }, ...namedOptions(teams)]}
            value={team}
            disabled={!canManage}
            error={errors.fields.default_team_id}
            onChange={(event) => setTeam(event.target.value)}
          />
          <div className="flex flex-col gap-1">
            <Switch label={t('crm.settings.lostReasonRequired')} checked={lostRequired} disabled={!canManage} onChange={setLostRequired} />
            <p className="text-caption text-ink-muted">{t('crm.settings.lostReasonHelp')}</p>
          </div>
        </div>
        {canManage ? (
          <div>
            <Button variant="primary" loading={save.isPending} disabled={threshold === null || !daysValid} onClick={() => save.mutate()}>{t('crm.settings.save')}</Button>
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('crm.settings.readOnly')}</p>
        )}
      </div>
    </Card>
  )
}

/** CRM-02: pipelines and their stages (add, rename, probability, order, archive), one default per company. */
function Pipelines({ company, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const pipelines = usePipelines(company.id, 'all')
  const action = useCrmAction(null)
  const [dialog, setDialog] = useState(null)
  const done = async (message) => {
    setDialog(null)
    toast.success(t(message))
    await queryClient.invalidateQueries({ queryKey: crmKey })
  }
  const move = (stages, index, direction) => {
    const other = stages[index + direction]
    action.mutate({ method: 'patch', path: `crm/stages/${stages[index].id}`, body: { position: other.position }, done: 'crm.pipelines.stageMoved' })
  }
  if (pipelines.isError) return <Alert tone="danger" title={errorMessage(pipelines.error)} />
  return (
    <div className="flex flex-col gap-4">
      {canManage ? (
        <div>
          <Button icon="plus" onClick={() => setDialog({ kind: 'pipeline' })}>{t('crm.pipelines.new')}</Button>
        </div>
      ) : null}
      {pipelines.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {pipelines.all.map((pipeline) => {
        const stages = stagesOf(pipeline)
        const archived = Boolean(pipeline.archived_at)
        const columns = [
          { key: 'position', label: t('crm.columns.position'), numeric: true, render: (stage) => stages.indexOf(stage) + 1 },
          { key: 'name', label: t('crm.columns.name'), render: (stage) => stage.name },
          { key: 'probability', label: t('crm.columns.probability'), align: 'end', numeric: true, render: (stage) => `${stage.probability} %` },
          ...(canManage && !archived
            ? [
                {
                  key: 'actions',
                  label: t('crm.columns.actions'),
                  align: 'end',
                  render: (stage) => {
                    const index = stages.indexOf(stage)
                    return (
                      <span className="flex justify-end gap-1">
                        <Button variant="ghost" icon="up" disabled={index === 0} aria-label={t('crm.pipelines.moveUp', { name: stage.name })} onClick={() => move(stages, index, -1)} />
                        <Button variant="ghost" icon="down" disabled={index === stages.length - 1} aria-label={t('crm.pipelines.moveDown', { name: stage.name })} onClick={() => move(stages, index, 1)} />
                        <Button variant="ghost" icon="edit" aria-label={t('crm.pipelines.editStage', { name: stage.name })} onClick={() => setDialog({ kind: 'stage', stage })} />
                        <Button variant="ghost" icon="archive" aria-label={t('crm.pipelines.archiveStage', { name: stage.name })} onClick={() => action.mutate({ path: `crm/stages/${stage.id}/archive`, done: 'crm.pipelines.stageArchived' })} />
                      </span>
                    )
                  },
                },
              ]
            : []),
        ]
        return (
          <Card
            key={pipeline.id}
            title={pipeline.name}
            subtitle={
              <span className="flex flex-wrap gap-3">
                {pipeline.is_default ? <StatusBadge tone="info">{t('crm.pipelines.default')}</StatusBadge> : null}
                {archived ? <Status status="archived" /> : null}
              </span>
            }
            actions={
              canManage ? (
                <>
                  {!archived ? <Button variant="ghost" icon="edit" aria-label={t('crm.pipelines.rename', { name: pipeline.name })} onClick={() => setDialog({ kind: 'pipeline', pipeline })} /> : null}
                  {!archived && !pipeline.is_default ? <Button variant="ghost" onClick={() => action.mutate({ method: 'patch', path: `crm/pipelines/${pipeline.id}`, body: { is_default: true }, done: 'crm.pipelines.saved' })}>{t('crm.pipelines.makeDefault')}</Button> : null}
                  <Button variant="ghost" icon={archived ? 'restore' : 'archive'} onClick={() => action.mutate({ path: `crm/pipelines/${pipeline.id}/${archived ? 'restore' : 'archive'}`, done: archived ? 'crm.pipelines.restored' : 'crm.pipelines.archived' })}>
                    {t(archived ? 'crm.actions.restore' : 'crm.actions.archive')}
                  </Button>
                </>
              ) : null
            }
          >
            <div className="flex flex-col gap-3">
              <DataTable caption={t('crm.pipelines.stagesOf', { name: pipeline.name })} columns={columns} rows={stages} emptyText={t('crm.pipelines.noStages')} />
              {canManage && !archived ? (
                <div>
                  <Button icon="plus" onClick={() => setDialog({ kind: 'stage', pipeline })}>{t('crm.pipelines.addStage')}</Button>
                </div>
              ) : null}
            </div>
          </Card>
        )
      })}
      {dialog?.kind === 'pipeline' ? (
        <FormDialog
          title={t(dialog.pipeline ? 'crm.pipelines.renameTitle' : 'crm.pipelines.newTitle')}
          initial={{ name: dialog.pipeline?.name ?? '', is_default: dialog.pipeline?.is_default ?? false }}
          fields={[
            { name: 'name', label: t('crm.columns.name'), required: true, wide: true },
            ...(dialog.pipeline ? [] : [{ name: 'is_default', type: 'checkbox', label: t('crm.pipelines.isDefault'), wide: true }]),
          ]}
          submit={(values) => (dialog.pipeline ? api.patch(`crm/pipelines/${dialog.pipeline.id}`, { name: values.name.trim() }) : api.post('crm/pipelines', { company_id: company.id, name: values.name.trim(), is_default: values.is_default }))}
          onClose={() => setDialog(null)}
          onDone={() => done(dialog.pipeline ? 'crm.pipelines.saved' : 'crm.pipelines.created')}
        />
      ) : null}
      {dialog?.kind === 'stage' ? (
        <FormDialog
          title={t(dialog.stage ? 'crm.pipelines.editStageTitle' : 'crm.pipelines.addStageTitle')}
          initial={{ name: dialog.stage?.name ?? '', probability: String(dialog.stage?.probability ?? '') }}
          fields={[
            { name: 'name', label: t('crm.columns.name'), required: true },
            { name: 'probability', type: 'number', suffix: '%', label: t('crm.fields.probability'), help: t('crm.pipelines.probabilityHelp'), required: true },
          ]}
          submit={(values) => {
            if (!probabilityValid(values.probability)) return Promise.reject(Object.assign(new Error(t('crm.validation.probability')), { errors: { probability: [t('crm.validation.probability')] }, status: 422 }))
            const body = { name: values.name.trim(), probability: whole(values.probability) }
            return dialog.stage ? api.patch(`crm/stages/${dialog.stage.id}`, body) : api.post(`crm/pipelines/${dialog.pipeline.id}/stages`, body)
          }}
          onClose={() => setDialog(null)}
          onDone={() => done(dialog.stage ? 'crm.pipelines.stageSaved' : 'crm.pipelines.stageAdded')}
        />
      ) : null}
    </div>
  )
}

/** CRM-03: one kind of option (sources, won reasons, lost reasons): add, rename, order, archive, restore. */
function OptionList({ company, kind, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const options = useCrmOptions(company.id, kind, 'all')
  const action = useCrmAction(null)
  const [dialog, setDialog] = useState(null)
  const live = [...options.active].sort((a, b) => a.position - b.position)
  const rows = [...live, ...options.all.filter((option) => option.archived_at)]
  const columns = [
    { key: 'name', label: t('crm.columns.name'), render: (option) => option.name },
    { key: 'status', label: t('crm.columns.status'), render: (option) => <Status status={option.archived_at ? 'archived' : 'active'} /> },
    ...(canManage
      ? [
          {
            key: 'actions',
            label: t('crm.columns.actions'),
            align: 'end',
            render: (option) => {
              const index = live.indexOf(option)
              if (option.archived_at) return <Button variant="ghost" icon="restore" onClick={() => action.mutate({ path: `crm/options/${option.id}/restore`, done: 'crm.options.restored' })}>{t('crm.actions.restore')}</Button>
              return (
                <span className="flex justify-end gap-1">
                  <Button variant="ghost" icon="up" disabled={index === 0} aria-label={t('crm.pipelines.moveUp', { name: option.name })} onClick={() => action.mutate({ method: 'patch', path: `crm/options/${option.id}`, body: { position: live[index - 1].position }, done: 'crm.options.saved' })} />
                  <Button variant="ghost" icon="down" disabled={index === live.length - 1} aria-label={t('crm.pipelines.moveDown', { name: option.name })} onClick={() => action.mutate({ method: 'patch', path: `crm/options/${option.id}`, body: { position: live[index + 1].position }, done: 'crm.options.saved' })} />
                  <Button variant="ghost" icon="edit" aria-label={t('crm.options.rename', { name: option.name })} onClick={() => setDialog(option)} />
                  <Button variant="ghost" icon="archive" aria-label={t('crm.options.archive', { name: option.name })} onClick={() => action.mutate({ path: `crm/options/${option.id}/archive`, done: 'crm.options.archived' })} />
                </span>
              )
            },
          },
        ]
      : []),
  ]
  return (
    <Card title={t(`crm.options.kinds.${kind}`)} actions={canManage ? <Button icon="plus" onClick={() => setDialog('new')}>{t('crm.options.add')}</Button> : null}>
      <DataTable caption={t(`crm.options.kinds.${kind}`)} columns={columns} rows={rows} loading={options.isPending} emptyText={t('crm.options.empty')} />
      {dialog ? (
        <FormDialog
          title={t(dialog === 'new' ? 'crm.options.addTitle' : 'crm.options.renameTitle', { kind: t(`crm.options.kinds.${kind}`) })}
          initial={{ name: dialog === 'new' ? '' : dialog.name }}
          fields={[{ name: 'name', label: t('crm.columns.name'), required: true, wide: true }]}
          submit={(values) => (dialog === 'new' ? api.post('crm/options', { company_id: company.id, kind, name: values.name.trim() }) : api.patch(`crm/options/${dialog.id}`, { name: values.name.trim() }))}
          onClose={() => setDialog(null)}
          onDone={async () => {
            setDialog(null)
            toast.success(t('crm.options.saved'))
            await queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
    </Card>
  )
}

/** CRM-04: teams with their branch, leader and members. */
function Teams({ company, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const users = useAssignees(null)
  const teams = useTeams(company.id, 'all')
  const action = useCrmAction(null)
  const [dialog, setDialog] = useState(null)
  const branches = places.activeBranches.filter((branch) => branch.company_id === company.id)
  const columns = [
    { key: 'name', label: t('crm.columns.name'), render: (team) => <span className="font-medium text-ink">{team.name}</span> },
    { key: 'branch', label: t('crm.columns.branch'), render: (team) => team.branch?.name ?? t('crm.teams.allBranches') },
    { key: 'leader', label: t('crm.columns.leader'), render: (team) => team.leader?.name ?? '—' },
    { key: 'members', label: t('crm.columns.members'), wrap: true, render: (team) => (team.members ?? []).map((member) => member.name).join(', ') || '—' },
    { key: 'status', label: t('crm.columns.status'), render: (team) => <Status status={team.archived_at ? 'archived' : 'active'} /> },
    ...(canManage
      ? [
          {
            key: 'actions',
            label: t('crm.columns.actions'),
            align: 'end',
            render: (team) => (
              <span className="flex justify-end gap-1">
                {!team.archived_at ? <Button variant="ghost" icon="edit" aria-label={t('crm.teams.edit', { name: team.name })} onClick={() => setDialog(team)} /> : null}
                <Button variant="ghost" icon={team.archived_at ? 'restore' : 'archive'} aria-label={t(team.archived_at ? 'crm.teams.restore' : 'crm.teams.archive', { name: team.name })} onClick={() => action.mutate({ path: `crm/teams/${team.id}/${team.archived_at ? 'restore' : 'archive'}`, done: 'crm.teams.saved' })} />
              </span>
            ),
          },
        ]
      : []),
  ]
  return (
    <Card title={t('crm.teams.title')} actions={canManage ? <Button icon="plus" onClick={() => setDialog('new')}>{t('crm.teams.new')}</Button> : null}>
      <DataTable caption={t('crm.teams.title')} columns={columns} rows={teams.all} loading={teams.isPending} emptyText={t('crm.teams.empty')} />
      {dialog ? (
        <FormDialog
          title={t(dialog === 'new' ? 'crm.teams.newTitle' : 'crm.teams.editTitle')}
          initial={{
            name: dialog === 'new' ? '' : dialog.name,
            branch_id: dialog === 'new' ? '' : (dialog.branch?.id ?? ''),
            leader_id: dialog === 'new' ? '' : (dialog.leader?.id ?? ''),
            members: dialog === 'new' ? [] : (dialog.members ?? []).map((member) => member.id),
          }}
          fields={[
            { name: 'name', label: t('crm.columns.name'), required: true },
            { name: 'branch_id', type: 'select', label: t('crm.fields.branch'), options: [{ value: '', label: t('crm.teams.allBranches') }, ...namedOptions(branches)] },
            { name: 'leader_id', type: 'select', label: t('crm.columns.leader'), options: [{ value: '', label: t('crm.pickers.none') }, ...namedOptions(users)] },
            { name: 'members', type: 'multi', label: t('crm.columns.members'), placeholder: t('crm.pickers.members'), options: namedOptions(users), wide: true },
          ]}
          submit={(values) => {
            const body = { name: values.name.trim(), branch_id: values.branch_id || null, leader_id: values.leader_id || null, members: values.members.map((id) => ({ user_id: id })) }
            return dialog === 'new' ? api.post('crm/teams', { company_id: company.id, ...body }) : api.patch(`crm/teams/${dialog.id}`, body)
          }}
          onClose={() => setDialog(null)}
          onDone={async () => {
            setDialog(null)
            toast.success(t('crm.teams.saved'))
            await queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
    </Card>
  )
}

/** CRM-11: assignment rules in order (branch, sources, channels → a user or a team). */
function Rules({ company, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const users = useAssignees(null)
  const teams = useTeams(company.id)
  const sources = useCrmOptions(company.id, 'source')
  const key = [...crmKey, 'assignment-rules', company.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/assignment-rules?company=${company.id}`), enabled: canManage, retry: false })
  const action = useCrmAction(null)
  const [dialog, setDialog] = useState(null)
  if (!canManage) return <p className="text-body text-ink-muted">{t('crm.settings.manageOnly')}</p>
  const rows = [...(query.data?.data ?? [])].filter((rule) => !rule.archived_at).sort((a, b) => a.position - b.position)
  const branches = places.activeBranches.filter((branch) => branch.company_id === company.id)
  const columns = [
    { key: 'position', label: t('crm.columns.position'), numeric: true, render: (rule) => rule.position },
    { key: 'name', label: t('crm.columns.name'), render: (rule) => <span className="font-medium text-ink">{rule.name}</span> },
    { key: 'branch', label: t('crm.columns.branch'), render: (rule) => rule.branch?.name ?? t('crm.rules.any') },
    { key: 'sources', label: t('crm.columns.sources'), wrap: true, render: (rule) => (rule.sources ?? []).map((source) => source.name).join(', ') || t('crm.rules.any') },
    { key: 'channels', label: t('crm.columns.channels'), wrap: true, render: (rule) => (rule.channels ?? []).map((channel) => t(`crm.channels.${channel}`, { defaultValue: channel })).join(', ') || t('crm.rules.any') },
    { key: 'assignee', label: t('crm.columns.assignee'), render: (rule) => (rule.assignee ? `${rule.assignee.name} · ${t(`crm.rules.assigneeTypes.${rule.assignee.type}`)}` : '—') },
    { key: 'active', label: t('crm.columns.status'), render: (rule) => <Status status={rule.active ? 'active' : 'archived'} /> },
    {
      key: 'actions',
      label: t('crm.columns.actions'),
      align: 'end',
      render: (rule) => (
        <span className="flex justify-end gap-1">
          <Button variant="ghost" icon="edit" aria-label={t('crm.rules.edit', { name: rule.name })} onClick={() => setDialog(rule)} />
          <Button variant="ghost" icon="archive" aria-label={t('crm.rules.archive', { name: rule.name })} onClick={() => action.mutate({ path: `crm/assignment-rules/${rule.id}/archive`, done: 'crm.rules.archived' })} />
        </span>
      ),
    },
  ]
  return (
    <Card title={t('crm.rules.title')} subtitle={t('crm.rules.help')} actions={<Button icon="plus" onClick={() => setDialog('new')}>{t('crm.rules.new')}</Button>}>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable caption={t('crm.rules.title')} columns={columns} rows={rows} loading={query.isPending} emptyText={t('crm.rules.empty')} />
      {dialog ? (
        <FormDialog
          title={t(dialog === 'new' ? 'crm.rules.newTitle' : 'crm.rules.editTitle')}
          initial={{
            name: dialog === 'new' ? '' : dialog.name,
            position: dialog === 'new' ? String(rows.length + 1) : String(dialog.position ?? ''),
            branch_id: dialog === 'new' ? '' : (dialog.branch?.id ?? ''),
            source_ids: dialog === 'new' ? [] : (dialog.sources ?? []).map((source) => source.id),
            channels: dialog === 'new' ? [] : (dialog.channels ?? []),
            assignee_type: dialog === 'new' ? 'user' : (dialog.assignee?.type ?? 'user'),
            user_id: dialog !== 'new' && dialog.assignee?.type === 'user' ? dialog.assignee.id : '',
            team_id: dialog !== 'new' && dialog.assignee?.type === 'team' ? dialog.assignee.id : '',
            active: dialog === 'new' ? true : dialog.active !== false,
          }}
          fields={[
            { name: 'name', label: t('crm.columns.name'), required: true },
            { name: 'position', type: 'number', label: t('crm.columns.position'), help: t('crm.rules.positionHelp') },
            { name: 'branch_id', type: 'select', label: t('crm.fields.branch'), options: [{ value: '', label: t('crm.rules.any') }, ...namedOptions(branches)] },
            { name: 'source_ids', type: 'multi', label: t('crm.columns.sources'), placeholder: t('crm.rules.any'), options: namedOptions(sources.active) },
            { name: 'channels', type: 'multi', label: t('crm.columns.channels'), placeholder: t('crm.rules.any'), options: LEAD_CHANNELS.map((channel) => ({ value: channel, label: t(`crm.channels.${channel}`) })) },
            { name: 'assignee_type', type: 'select', label: t('crm.rules.assigneeType'), options: ['user', 'team'].map((type) => ({ value: type, label: t(`crm.rules.assigneeTypes.${type}`) })), resets: ['user_id', 'team_id'] },
            { name: 'user_id', type: 'select', label: t('crm.rules.assigneeTypes.user'), placeholder: t('crm.pickers.user'), options: namedOptions(users), required: true, when: (values) => values.assignee_type === 'user' },
            { name: 'team_id', type: 'select', label: t('crm.rules.assigneeTypes.team'), placeholder: t('crm.pickers.team'), options: namedOptions(teams.active), required: true, when: (values) => values.assignee_type === 'team' },
            { name: 'active', type: 'checkbox', label: t('crm.rules.active'), wide: true },
          ]}
          submit={(values) => {
            const body = {
              name: values.name.trim(),
              ...(whole(values.position) !== null ? { position: whole(values.position) } : {}),
              branch_id: values.branch_id || null,
              source_ids: values.source_ids,
              channels: values.channels,
              assignee_type: values.assignee_type,
              ...(values.assignee_type === 'user' ? { user_id: values.user_id } : { team_id: values.team_id }),
              active: values.active,
            }
            return dialog === 'new' ? api.post('crm/assignment-rules', { company_id: company.id, ...body }) : api.patch(`crm/assignment-rules/${dialog.id}`, body)
          }}
          onClose={() => setDialog(null)}
          onDone={async () => {
            setDialog(null)
            toast.success(t('crm.rules.saved'))
            await queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
    </Card>
  )
}

/** CRM-06: the public link of a form, shown once, with an embed snippet to copy. */
function FormLink({ form, url, onClose }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(null)
  const shown = form.fields?.shown?.length ? form.fields.shown : ['name', 'email', 'phone']
  const required = form.fields?.required ?? []
  const input = (field) => {
    const type = field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'
    const tag = field === 'notes' ? `<textarea name="notes"${required.includes(field) ? ' required' : ''}></textarea>` : `<input name="${field}" type="${type}"${required.includes(field) ? ' required' : ''}>`
    return `  <label>${t(`crm.leadForms.fields.${field}`)}\n    ${tag}\n  </label>`
  }
  const snippet = [`<form method="post" action="${url}">`, ...shown.map(input), `  <button type="submit">${t('crm.leadForms.snippetSubmit')}</button>`, '</form>'].join('\n')
  const copy = async (what, text) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(what)
    } catch {
      setCopied(null)
    }
  }
  return (
    <Dialog open size="lg" title={t('crm.leadForms.linkTitle', { name: form.name })} onClose={onClose} footer={<Button variant="primary" onClick={onClose}>{t('crm.leadForms.done')}</Button>}>
      <div className="flex flex-col gap-4 pt-1">
        <Alert tone="warning" title={t('crm.leadForms.onceTitle')}>{t('crm.leadForms.onceHelp')}</Alert>
        <div className="flex flex-col gap-2">
          <TextField label={t('crm.leadForms.url')} value={url} readOnly />
          <Button icon="copy" className="w-fit" onClick={() => copy('url', url)}>{copied === 'url' ? t('crm.leadForms.copied') : t('crm.leadForms.copyUrl')}</Button>
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-label text-ink">{t('crm.leadForms.snippet')}</span>
          <pre className="max-h-panel overflow-auto rounded-md border border-border bg-surface-100 p-3 font-mono text-caption text-ink">{snippet}</pre>
          <Button icon="copy" className="w-fit" onClick={() => copy('snippet', snippet)}>{copied === 'snippet' ? t('crm.leadForms.copied') : t('crm.leadForms.copySnippet')}</Button>
        </div>
      </div>
    </Dialog>
  )
}

/** CRM-06: lead forms (branch, source, team, fields shown and required), their public link and submissions. */
function LeadForms({ company, canManage }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const teams = useTeams(company.id)
  const sources = useCrmOptions(company.id, 'source')
  const key = [...crmKey, 'lead-forms', company.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/lead-forms?company=${company.id}`), enabled: canManage, retry: false })
  const action = useCrmAction(null)
  const [dialog, setDialog] = useState(null)
  const [link, setLink] = useState(null)
  const rotate = useMutation({
    mutationFn: (form) => api.post(`crm/lead-forms/${form.id}/rotate`),
    onSuccess: async (response, form) => {
      setLink({ form: response.data ?? form, url: response.data?.url })
      await queryClient.invalidateQueries({ queryKey: crmKey })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
  if (!canManage) return <p className="text-body text-ink-muted">{t('crm.settings.manageOnly')}</p>
  const rows = (query.data?.data ?? []).filter((form) => !form.archived_at)
  const branches = places.activeBranches.filter((branch) => branch.company_id === company.id)
  const fieldOptions = LEAD_FORM_FIELDS.map((field) => ({ value: field, label: t(`crm.leadForms.fields.${field}`) }))
  const columns = [
    { key: 'name', label: t('crm.columns.name'), render: (form) => <span className="font-medium text-ink">{form.name}</span> },
    { key: 'branch', label: t('crm.columns.branch'), render: (form) => form.branch?.name ?? '' },
    { key: 'source', label: t('crm.columns.source'), render: (form) => form.source?.name ?? '—' },
    { key: 'team', label: t('crm.columns.team'), render: (form) => form.team?.name ?? '—' },
    { key: 'submissions', label: t('crm.columns.submissions'), align: 'end', numeric: true, render: (form) => form.submissions ?? 0 },
    { key: 'last_submitted_at', label: t('crm.columns.lastSubmitted'), render: (form) => <When value={form.last_submitted_at} companyId={company.id} /> },
    {
      key: 'actions',
      label: t('crm.columns.actions'),
      align: 'end',
      render: (form) => (
        <span className="flex justify-end gap-1">
          <Button variant="ghost" icon="edit" aria-label={t('crm.leadForms.edit', { name: form.name })} onClick={() => setDialog(form)} />
          <Button variant="ghost" icon="sync" loading={rotate.isPending && rotate.variables?.id === form.id} onClick={() => rotate.mutate(form)}>{t('crm.leadForms.rotate')}</Button>
          <Button variant="ghost" icon="archive" aria-label={t('crm.leadForms.archive', { name: form.name })} onClick={() => action.mutate({ path: `crm/lead-forms/${form.id}/archive`, done: 'crm.leadForms.archived' })} />
        </span>
      ),
    },
  ]
  return (
    <Card title={t('crm.leadForms.title')} subtitle={t('crm.leadForms.help')} actions={<Button icon="plus" onClick={() => setDialog('new')}>{t('crm.leadForms.new')}</Button>}>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable caption={t('crm.leadForms.title')} columns={columns} rows={rows} loading={query.isPending} emptyText={t('crm.leadForms.empty')} />
      {dialog ? (
        <FormDialog
          title={t(dialog === 'new' ? 'crm.leadForms.newTitle' : 'crm.leadForms.editTitle')}
          initial={{
            name: dialog === 'new' ? '' : dialog.name,
            branch_id: dialog === 'new' ? (branches.length === 1 ? branches[0].id : '') : (dialog.branch?.id ?? ''),
            source_id: dialog === 'new' ? '' : (dialog.source?.id ?? ''),
            team_id: dialog === 'new' ? '' : (dialog.team?.id ?? ''),
            shown: dialog === 'new' ? ['name', 'email', 'phone'] : (dialog.fields?.shown ?? []),
            required: dialog === 'new' ? ['name'] : (dialog.fields?.required ?? []),
          }}
          fields={[
            { name: 'name', label: t('crm.columns.name'), required: true },
            { name: 'branch_id', type: 'select', label: t('crm.fields.branch'), placeholder: t('crm.pickers.branch'), options: namedOptions(branches), required: true },
            { name: 'source_id', type: 'select', label: t('crm.fields.source'), options: [{ value: '', label: t('crm.pickers.none') }, ...namedOptions(sources.active)] },
            { name: 'team_id', type: 'select', label: t('crm.fields.team'), options: [{ value: '', label: t('crm.pickers.none') }, ...namedOptions(teams.active)] },
            { name: 'shown', api: 'fields.shown', type: 'multi', label: t('crm.leadForms.shown'), options: fieldOptions, required: true },
            { name: 'required', api: 'fields.required', type: 'multi', label: t('crm.leadForms.required'), help: t('crm.leadForms.requiredHelp'), options: fieldOptions },
          ]}
          submit={(values) => {
            const body = { name: values.name.trim(), branch_id: values.branch_id, source_id: values.source_id || null, team_id: values.team_id || null, fields: { shown: values.shown, required: values.required.filter((field) => values.shown.includes(field)) } }
            return dialog === 'new' ? api.post('crm/lead-forms', { company_id: company.id, ...body }) : api.patch(`crm/lead-forms/${dialog.id}`, body)
          }}
          onClose={() => setDialog(null)}
          onDone={async (response) => {
            const created = dialog === 'new'
            setDialog(null)
            toast.success(t(created ? 'crm.leadForms.created' : 'crm.leadForms.saved'))
            if (created && response?.data?.url) setLink({ form: response.data, url: response.data.url })
            await queryClient.invalidateQueries({ queryKey: crmKey })
          }}
        />
      ) : null}
      {link?.url ? <FormLink form={link.form} url={link.url} onClose={() => setLink(null)} /> : null}
    </Card>
  )
}

/**
 * CRM-01..CRM-04, CRM-06, CRM-11: a company's sales settings, pipelines
 * and stages, options, teams, assignment rules and lead forms. The company
 * comes from the switcher, or a picker when several are shown; changes
 * need crm.settings.manage at the company.
 */
export default function CrmSettings() {
  const { t } = useTranslation()
  const { canWithin } = usePermissions()
  const { company, companies, choose, ready } = useCrmCompany()
  const [tab, setTab] = useState('general')
  const canManage = Boolean(company) && canWithin(SETTINGS_MANAGE, [{ type: 'company', id: company.id }])
  return (
    <>
      <PageHeader title={t('crm.settings.title')} description={t('crm.settings.description')} />
      <div className="flex flex-col gap-5">
        {companies.length > 1 ? <Select label={t('crm.fields.company')} options={namedOptions(companies)} value={company?.id ?? ''} onChange={(event) => choose(event.target.value)} className="max-w-field" /> : null}
        {!company ? <p className="text-ink-muted">{ready ? t('crm.settings.noCompany') : t('common.loading')}</p> : null}
        {company ? (
          <>
            <Tabs items={TABS.map((value) => ({ value, label: t(`crm.settings.tabs.${value}`) }))} value={tab} onChange={setTab} />
            {tab === 'general' ? <General key={company.id} company={company} canManage={canManage} /> : null}
            {tab === 'pipelines' ? <Pipelines key={company.id} company={company} canManage={canManage} /> : null}
            {tab === 'options' ? (
              <div className="flex flex-col gap-4">
                {OPTION_KINDS.map((kind) => <OptionList key={`${company.id}-${kind}`} company={company} kind={kind} canManage={canManage} />)}
              </div>
            ) : null}
            {tab === 'teams' ? <Teams key={company.id} company={company} canManage={canManage} /> : null}
            {tab === 'rules' ? <Rules key={company.id} company={company} canManage={canManage} /> : null}
            {tab === 'forms' ? <LeadForms key={company.id} company={company} canManage={canManage} /> : null}
          </>
        ) : null}
      </div>
    </>
  )
}

