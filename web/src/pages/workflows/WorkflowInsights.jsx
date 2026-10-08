import { useQuery } from '@tanstack/react-query'
import { useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, FilterChips, FilterDrawer, StatusBadge, TextField } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { NoAccess } from '@/pages/NotFound'
import { useDuration } from './useDuration'

const DEFAULTS = { type: '', company: '', from: '', to: '' }

/**
 * The filters in the URL, shaped like a useServerList list so the shared
 * filter drawer and chips (ListFilters) can draw them.
 */
function useUrlFilters() {
  const [params, setParams] = useSearchParams()
  const filters = Object.fromEntries(Object.keys(DEFAULTS).map((name) => [name, params.get(name) ?? DEFAULTS[name]]))
  const setFilters = (changes) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        for (const [name, value] of Object.entries(changes)) {
          if (value) next.set(name, value)
          else next.delete(name)
        }
        return next
      },
      { replace: true },
    )
  return { filters, filterDefaults: DEFAULTS, setFilters, setFilter: (name, value) => setFilters({ [name]: value }) }
}

function DateFilter({ value, onChange, label }) {
  return <TextField type="date" label={label} value={value} onChange={(event) => onChange(event.target.value)} className="w-full" />
}

/** One live flow: its stages in flow order with volumes and times; the slowest stage is marked. */
function FlowTable({ flow }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const duration = useDuration()
  const count = (value) => formatInteger(value ?? 0, locale)
  const time = (seconds) => (seconds == null ? '—' : duration(seconds))
  const heading = `${flow.document_type_label} · ${flow.company_name ?? t('workflows.allCompanies')}`
  const id = `insights-${flow.workflow_id}`

  const columns = [
    {
      key: 'name',
      label: t('workflowInsights.columns.stage'),
      wrap: true,
      render: (stage) => (
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span className="text-ink">{stage.name}</span>
          {stage.kind === 'approval' ? <span className="text-caption text-ink-muted">{t('workflowInsights.approval')}</span> : null}
          {stage.slowest ? <StatusBadge tone="warning">{t('workflowInsights.slowest')}</StatusBadge> : null}
        </span>
      ),
    },
    { key: 'now', label: t('workflowInsights.columns.now'), align: 'end', numeric: true, render: (stage) => count(stage.now) },
    { key: 'entered', label: t('workflowInsights.columns.entered'), align: 'end', numeric: true, render: (stage) => count(stage.entered) },
    { key: 'left', label: t('workflowInsights.columns.left'), align: 'end', numeric: true, render: (stage) => count(stage.left) },
    { key: 'median', label: t('workflowInsights.columns.median'), align: 'end', numeric: true, render: (stage) => time(stage.median_seconds) },
    { key: 'p90', label: t('workflowInsights.columns.p90'), align: 'end', numeric: true, render: (stage) => time(stage.p90_seconds) },
    {
      key: 'overdue',
      label: t('workflowInsights.columns.overdue'),
      align: 'end',
      numeric: true,
      render: (stage) => <span className={cn(stage.overdue > 0 && 'text-danger')}>{count(stage.overdue)}</span>,
    },
  ]

  return (
    <section aria-labelledby={id} className="flex min-w-0 flex-col gap-2">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h2 id={id} className="text-h3 text-ink">
          {heading}
        </h2>
        <span className="text-caption text-ink-muted">{t('workflows.header.live', { version: flow.version })}</span>
      </div>
      <DataTable
        caption={heading}
        columns={columns}
        rows={flow.stages.map((stage) => ({ ...stage, id: stage.node_id }))}
        emptyText={t('workflowInsights.noStages')}
        className="overflow-x-auto"
      />
    </section>
  )
}

/**
 * WF-10: volumes and bottlenecks per stage of each live workflow: how many
 * documents are at each stage now, entered and left in the period, the
 * median and slowest 10% time in stage (elapsed time) and how many are
 * overdue; the slowest stage of each flow is marked. Filters (type,
 * company, period) live in the URL and the shared filter drawer. The API
 * counts only documents the viewer may see and decides who may open it.
 */
export default function WorkflowInsights() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { companies } = useCompanies()
  const list = useUrlFilters()
  const filterButton = useRef(null)
  const { filters } = list

  const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value))
  const query = useQuery({
    queryKey: ['workflow-insights', params.toString()],
    queryFn: () => api.get(`workflow-insights${params.size ? `?${params}` : ''}`),
    placeholderData: (previous) => previous,
    retry: false,
  })

  if (query.isError && query.error?.status === 403) return <NoAccess />

  const meta = query.data?.meta ?? {}
  const flows = query.data?.data ?? []
  const types = meta.types ?? []
  const fields = [
    { name: 'type', label: t('workflowInsights.filters.type'), options: [{ value: '', label: t('workflows.filters.allTypes') }, ...types.map((type) => ({ value: type.key, label: type.label }))] },
    ...(companies.length > 1
      ? [
          {
            name: 'company',
            label: t('workflowInsights.filters.company'),
            options: [{ value: '', label: t('workflows.allCompanies') }, ...companies.map((company) => ({ value: company.id, label: company.name }))],
          },
        ]
      : []),
    { name: 'from', label: t('workflowInsights.filters.from'), render: DateFilter, valueLabel: (value) => formatCalendarDate(value, locale) },
    { name: 'to', label: t('workflowInsights.filters.to'), render: DateFilter, valueLabel: (value) => formatCalendarDate(value, locale) },
  ]

  return (
    <>
      <PageHeader
        title={t('workflowInsights.title')}
        description={t('workflowInsights.description')}
        actions={
          <Link to="/settings/workflows" className="text-label text-primary hover:text-primary-hover">
            {t('workflowInsights.back')}
          </Link>
        }
      />
      <div className="flex flex-col gap-5">
        <div className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-body text-ink-muted">
              {meta.from
                ? t('workflowInsights.period', { from: formatCalendarDate(meta.from, locale), to: formatCalendarDate(meta.to, locale) })
                : null}
              {meta.timezone && meta.timezone !== 'UTC' ? ` · ${t('workflowInsights.zone', { zone: meta.timezone })}` : null}
            </p>
            <FilterDrawer list={list} fields={fields} triggerRef={filterButton} />
          </div>
          <FilterChips list={list} fields={fields} focusRef={filterButton} />
          <p className="text-caption text-ink-muted">{t('workflowInsights.basis')}</p>
        </div>

        {query.isError ? (
          <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} />
        ) : null}
        {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {query.isSuccess && flows.length === 0 ? <p className="text-ink-muted">{t('workflowInsights.empty')}</p> : null}
        <div className={cn('flex flex-col gap-6', query.isPlaceholderData && 'opacity-60')} aria-busy={query.isFetching ? 'true' : undefined}>
          {flows.map((flow) => (
            <FlowTable key={flow.workflow_id} flow={flow} />
          ))}
        </div>
      </div>
    </>
  )
}
