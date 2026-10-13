import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatBytes, formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'

const EXPORTS_KEY = ['tenant-exports']
const TONES = { queued: 'neutral', running: 'warning', ready: 'success', failed: 'danger', expired: 'neutral' }
const ACTIVE = ['queued', 'running']

function ExportRow({ item }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const when = formatWhen(item.created_at, locale)

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-4">
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-3">
          <span className="font-medium text-ink">{t('dataExport.requestedAt', { when })}</span>
          <StatusBadge tone={TONES[item.status] ?? 'neutral'}>{t(`dataExport.status.${item.status}`)}</StatusBadge>
        </div>
        <p className="text-caption text-ink-muted">
          {[
            item.requested_by?.name ? t('dataExport.by', { name: item.requested_by.name }) : null,
            item.size != null ? formatBytes(item.size, locale) : null,
            item.status === 'ready' && item.expires_at ? t('dataExport.expires', { when: formatWhen(item.expires_at, locale) }) : null,
            item.status === 'failed' ? t('dataExport.failedText') : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </div>
      {item.download_url ? (
        <a href={item.download_url} className="font-medium text-primary hover:text-primary-hover" aria-label={t('dataExport.downloadFor', { when })}>
          {t('dataExport.download')}
        </a>
      ) : null}
    </li>
  )
}

/**
 * EXP-02: Settings → Data export (Owners only). Request a full export of
 * the organisation's data, follow it while it is prepared, and download it
 * (only the Owner who asked can). Explains what is in it and what is left out.
 */
export default function DataExport() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: EXPORTS_KEY,
    queryFn: () => api.get('tenant-exports'),
    refetchInterval: (q) => ((q.state.data?.data ?? []).some((item) => ACTIVE.includes(item.status)) ? 3000 : false),
  })
  const request = useMutation({
    mutationFn: () => api.post('tenant-exports'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: EXPORTS_KEY }),
  })
  const exports = query.data?.data ?? []
  const meta = query.data?.meta ?? {}
  const busy = exports.some((item) => ACTIVE.includes(item.status))

  return (
    <>
      <PageHeader title={t('dataExport.title')} description={t('dataExport.description')} />
      <div className="flex flex-col gap-5">
        <Card title={t('dataExport.request.title')} subtitle={t('dataExport.request.text', { hours: meta.link_hours ?? 24, days: meta.keep_days ?? 7 })}>
          <div className="flex flex-col gap-4">
            {request.error ? <Alert tone="danger" title={errorMessage(request.error)} /> : null}
            {busy ? <Alert tone="info" title={t('dataExport.preparing')} /> : null}
            <div>
              <Button variant="primary" icon="download" loading={request.isPending} disabled={busy} onClick={() => request.mutate()}>
                {t('dataExport.request.action')}
              </Button>
            </div>
            <p className="text-caption text-ink-muted">{t('dataExport.request.limit', { count: meta.per_day ?? 3 })}</p>
          </div>
        </Card>

        <Card title={t('dataExport.contents.title')}>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <p className="font-medium text-ink">{t('dataExport.contents.included')}</p>
              <ul className="flex list-disc flex-col gap-1 pl-5 text-ink">
                <li>{t('dataExport.contents.tables')}</li>
                <li>{t('dataExport.contents.files')}</li>
                <li>{t('dataExport.contents.manifest')}</li>
              </ul>
            </div>
            <div className="flex flex-col gap-2">
              <p className="font-medium text-ink">{t('dataExport.contents.excluded')}</p>
              <ul className="flex list-disc flex-col gap-1 pl-5 text-ink">
                <li>{t('dataExport.contents.secrets')}</li>
                <li>{t('dataExport.contents.sessions')}</li>
              </ul>
            </div>
          </div>
        </Card>

        <Card title={t('dataExport.past.title')}>
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
          {!query.isPending && !query.isError && exports.length === 0 ? <p className="text-ink-muted">{t('dataExport.past.empty')}</p> : null}
          <ul className="divide-y divide-border">
            {exports.map((item) => (
              <ExportRow key={item.id} item={item} />
            ))}
          </ul>
        </Card>
      </div>
    </>
  )
}
