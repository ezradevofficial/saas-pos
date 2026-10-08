import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Select, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies } from '@/layouts/companySelection'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConditionEditor } from '@/pages/workflows/ConditionEditor'
import { useRoleOptions, useUserOptions } from '@/pages/workflows/workflowData'
import { ActionsEditor } from './ActionsEditor'
import { RuleStatus } from './AutomationRules'
import { hasDocument, newTrigger, ruleBody, useAutomationCatalogue, useAutomationRights, useFlowStages } from './automationData'
import { summarizeRule } from './describeRule'
import { RunsList } from './RunLog'
import { TestPanel } from './TestPanel'
import { TriggerEditor } from './TriggerEditor'

const ALL = 'all'
const NEW = 'new'

const fromRule = (rule) => ({
  name: rule.name ?? '',
  document_type: rule.document_type,
  company_id: rule.company_id ?? null,
  trigger: rule.trigger ?? { type: 'record_created' },
  conditions: rule.conditions ?? null,
  actions: Array.isArray(rule.actions) ? rule.actions : [],
})

const signature = (draft) => JSON.stringify(ruleBody(draft))

/**
 * A 422's field messages by where they belong: the form's own fields, the
 * trigger, the conditions, each action (`actions.N`, or anything under
 * it) and the rest, which shows at the top.
 */
function sortErrors(error) {
  const out = { fields: {}, trigger: [], conditions: [], actions: {}, actionsSection: [], other: [] }
  for (const [path, list] of Object.entries(error?.errors ?? {})) {
    const messages = Array.isArray(list) ? list : [String(list)]
    const action = /^actions\.(\d+)/.exec(path)
    if (['name', 'document_type', 'company_id'].includes(path)) out.fields[path] = messages[0]
    else if (path === 'trigger' || path.startsWith('trigger.')) out.trigger.push(...messages)
    else if (path === 'conditions' || path.startsWith('conditions.')) out.conditions.push(...messages)
    else if (action) out.actions[action[1]] = [...(out.actions[action[1]] ?? []), ...messages]
    else if (path === 'actions' || path === 'webhook_secret') out.actionsSection.push(...messages)
    else out.other.push(...messages)
  }
  return out
}

function Messages({ messages }) {
  if (!messages?.length) return null
  return (
    <ul role="alert" className="flex flex-col gap-1 text-caption text-danger">
      {messages.map((message) => (
        <li key={message}>{message}</li>
      ))}
    </ul>
  )
}

/**
 * The automation rule editor (AUTO-01..AUTO-04): a plain-words summary,
 * then When (the trigger), Only if (conditions, optional) and Then (the
 * ordered actions); save switched off, enable, disable and archive; a
 * test panel and the rule's run log. `/settings/automation-rules/new`
 * creates; saving it moves to the rule's own address without leaving the
 * page, so a webhook secret returned once stays on screen until the page
 * is left or reloaded.
 */
export default function RuleEditor() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { ruleId } = useParams()
  const isNew = ruleId === NEW
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = ['test', 'runs'].includes(searchParams.get('tab')) ? searchParams.get('tab') : 'rule'

  const catalogue = useAutomationCatalogue()
  const { types } = catalogue
  const { companies } = useCompanies()
  const roles = useRoleOptions()
  const users = useUserOptions()
  const ruleQuery = useQuery({ queryKey: ['automation-rules', ruleId], queryFn: () => api.get(`automation-rules/${ruleId}`), enabled: !isNew })
  const rule = isNew ? null : ruleQuery.data?.data

  // The editor's copy: `id` names what it was loaded from ("new" or a rule id), `base` the last saved body.
  const [state, setState] = useState(null)
  const [secret, setSecret] = useState(null)
  const [failure, setFailure] = useState(null)
  const [confirmArchive, setConfirmArchive] = useState(false)
  const rights = useAutomationRights(state?.draft.company_id ?? null)

  if (isNew && state?.id !== NEW && types.length > 0) {
    const info = types[0]
    const draft = { name: '', document_type: info.key, company_id: rights.canEditAll ? null : (companies[0]?.id ?? null), trigger: newTrigger(info.triggers?.[0] ?? 'record_created', info), conditions: null, actions: [] }
    setState({ id: NEW, draft, base: null })
    setSecret(null)
    setFailure(null)
  } else if (!isNew && rule && state?.id !== ruleId) {
    const draft = fromRule(rule)
    setState({ id: ruleId, draft, base: signature(draft) })
    setSecret(null)
    setFailure(null)
  }

  const draft = state?.draft
  const info = types.find((one) => one.key === draft?.document_type)
  const { stages } = useFlowStages(draft?.document_type)
  const timeZone = useTimeZone(draft?.company_id ?? undefined)
  const status = isNew ? 'new' : (rule?.status ?? 'disabled')
  const readOnly = !rights.canEdit || status === 'archived'
  const dirty = Boolean(draft) && (isNew || signature(draft) !== state.base)
  const errors = sortErrors(failure)

  const setDraft = (changes) => setState((current) => ({ ...current, draft: { ...current.draft, ...changes } }))

  /** Keeps the cached rule and the lists current after a change; returns the rule. */
  const remember = async (response) => {
    const saved = response.data
    if (saved.webhook_secret) setSecret(saved.webhook_secret)
    const { webhook_secret: _secret, ...plain } = saved
    queryClient.setQueryData(['automation-rules', saved.id], { data: plain })
    await queryClient.invalidateQueries({ queryKey: ['automation-rules'], predicate: (query) => query.queryKey[1] !== saved.id })
    return plain
  }

  /** Saves the editor's rule (switched off when new); returns the saved rule. */
  const persist = async () => {
    const body = ruleBody(draft)
    const response = isNew ? await api.post('automation-rules', body) : await api.patch(`automation-rules/${ruleId}`, body)
    const saved = await remember(response)
    const next = fromRule(saved)
    setState({ id: saved.id, draft: next, base: signature(next) })
    if (isNew) navigate(`/settings/automation-rules/${saved.id}${tab === 'rule' ? '' : `?tab=${tab}`}`, { replace: true })
    return saved
  }

  const onError = (error) => setFailure(error)
  const save = useMutation({
    mutationFn: persist,
    onMutate: () => setFailure(null),
    onSuccess: () => toast.success(t('automation.editor.saved')),
    onError,
  })
  const enable = useMutation({
    mutationFn: async () => {
      const saved = dirty ? await persist() : rule
      return remember(await api.post(`automation-rules/${saved.id}/enable`))
    },
    onMutate: () => setFailure(null),
    onSuccess: () => toast.success(t('automation.editor.enabled')),
    onError,
  })
  const disable = useMutation({
    mutationFn: async () => remember(await api.post(`automation-rules/${ruleId}/disable`)),
    onMutate: () => setFailure(null),
    onSuccess: () => toast.success(t('automation.editor.disabled')),
    onError,
  })
  const archive = useMutation({
    mutationFn: async () => remember(await api.post(`automation-rules/${ruleId}/archive`)),
    onMutate: () => setFailure(null),
    onSuccess: () => {
      setConfirmArchive(false)
      toast.success(t('automation.editor.archived'))
    },
    onError,
  })
  const rotate = useMutation({ mutationFn: async () => remember(await api.post(`automation-rules/${ruleId}/webhook-secret/rotate`)) })

  if (!isNew && ruleQuery.isError) {
    return (
      <>
        <PageHeader title={t('automation.title')} />
        <Alert tone="danger" title={errorMessage(ruleQuery.error)} />
      </>
    )
  }
  if (!draft) return <p className="text-ink-muted">{t('common.loading')}</p>

  const withDocument = hasDocument(draft.trigger)
  const companyOptions = [
    ...(rights.canEditAll || draft.company_id === null ? [{ value: ALL, label: t('automation.allCompanies') }] : []),
    ...companies.map((company) => ({ value: company.id, label: company.name })),
  ]
  const busy = save.isPending || enable.isPending || disable.isPending || archive.isPending
  const formProblem = failure ? (errors.other.length ? errors.other.join(' ') : failure.status === 422 ? t('automation.editor.fixBelow') : errorMessage(failure)) : null
  const context = {
    types,
    roles,
    users,
    placeholders: catalogue.placeholders,
    limits: catalogue.limits,
    actionsMeta: catalogue.actionsMeta,
    webhook: {
      secret,
      hasSecret: Boolean(rule?.has_webhook_secret),
      saved: !isNew,
      canRotate: !readOnly,
      rotating: rotate.isPending,
      rotateError: rotate.isError ? errorMessage(rotate.error) : null,
      onRotate: async () => {
        try {
          await rotate.mutateAsync()
          toast.success(t('automation.secret.rotated'))
          return true
        } catch {
          return false
        }
      },
    },
  }

  const actions = readOnly ? null : status === 'enabled' ? (
    <>
      <Button loading={disable.isPending} disabled={busy} onClick={() => disable.mutate()}>
        {t('automation.editor.disable')}
      </Button>
      <Button variant="primary" loading={save.isPending} disabled={busy || !dirty} onClick={() => save.mutate()}>
        {t('common.save')}
      </Button>
    </>
  ) : (
    <>
      <Button loading={save.isPending} disabled={busy || !dirty} onClick={() => save.mutate()}>
        {isNew ? t('automation.editor.saveOff') : t('common.save')}
      </Button>
      <Button variant="pay" loading={enable.isPending} disabled={busy} onClick={() => enable.mutate()}>
        {t('automation.editor.enable')}
      </Button>
    </>
  )

  return (
    <>
      <PageHeader eyebrow={t('automation.title')} title={isNew ? t('automation.editor.newTitle') : draft.name || rule?.name} actions={actions} />
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        {isNew ? <span className="text-caption text-ink-muted">{t('automation.editor.notSaved')}</span> : <RuleStatus status={status} />}
        {rule?.version ? <span className="text-caption text-ink-muted">{t('automation.runs.versionValue', { version: rule.version })}</span> : null}
        {dirty && !isNew ? <span className="text-caption text-ink-muted">{t('automation.editor.unsaved')}</span> : null}
      </div>
      <div className="flex flex-col gap-1 rounded-md border border-border bg-surface-200 px-4 py-3">
        <span className="text-caption text-ink-muted">{t('automation.editor.summaryLabel')}</span>
        <p data-testid="rule-summary" className="text-body text-ink">
          {summarizeRule(t, draft, { info, stages, locale })}
        </p>
      </div>
      {status === 'archived' ? <Alert tone="info" title={t('automation.editor.archivedNote')} /> : null}
      {!rights.canEdit && status !== 'archived' ? <Alert tone="info" title={t('automation.editor.readOnly')} /> : null}
      {formProblem ? <Alert tone="danger" title={formProblem} /> : null}

      <Tabs
        items={[
          { value: 'rule', label: t('automation.editor.tabs.rule') },
          { value: 'test', label: t('automation.editor.tabs.test') },
          ...(isNew ? [] : [{ value: 'runs', label: t('automation.editor.tabs.runs') }]),
        ]}
        value={tab}
        onChange={(next) => setSearchParams(next === 'rule' ? {} : { tab: next })}
      />

      {tab === 'rule' ? (
        <fieldset disabled={readOnly} className="flex min-w-0 flex-col gap-6">
          <legend className="sr-only">{t('automation.editor.legend')}</legend>
          <Card title={t('automation.editor.details')}>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <TextField label={t('automation.fields.name')} value={draft.name} maxLength={120} required error={errors.fields.name} onChange={(event) => setDraft({ name: event.target.value })} />
              <Select
                label={t('automation.fields.documentType')}
                options={types.map((one) => ({ value: one.key, label: one.label }))}
                value={draft.document_type}
                disabled={!isNew}
                error={errors.fields.document_type}
                help={isNew ? undefined : t('automation.fields.documentTypeFixed')}
                onChange={(event) => {
                  const next = types.find((one) => one.key === event.target.value)
                  setDraft({ document_type: event.target.value, trigger: newTrigger(next?.triggers?.[0] ?? 'record_created', next), conditions: null, actions: [] })
                }}
              />
              <Select
                label={t('automation.fields.company')}
                options={companyOptions}
                value={draft.company_id ?? ALL}
                error={errors.fields.company_id}
                onChange={(event) => setDraft({ company_id: event.target.value === ALL ? null : event.target.value })}
              />
            </div>
          </Card>

          <Card title={t('automation.editor.when')} subtitle={t('automation.editor.whenHelp')}>
            <div className="flex flex-col gap-3" data-path="trigger">
              <Messages messages={errors.trigger} />
              <TriggerEditor trigger={draft.trigger} info={info} stages={stages} limits={catalogue.limits} onChange={(trigger) => setDraft({ trigger })} />
            </div>
          </Card>

          <Card title={t('automation.editor.onlyIf')} subtitle={t('automation.editor.onlyIfHelp')}>
            <div className="flex flex-col gap-3" data-path="conditions">
              <Messages messages={errors.conditions} />
              {withDocument ? (
                <ConditionEditor label={t('automation.editor.conditions')} value={draft.conditions} fields={info?.fields ?? []} onChange={(conditions) => setDraft({ conditions })} />
              ) : (
                <p className="text-caption text-ink-muted">{t('automation.editor.noConditionsOnSchedule')}</p>
              )}
            </div>
          </Card>

          <Card title={t('automation.editor.then')} subtitle={t('automation.editor.thenHelp')}>
            <ActionsEditor
              actions={draft.actions}
              onChange={(next) => setDraft({ actions: next })}
              info={info}
              stages={stages}
              withDocument={withDocument}
              context={context}
              errors={errors.actions}
              sectionError={errors.actionsSection.join(' ') || null}
            />
          </Card>

          {!isNew && !readOnly ? (
            <section aria-labelledby="archive-rule" className="flex flex-col gap-3 border-t border-border pt-6">
              <h2 id="archive-rule" className="text-h3 text-ink">
                {t('automation.editor.archiveTitle')}
              </h2>
              <p className="text-body text-ink-muted">{t('automation.editor.archiveBody')}</p>
              {confirmArchive ? (
                <div role="group" aria-label={t('automation.editor.archiveConfirmTitle')} className="flex flex-col gap-3 rounded-md border border-border-strong p-4">
                  <p className="text-body text-ink">{t('automation.editor.archiveConfirm', { name: rule?.name ?? draft.name })}</p>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="ghost" onClick={() => setConfirmArchive(false)}>
                      {t('common.cancel')}
                    </Button>
                    <Button variant="danger" icon="archive" loading={archive.isPending} disabled={busy} onClick={() => archive.mutate()}>
                      {t('automation.editor.archiveNow')}
                    </Button>
                  </div>
                </div>
              ) : (
                <Button variant="danger" icon="archive" className="self-start" disabled={busy} onClick={() => setConfirmArchive(true)}>
                  {t('automation.editor.archive')}
                </Button>
              )}
            </section>
          ) : null}
        </fieldset>
      ) : null}

      {tab === 'test' ? (
        <Card title={t('automation.test.title')}>
          <TestPanel draft={draft} info={info} ruleId={isNew ? null : ruleId} dirty={dirty} timeZone={timeZone} />
        </Card>
      ) : null}

      {tab === 'runs' && !isNew ? <RunsList ruleId={ruleId} /> : null}
    </>
  )
}
