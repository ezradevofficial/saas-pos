import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Icon, ListView, StatusBadge } from '@/components/ds'
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { useCompanies } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { ACTION_RESULT_TONES, DELIVERY_TONES, OUTCOME_TONES, RUN_OUTCOMES } from './automationData'

/** An outcome as a dot and a word. */
export function Outcome({ outcome }) {
  const { t } = useTranslation()
  return <StatusBadge tone={OUTCOME_TONES[outcome] ?? 'neutral'}>{t(`automation.outcomes.${outcome}`, { defaultValue: outcome })}</StatusBadge>
}

/**
 * What started a run (AUTO-06): another rule only when its chain holds one
 * (`caused_by_rule`); otherwise what the trigger reacts to. Every run is at
 * least level 1, so the level alone never means another rule started it.
 */
function runCause(run, t) {
  if (run.caused_by_rule) return t('automation.runs.cause.rule', { level: run.depth })
  if (run.trigger_type === 'schedule') return t('automation.runs.cause.schedule')
  if (run.trigger_type === 'date') return t('automation.runs.cause.date')
  if (run.trigger_type === 'stage_entered' || run.trigger_type === 'stage_left') return t('automation.runs.cause.workflow')
  return t('automation.runs.cause.change')
}

/**
 * A run's document (AUTO-05): its number, else its title (the API leaves
 * out a title hidden from the reader), else its short id; a link when the
 * API gives one (a relative app path only).
 */
function DocumentName({ run }) {
  const { t } = useTranslation()
  if (!run.document_id) return <span className="text-ink-muted">{t('automation.runs.noDocument')}</span>
  const name = run.document?.number ?? run.document?.title
  const text = name ? <span>{name}</span> : <span className="font-mono text-caption">{run.document_id.slice(0, 8)}</span>
  const link = run.document?.link
  if (!link?.startsWith('/') || link.startsWith('//')) return text
  return (
    <Link to={link} onClick={(event) => event.stopPropagation()} className="text-primary hover:text-primary-hover">
      {text}
    </Link>
  )
}

function Detail({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/** One run (AUTO-05): what started it, its outcome, attempts and each action's result with the safe error text. */
function RunDrawer({ runId, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { companies } = useCompanies()
  const query = useQuery({ queryKey: ['automation-runs', runId], queryFn: () => api.get(`automation-runs/${runId}`), enabled: Boolean(runId) })
  const run = query.data?.data
  // Times in the run's company zone, like the approvals inbox (lib/companyTime).
  const when = (value) => (value ? formatCompanyTime(value, locale, companies.find((company) => company.id === run?.company_id)) : '—')

  return (
    <Sheet open={Boolean(runId)} onOpenChange={(open) => (open ? undefined : onClose())}>
      <SheetContent side="right" showCloseButton={false} className="w-full gap-0 overflow-y-auto border-border bg-surface-200 p-0 text-body text-ink sm:max-w-md">
        <header className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div className="flex min-w-0 flex-col gap-1">
            <SheetTitle className="text-h2 text-ink">{t('automation.runs.detailTitle')}</SheetTitle>
            <SheetDescription className="text-caption text-ink-muted">{run?.rule_name ?? ''}</SheetDescription>
          </div>
          <SheetClose asChild>
            <Button variant="ghost" aria-label={t('ds.dialog.close')} className="size-icon-btn shrink-0 px-0">
              <Icon name="x" size={18} />
            </Button>
          </SheetClose>
        </header>
        <div className="flex flex-col gap-5 px-5 py-4">
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
          {run ? (
            <>
              <dl className="grid grid-cols-2 gap-4">
                <Detail label={t('automation.runs.columns.outcome')}>
                  <Outcome outcome={run.outcome} />
                </Detail>
                <Detail label={t('automation.runs.columns.attempts')}>
                  <span className="tabular-nums">{run.attempts}</span>
                </Detail>
                <Detail label={t('automation.runs.columns.trigger')}>{t(`automation.triggers.${run.trigger_type}`, { defaultValue: run.trigger_type })}</Detail>
                <Detail label={t('automation.runs.version')}>{t('automation.runs.versionValue', { version: run.rule_version })}</Detail>
                <Detail label={t('automation.runs.columns.document')}><DocumentName run={run} /></Detail>
                <Detail label={t('automation.runs.started')}>{when(run.started_at ?? run.created_at)}</Detail>
                <Detail label={t('automation.runs.finished')}>{when(run.finished_at)}</Detail>
                {run.next_attempt_at ? <Detail label={t('automation.runs.nextAttempt')}>{when(run.next_attempt_at)}</Detail> : null}
                {run.conditions ? <Detail label={t('automation.runs.conditions')}>{run.conditions.passed ? t('automation.test.passed') : t('automation.test.failed')}</Detail> : null}
                <Detail label={t('automation.runs.startedBy')}>{runCause(run, t)}</Detail>
              </dl>
              {run.error ? <Alert tone="danger" title={run.error} /> : null}
              <section aria-labelledby="run-actions" className="flex flex-col gap-2">
                <h3 id="run-actions" className="text-label text-ink">
                  {t('automation.runs.actions')}
                </h3>
                {run.actions.length === 0 ? (
                  <p className="text-ink-muted">{t('automation.runs.noActions')}</p>
                ) : (
                  <ol className="flex flex-col gap-2">
                    {run.actions.map((result, index) => (
                      <li key={index} className="flex flex-col gap-1 rounded-md border border-border p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="text-body text-ink">
                            <span className="text-ink-muted tabular-nums">{index + 1}.</span> {t(`automation.actionNames.${result.type}`, { defaultValue: result.type })}
                          </span>
                          <StatusBadge tone={ACTION_RESULT_TONES[result.status] ?? 'neutral'}>{t(`automation.actionResults.${result.status}`, { defaultValue: result.status })}</StatusBadge>
                        </div>
                        {result.error ? <p className="text-caption text-danger">{result.error}</p> : null}
                        {(run.deliveries ?? [])
                          .filter((delivery) => delivery.action_index === index)
                          .map((delivery) => (
                            <div key={delivery.id} className="flex flex-col gap-1 border-t border-border pt-2">
                              <StatusBadge tone={DELIVERY_TONES[delivery.status] ?? 'neutral'}>{t(`automation.deliveryStatus.${delivery.status}`, { defaultValue: delivery.status })}</StatusBadge>
                              <p className="text-caption text-ink-muted">
                                {t('automation.runs.delivery', { url: delivery.url_display, status: delivery.response_status ?? '—', count: delivery.attempts })}
                              </p>
                              {delivery.error ? <p className="text-caption text-danger">{delivery.error}</p> : null}
                            </div>
                          ))}
                      </li>
                    ))}
                  </ol>
                )}
              </section>
            </>
          ) : null}
        </div>
      </SheetContent>
    </Sheet>
  )
}

/**
 * The run log (AUTO-05) as a list: every rule's runs, or one rule's (the
 * rule editor's Runs tab). A row opens the run's detail in a drawer.
 */
export function RunsList({ ruleId, rules = [] }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { companies } = useCompanies()
  const [searchParams, setSearchParams] = useSearchParams()
  const open = searchParams.get('run')

  const columns = [
    { key: 'created_at', label: t('automation.runs.columns.time'), sortKey: 'created_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, companies.find((company) => company.id === row.company_id))}</span> },
    ...(ruleId ? [] : [{ key: 'rule', label: t('automation.runs.columns.rule'), render: (row) => <span className="font-medium text-ink">{row.rule_name}</span> }]),
    { key: 'trigger', label: t('automation.runs.columns.trigger'), sortKey: 'trigger_type', render: (row) => t(`automation.triggers.${row.trigger_type}`, { defaultValue: row.trigger_type }) },
    {
      key: 'document_id',
      label: t('automation.runs.columns.document'),
      render: (row) => <DocumentName run={row} />,
    },
    { key: 'outcome', label: t('automation.runs.columns.outcome'), sortKey: 'outcome', render: (row) => <Outcome outcome={row.outcome} /> },
    { key: 'attempts', label: t('automation.runs.columns.attempts'), align: 'end', numeric: true, render: (row) => row.attempts },
  ]
  const list = useServerList({
    id: ruleId ? 'automation-rule-runs' : 'automation-runs',
    endpoint: 'automation-runs',
    queryKey: ['automation-runs'],
    params: ruleId ? { rule: ruleId } : {},
    filters: ruleId ? { outcome: '' } : { outcome: '', rule: '' },
    columns,
  })
  const setOpen = (id) => {
    const next = new URLSearchParams(searchParams)
    if (id) next.set('run', id)
    else next.delete('run')
    setSearchParams(next)
  }

  return (
    <>
      <ListView
        list={list}
        title={t('automation.runs.title')}
        searchable={false}
        filterFields={[
          {
            name: 'outcome',
            label: t('automation.runs.filters.outcome'),
            options: [{ value: '', label: t('automation.runs.filters.allOutcomes') }, ...RUN_OUTCOMES.map((outcome) => ({ value: outcome, label: t(`automation.outcomes.${outcome}`) }))],
          },
          ...(ruleId
            ? []
            : [
                {
                  name: 'rule',
                  label: t('automation.runs.filters.rule'),
                  options: [{ value: '', label: t('automation.runs.filters.allRules') }, ...rules.map((rule) => ({ value: rule.id, label: rule.name }))],
                },
              ]),
        ]}
        emptyText={t('automation.runs.empty')}
        onRowClick={(row) => setOpen(row.id)}
        selectedId={open}
      />
      <RunDrawer runId={open} onClose={() => setOpen(null)} />
    </>
  )
}

/** The run log page (AUTO-05), for every rule the user may see. */
export default function AutomationRuns() {
  const { t } = useTranslation()
  const rules = useQuery({ queryKey: ['automation-rules', 'options'], queryFn: () => api.get('automation-rules?status=all&per_page=100&sort=name') })
  return (
    <>
      <PageHeader title={t('automation.runs.title')} description={t('automation.runs.description')} />
      <RunsList rules={rules.data?.data ?? []} />
    </>
  )
}
