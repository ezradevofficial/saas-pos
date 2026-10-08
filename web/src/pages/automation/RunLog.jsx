import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Icon, ListView, Select, StatusBadge } from '@/components/ds'
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { ACTION_RESULT_TONES, DELIVERY_TONES, OUTCOME_TONES, RUN_OUTCOMES } from './automationData'

/** An outcome as a dot and a word. */
export function Outcome({ outcome }) {
  const { t } = useTranslation()
  return <StatusBadge tone={OUTCOME_TONES[outcome] ?? 'neutral'}>{t(`automation.outcomes.${outcome}`, { defaultValue: outcome })}</StatusBadge>
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
  const timeZone = useTimeZone()
  const query = useQuery({ queryKey: ['automation-runs', runId], queryFn: () => api.get(`automation-runs/${runId}`), enabled: Boolean(runId) })
  const run = query.data?.data
  const when = (value) => (value ? formatWhen(value, locale, timeZone) : '—')

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
                <Detail label={t('automation.runs.columns.document')}>{run.document_id ? <span className="font-mono text-caption break-all">{run.document_id}</span> : t('automation.runs.noDocument')}</Detail>
                <Detail label={t('automation.runs.started')}>{when(run.started_at ?? run.created_at)}</Detail>
                <Detail label={t('automation.runs.finished')}>{when(run.finished_at)}</Detail>
                {run.next_attempt_at ? <Detail label={t('automation.runs.nextAttempt')}>{when(run.next_attempt_at)}</Detail> : null}
                {run.conditions ? <Detail label={t('automation.runs.conditions')}>{run.conditions.passed ? t('automation.test.passed') : t('automation.test.failed')}</Detail> : null}
                {run.depth ? <Detail label={t('automation.runs.depth')}>{run.depth}</Detail> : null}
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
  const timeZone = useTimeZone()
  const [searchParams, setSearchParams] = useSearchParams()
  const open = searchParams.get('run')

  const columns = [
    { key: 'created_at', label: t('automation.runs.columns.time'), sortKey: 'created_at', hideable: false, render: (row) => <span className="tabular-nums">{formatWhen(row.created_at, locale, timeZone)}</span> },
    ...(ruleId ? [] : [{ key: 'rule', label: t('automation.runs.columns.rule'), render: (row) => <span className="font-medium text-ink">{row.rule_name}</span> }]),
    { key: 'trigger', label: t('automation.runs.columns.trigger'), sortKey: 'trigger_type', render: (row) => t(`automation.triggers.${row.trigger_type}`, { defaultValue: row.trigger_type }) },
    {
      key: 'document_id',
      label: t('automation.runs.columns.document'),
      render: (row) => (row.document_id ? <span className="font-mono text-caption">{row.document_id.slice(0, 8)}</span> : <span className="text-ink-muted">{t('automation.runs.noDocument')}</span>),
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
        filters={
          <>
            <Select
              label={t('automation.runs.filters.outcome')}
              options={[{ value: '', label: t('automation.runs.filters.allOutcomes') }, ...RUN_OUTCOMES.map((outcome) => ({ value: outcome, label: t(`automation.outcomes.${outcome}`) }))]}
              value={list.filters.outcome}
              onChange={(event) => list.setFilter('outcome', event.target.value)}
              className="w-full sm:w-palette"
            />
            {ruleId ? null : (
              <Select
                label={t('automation.runs.filters.rule')}
                options={[{ value: '', label: t('automation.runs.filters.allRules') }, ...rules.map((rule) => ({ value: rule.id, label: rule.name }))]}
                value={list.filters.rule}
                onChange={(event) => list.setFilter('rule', event.target.value)}
                className="w-full sm:w-palette"
              />
            )}
          </>
        }
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
