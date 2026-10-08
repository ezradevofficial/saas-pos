import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, Select, StatusBadge } from '@/components/ds'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { ValueInput } from './ConditionEditor'
import { displayName } from './describe'

const VERSION_TONES = { draft: 'info', published: 'success', archived: 'neutral' }

/**
 * Publish the draft (APR-09): new documents follow it; documents already
 * running stay on the version they started with. The near-black action is
 * the page's one decisive step.
 */
export function PublishDialog({ open, draftVersion, live, onConfirm, onClose, pending, error }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={open}
      title={t('workflows.publish.title', { version: draftVersion })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('workflows.publish.keepEditing')}
          </Button>
          <Button variant="pay" loading={pending} onClick={onConfirm}>
            {t('workflows.publish.confirm', { version: draftVersion })}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {error ? <Alert tone="danger" title={error} /> : null}
        <p>{t('workflows.publish.body', { version: draftVersion })}</p>
        {live ? (
          <p>
            {live.in_progress > 0
              ? t('workflows.publish.inProgress', { count: live.in_progress, version: live.version })
              : t('workflows.publish.noneInProgress', { version: live.version })}
          </p>
        ) : null}
      </div>
    </Dialog>
  )
}

/** Versions of the flow (APR-09) with roll back to an earlier one. */
export function VersionsDialog({ open, workflowId, canPublish, onClose, onRolledBack }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(null)
  const versions = useQuery({ queryKey: ['workflows', workflowId, 'versions'], queryFn: () => api.get(`workflows/${workflowId}/versions`), enabled: open })
  const rollback = useMutation({
    mutationFn: (version) => api.post(`workflows/${workflowId}/rollback`, { version }),
    onSuccess: async () => {
      setConfirming(null)
      await queryClient.invalidateQueries({ queryKey: ['workflows'] })
      onRolledBack?.()
    },
  })
  const rows = versions.data?.data ?? []

  return (
    <Dialog open={open} size="lg" title={t('workflows.versions.title')} onClose={onClose}>
      <div className="flex flex-col gap-3">
        {versions.isError ? <Alert tone="danger" title={errorMessage(versions.error)} /> : null}
        {rollback.isError ? <Alert tone="danger" title={errorMessage(rollback.error)} /> : null}
        {versions.isPending ? <p>{t('common.loading')}</p> : null}
        <ul className="flex flex-col divide-y divide-border">
          {rows.map((version) => (
            <li key={version.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="flex min-w-0 flex-col gap-1">
                <span className="flex items-center gap-3 text-ink">
                  <span className="font-medium">{t('workflows.versions.number', { version: version.version })}</span>
                  <StatusBadge tone={VERSION_TONES[version.status] ?? 'neutral'}>{t(`workflows.versions.status.${version.status}`, { defaultValue: version.status })}</StatusBadge>
                </span>
                <span className="text-caption text-ink-muted">
                  {[
                    t(`workflows.versions.source.${version.source}`, { defaultValue: version.source ?? '' }),
                    version.published_at ? t('workflows.versions.publishedAt', { when: formatWhen(version.published_at, locale, timeZone) }) : null,
                    version.in_progress ? t('workflows.versions.inProgress', { count: version.in_progress }) : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
              </div>
              {canPublish && version.status === 'archived' ? (
                confirming === version.version ? (
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-caption text-ink">{t('workflows.versions.confirmText', { version: version.version })}</span>
                    <Button variant="ghost" onClick={() => setConfirming(null)}>
                      {t('common.cancel')}
                    </Button>
                    <Button variant="primary" loading={rollback.isPending} onClick={() => rollback.mutate(version.version)}>
                      {t('workflows.versions.rollBackTo', { version: version.version })}
                    </Button>
                  </span>
                ) : (
                  <Button icon="restore" onClick={() => setConfirming(version.version)}>
                    {t('workflows.versions.rollBackTo', { version: version.version })}
                  </Button>
                )
              ) : null}
            </li>
          ))}
        </ul>
      </div>
    </Dialog>
  )
}

/** Copy this flow into another company (spec 6.4): it becomes that company's draft. */
export function CopyDialog({ open, workflow, companies, canCopyToAll, onClose, onCopied }) {
  const { t } = useTranslation()
  const options = [
    ...(canCopyToAll && workflow.company_id ? [{ value: 'all', label: t('workflows.allCompanies') }] : []),
    ...companies.filter((company) => company.id !== workflow.company_id).map((company) => ({ value: company.id, label: company.name })),
  ]
  const [target, setTarget] = useState('')
  const [from, setFrom] = useState(workflow.published ? 'published' : 'draft')
  const copy = useMutation({
    mutationFn: () => api.post(`workflows/${workflow.id}/copy`, { company_id: target === 'all' ? null : target, from }),
    onSuccess: (response) => onCopied(response?.data),
  })

  return (
    <Dialog
      open={open}
      title={t('workflows.copy.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" disabled={!target} loading={copy.isPending} onClick={() => copy.mutate()}>
            {t('workflows.copy.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {copy.isError ? <Alert tone="danger" title={errorMessage(copy.error)} /> : null}
        <p>{t('workflows.copy.body')}</p>
        <Select label={t('workflows.copy.company')} options={options} placeholder={t('workflows.copy.chooseCompany')} value={target} onChange={(event) => setTarget(event.target.value)} />
        <Select
          label={t('workflows.copy.from')}
          options={[
            ...(workflow.published ? [{ value: 'published', label: t('workflows.copy.fromLive', { version: workflow.published.version }) }] : []),
            ...(workflow.draft ? [{ value: 'draft', label: t('workflows.copy.fromDraft', { version: workflow.draft.version }) }] : []),
          ]}
          value={from}
          onChange={(event) => setFrom(event.target.value)}
        />
      </div>
    </Dialog>
  )
}

/** The sample values sent to the dry run: filled fields only, in the API's value shapes. */
function sampleValues(fields, values) {
  const result = {}
  for (const field of fields) {
    const value = values[field.name]
    if (value === undefined || value === '' || value === null) continue
    if (field.type === 'money' && (!value.amount_minor || value.amount_minor === '')) continue
    result[field.name] = value
  }
  return result
}

/**
 * Test with a sample (spec 6.4): field values and, for each approval, the
 * decision to assume; the dry run walks the current canvas and answers
 * the path taken and why. Nothing is saved or sent.
 */
export function TestDialog({ open, workflowId, graph, fields, onClose, onResult, result }) {
  const { t } = useTranslation()
  const [values, setValues] = useState({})
  const [outcomes, setOutcomes] = useState({})
  const approvals = graph.nodes.filter((node) => node.type === 'approval')
  const run = useMutation({
    mutationFn: () => api.post(`workflows/${workflowId}/test`, { values: sampleValues(fields, values), outcomes, graph }),
    onSuccess: (response) => onResult(response?.data ?? null),
  })
  const names = new Map(graph.nodes.map((node) => [node.id, displayName(t, node)]))

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('workflows.test.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {result ? t('workflows.test.showOnCanvas') : t('common.cancel')}
          </Button>
          <Button variant="primary" loading={run.isPending} onClick={() => run.mutate()}>
            {t('workflows.test.run')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('workflows.test.body')}</p>
        {run.isError ? <Alert tone="danger" title={errorMessage(run.error)} /> : null}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {fields.map((field) => (
            <ValueInput
              key={field.name}
              field={field}
              op="eq"
              label={field.label}
              value={values[field.name] ?? (field.type === 'boolean' ? false : field.type === 'enum' ? '' : undefined)}
              onChange={(next) => setValues((current) => ({ ...current, [field.name]: next }))}
            />
          ))}
        </div>
        {approvals.length > 0 ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {approvals.map((node) => (
              <Select
                key={node.id}
                label={t('workflows.test.decision', { name: displayName(t, node) })}
                options={['approved', 'rejected'].map((value) => ({ value, label: t(`workflows.branches.${value}`) }))}
                value={outcomes[node.id] ?? 'approved'}
                onChange={(event) => setOutcomes((current) => ({ ...current, [node.id]: event.target.value }))}
              />
            ))}
          </div>
        ) : null}

        {result ? (
          <section aria-label={t('workflows.test.resultTitle')} className="flex flex-col gap-3 border-t border-border pt-4">
            <h3 className="text-h3 text-ink">{t('workflows.test.resultTitle')}</h3>
            {!result.valid ? (
              <Alert tone="warning" title={t('workflows.test.invalid')}>
                <ul className="list-disc pl-5">
                  {result.problems.map((problem, index) => (
                    <li key={index}>{problem.message}</li>
                  ))}
                </ul>
              </Alert>
            ) : result.blocked ? (
              <Alert tone="warning" title={t('workflows.test.blocked', { name: result.blocked.name ?? names.get(result.blocked.node_id) })}>
                {(result.blocked.reasons ?? []).join(' ')}
              </Alert>
            ) : (
              <Alert tone="success" title={t('workflows.test.ends', { outcome: t(`workflows.outcomes.${result.outcome}`, { defaultValue: result.outcome ?? '' }) })} />
            )}
            {result.valid ? (
              <ol className="flex flex-col gap-2">
                {result.path.map((step, index) => (
                  <li key={`${step.node_id}-${index}`} className="flex flex-col gap-1 text-ink">
                    <span className="flex flex-wrap items-center gap-3">
                      <span className="font-medium">{step.name || names.get(step.node_id)}</span>
                      <StatusBadge tone={step.result === 'blocked' ? 'warning' : step.result === 'rejected' ? 'danger' : 'success'}>
                        {t(`workflows.test.results.${step.result}`, { defaultValue: step.result })}
                      </StatusBadge>
                    </span>
                    {(step.reasons ?? []).map((reason, i) => (
                      <span key={i} className="text-caption text-ink-muted">
                        {reason}
                      </span>
                    ))}
                  </li>
                ))}
              </ol>
            ) : null}
          </section>
        ) : null}
      </div>
    </Dialog>
  )
}
