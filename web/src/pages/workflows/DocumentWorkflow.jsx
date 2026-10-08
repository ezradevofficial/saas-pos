import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card, StatusBadge } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import NotFound, { NoAccess } from '@/pages/NotFound'
import { useDuration } from './useDuration'

const STATUS_TONES = { running: 'info', completed: 'success', cancelled: 'neutral' }

/** A relative app path only (the API never sends anything else, but links are checked anyway). */
const appPath = (link) => (typeof link === 'string' && link.startsWith('/') && !link.startsWith('//') ? link : null)

function Detail({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/** Who may act on an open step: named people, else the roles, else nobody (or why nobody). */
function Holders({ holders }) {
  const { t } = useTranslation()
  if (!holders) return null
  if (holders.blocked) return <span className="text-ink-muted">{t('documentWorkflow.nobody')}</span>
  const users = (holders.users ?? []).map((user) => user.name).filter(Boolean)
  const roles = (holders.roles ?? []).map((role) => role.name).filter(Boolean)
  if (users.length > 0) return <span>{t('documentWorkflow.heldBy', { names: users.join(', ') })}</span>
  if (roles.length > 0) return <span>{t('documentWorkflow.heldByRoles', { names: roles.join(', ') })}</span>
  return <span className="text-ink-muted">{t('documentWorkflow.nobody')}</span>
}

/**
 * WF-10: any document's flow, for links from workflow notifications and
 * the automation run log: the document (type, number or title), the
 * flow's status, each open step with who holds it, how long it has waited
 * and when it is due, the approval while one waits, the document's own
 * page when it has one, and the history. Times follow the app's company
 * zone convention (lib/companyTime). The API decides who may see it.
 */
export default function DocumentWorkflow() {
  const { t } = useTranslation()
  const locale = useLocale()
  const duration = useDuration()
  const { companies } = useCompanies()
  const { documentType, documentId } = useParams()
  const query = useQuery({
    queryKey: ['document-workflows', documentType, documentId],
    queryFn: () => api.get(`document-workflows/${encodeURIComponent(documentType)}/${encodeURIComponent(documentId)}`),
    retry: false,
  })

  if (query.isError && query.error?.status === 404) return <NotFound />
  if (query.isError && query.error?.status === 403) return <NoAccess />

  const flow = query.data?.data
  const document = flow?.document ?? {}
  // L10N-03: the API sends the company's zone with the document, so viewers
  // who cannot list companies still read times in the company's zone.
  const timezone = document.timezone ?? companies.find((one) => one.id === document.company_id)?.timezone
  const when = (value) => formatCompanyTime(value, locale, timezone ? { timezone } : null) ?? '—'
  const name = document.number ?? document.title ?? documentId.slice(0, 8)
  const title = flow ? t('documentWorkflow.title', { type: document.type_label ?? documentType, name }) : t('documentWorkflow.loading')
  const documentLink = appPath(document.link)

  return (
    <>
      <PageHeader title={title} description={document.number && document.title ? document.title : t('documentWorkflow.description')} />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {flow ? (
        <div className="flex flex-col gap-5">
          <Card>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Detail label={t('documentWorkflow.status')}>
                <StatusBadge tone={STATUS_TONES[flow.status] ?? 'neutral'}>{t(`documentWorkflow.statuses.${flow.status}`, { defaultValue: flow.status })}</StatusBadge>
              </Detail>
              <Detail label={t('documentWorkflow.started')}>
                {when(flow.started_at)}
                {flow.started_by?.name ? ` · ${flow.started_by.name}` : ''}
              </Detail>
              <Detail label={t('documentWorkflow.version')}>{t('documentWorkflow.versionValue', { number: flow.version?.number })}</Detail>
              {flow.completed_at ? <Detail label={t('documentWorkflow.completed')}>{when(flow.completed_at)}</Detail> : null}
              {flow.cancelled_at ? (
                <Detail label={t('documentWorkflow.cancelled')}>
                  {when(flow.cancelled_at)}
                  {flow.cancel_reason ? ` · ${flow.cancel_reason}` : ''}
                </Detail>
              ) : null}
            </dl>
            {documentLink ? (
              <Link to={documentLink} className="mt-4 inline-block w-fit text-label text-primary hover:text-primary-hover">
                {t('documentWorkflow.openDocument')}
              </Link>
            ) : null}
          </Card>

          <section aria-labelledby="document-workflow-current" className="flex flex-col gap-3">
            <h2 id="document-workflow-current" className="text-h3 text-ink">
              {t('documentWorkflow.current')}
            </h2>
            {(flow.current ?? []).length === 0 ? <p className="text-ink-muted">{t('documentWorkflow.noCurrent')}</p> : null}
            {(flow.current ?? []).map((step) => (
              <div key={step.token_id} className="flex flex-col gap-1 rounded-md border border-border bg-surface-200 p-3">
                <span className="font-medium text-ink">{step.name}</span>
                <Holders holders={step.holders} />
                <span>{t('documentWorkflow.inStep', { time: duration(step.seconds_in_stage) })}</span>
                {step.due_at ? (
                  <span className={step.overdue ? 'text-danger' : 'text-ink-muted'}>
                    {t(step.overdue ? 'documentWorkflow.overdue' : 'documentWorkflow.due', { when: when(step.due_at) })}
                  </span>
                ) : null}
                {step.holders?.approval_id ? (
                  <Link to={`/approvals/${step.holders.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
                    {t('documentWorkflow.openApproval')}
                  </Link>
                ) : null}
              </div>
            ))}
          </section>

          <section aria-labelledby="document-workflow-history" className="flex flex-col gap-3">
            <h2 id="document-workflow-history" className="text-h3 text-ink">
              {t('documentWorkflow.history')}
            </h2>
            {(flow.history ?? []).length === 0 ? (
              <p className="text-ink-muted">{t('documentWorkflow.noHistory')}</p>
            ) : (
              <ol className="flex flex-col gap-2" aria-label={t('documentWorkflow.history')}>
                {flow.history.map((event, index) => (
                  <li key={`${event.type}-${index}`} className="flex flex-wrap gap-x-2 text-body">
                    <span className="text-ink">{t(`documentWorkflow.events.${event.type}`, { defaultValue: event.type, step: event.node_name ?? '' })}</span>
                    {event.user?.name ? <span className="text-ink-muted">{event.user.name}</span> : null}
                    <span className="text-ink-muted tabular-nums">{when(event.occurred_at)}</span>
                    {event.reason ? <span className="w-full text-ink-muted">{event.reason}</span> : null}
                  </li>
                ))}
              </ol>
            )}
          </section>
        </div>
      ) : null}
    </>
  )
}
