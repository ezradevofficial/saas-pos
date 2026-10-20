import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, Dialog, Select, StatusBadge } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { CATALOGUE_KEY, DELIVERIES_KEY, DELIVERY_STATUSES, DELIVERY_TONES, deliveryError, eventLabel } from './webhookShared'

const PER_PAGE = 25

function Fact({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="min-w-0 text-body break-words text-ink">{children}</dd>
    </div>
  )
}

/** One delivery: what was sent, what came back, and replay. */
function DeliveryDialog({ deliveryId, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: [...DELIVERIES_KEY, 'one', deliveryId], queryFn: () => api.get(`webhook-deliveries/${deliveryId}`), enabled: Boolean(deliveryId) })
  const replay = useMutation({
    mutationFn: () => api.post(`webhook-deliveries/${deliveryId}/replay`),
    onSuccess: async () => {
      toast.success(t('webhooks.delivery.replayed'))
      await queryClient.invalidateQueries({ queryKey: DELIVERIES_KEY })
      onClose()
    },
  })
  const delivery = query.data?.data

  return (
    <Dialog
      open={Boolean(deliveryId)}
      size="lg"
      title={t('webhooks.delivery.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('ds.dialog.close')}
          </Button>
          {delivery ? (
            <Button icon="sync" loading={replay.isPending} onClick={() => replay.mutate()}>
              {t('webhooks.delivery.replay')}
            </Button>
          ) : null}
        </>
      }
    >
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {replay.error ? <Alert tone="danger" title={errorMessage(replay.error)} /> : null}
      {delivery ? (
        <div className="flex flex-col gap-5 pt-1">
          <dl className="grid gap-4 sm:grid-cols-2">
            <Fact label={t('webhooks.delivery.eventType')}>
              {eventLabel(t, delivery.event_type)} <span className="font-mono text-caption text-ink-muted">{delivery.event_type}</span>
            </Fact>
            <Fact label={t('webhooks.delivery.status')}>
              <StatusBadge tone={DELIVERY_TONES[delivery.status] ?? 'neutral'}>{t(`webhooks.deliveries.statuses.${delivery.status}`)}</StatusBadge>
            </Fact>
            <Fact label={t('webhooks.delivery.eventId')}>
              <span className="font-mono text-caption">{delivery.event_id}</span>
            </Fact>
            <Fact label={t('webhooks.delivery.when')}>
              <span className="tabular-nums">{formatDateTime(delivery.created_at, locale)}</span>
            </Fact>
            <Fact label={t('webhooks.delivery.response')}>
              <span className="tabular-nums">
                {delivery.response_status ?? t('webhooks.deliveries.noAnswer')}
                {delivery.duration_ms != null ? ` · ${t('webhooks.delivery.duration', { ms: delivery.duration_ms })}` : ''}
              </span>
            </Fact>
            <Fact label={t('webhooks.delivery.attempts')}>
              <span className="tabular-nums">{delivery.attempts}</span>
              {delivery.next_attempt_at ? <span className="block text-caption text-ink-muted">{t('webhooks.delivery.nextAttempt', { when: formatDateTime(delivery.next_attempt_at, locale) })}</span> : null}
            </Fact>
          </dl>
          {delivery.error ? <Alert tone={delivery.status === 'failed' ? 'danger' : 'warning'} title={deliveryError(t, delivery.error)} /> : null}
          {delivery.replay_of ? <p className="text-caption text-ink-muted">{t('webhooks.delivery.replayOf')}</p> : null}
          <section className="flex flex-col gap-2">
            <h3 className="text-label text-ink">{t('webhooks.delivery.payload')}</h3>
            {delivery.payload ? (
              <pre className="max-h-72 overflow-auto rounded-md border border-border bg-surface-100 p-3 font-mono text-caption whitespace-pre-wrap text-ink">
                {JSON.stringify(delivery.payload, null, 2)}
              </pre>
            ) : (
              <p className="text-caption text-ink-muted">{t('webhooks.delivery.payloadHidden')}</p>
            )}
          </section>
          {delivery.response_body ? (
            <section className="flex flex-col gap-2">
              <h3 className="text-label text-ink">{t('webhooks.delivery.responseBody')}</h3>
              <pre className="max-h-48 overflow-auto rounded-md border border-border bg-surface-100 p-3 font-mono text-caption whitespace-pre-wrap text-ink">{delivery.response_body}</pre>
            </section>
          ) : null}
        </div>
      ) : null}
    </Dialog>
  )
}

/**
 * HOOK-01: the delivery log, newest first: every event sent to the
 * organisation's webhooks (or one webhook's, with `webhookId`), with its
 * status, the endpoint's answer and the attempts; filtered by status and
 * event; a delivery opens with its body and the answer, and can be replayed.
 */
export function DeliveryLog({ webhookId = null, retentionDays }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(null)
  const catalogue = useQuery({ queryKey: CATALOGUE_KEY, queryFn: () => api.get('webhooks/catalogue') })

  const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) })
  if (webhookId) params.set('webhook', webhookId)
  if (status) params.set('status', status)
  if (type) params.set('type', type)
  const query = useQuery({
    queryKey: [...DELIVERIES_KEY, webhookId ?? 'all', status, type, page],
    queryFn: () => api.get(`webhook-deliveries?${params}`),
    placeholderData: keepPreviousData,
    // While something is still on its way, look again every few seconds.
    refetchInterval: (q) => ((q.state.data?.data ?? []).some((row) => ['pending', 'sending'].includes(row.status)) ? 3000 : false),
  })
  const rows = query.data?.data ?? []
  const meta = query.data?.meta ?? { current_page: 1, last_page: 1 }
  const filtered = Boolean(status || type)
  const types = ['webhook.test.v1', ...(catalogue.data?.data ?? []).map((item) => item.key)]

  const columns = [
    { key: 'created_at', label: t('webhooks.deliveries.columns.created'), render: (row) => <span className="tabular-nums">{formatDateTime(row.created_at, locale)}</span> },
    { key: 'event', label: t('webhooks.deliveries.columns.event'), render: (row) => eventLabel(t, row.event_type) },
    ...(webhookId ? [] : [{ key: 'webhook', label: t('webhooks.deliveries.columns.webhook'), wrap: true, render: (row) => <span className="break-all text-ink-muted">{row.webhook?.url_display}</span> }]),
    {
      key: 'status',
      label: t('webhooks.deliveries.columns.status'),
      render: (row) => <StatusBadge tone={DELIVERY_TONES[row.status] ?? 'neutral'}>{t(`webhooks.deliveries.statuses.${row.status}`)}</StatusBadge>,
    },
    {
      key: 'response',
      label: t('webhooks.deliveries.columns.response'),
      numeric: true,
      render: (row) => (row.response_status != null ? row.response_status : <span className="text-ink-muted">{deliveryError(t, row.error) ?? '–'}</span>),
    },
    { key: 'attempts', label: t('webhooks.deliveries.columns.attempts'), align: 'end', numeric: true, render: (row) => row.attempts },
  ]

  return (
    <section aria-label={t('webhooks.deliveries.title')} className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <Select
          label={t('webhooks.deliveries.filters.status')}
          value={status}
          onChange={(event) => {
            setStatus(event.target.value)
            setPage(1)
          }}
          options={[{ value: '', label: t('webhooks.deliveries.filters.allStatuses') }, ...DELIVERY_STATUSES.map((value) => ({ value, label: t(`webhooks.deliveries.statuses.${value}`) }))]}
        />
        <Select
          label={t('webhooks.deliveries.filters.type')}
          value={type}
          onChange={(event) => {
            setType(event.target.value)
            setPage(1)
          }}
          options={[{ value: '', label: t('webhooks.deliveries.filters.allTypes') }, ...types.map((value) => ({ value, label: eventLabel(t, value) }))]}
        />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t('webhooks.deliveries.title')}
        columns={columns}
        rows={rows}
        loading={query.isPending}
        stale={query.isPlaceholderData}
        onRowClick={(row) => setOpen(row.id)}
        emptyText={filtered ? t('webhooks.deliveries.emptyFiltered') : t('webhooks.deliveries.empty')}
      />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-caption text-ink-muted">{retentionDays ? t('webhooks.deliveries.retention', { days: retentionDays }) : null}</p>
        {meta.last_page > 1 ? (
          <div className="flex items-center gap-2">
            <Button variant="ghost" icon="chevronLeft" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label={t('webhooks.deliveries.previous')} />
            <span className="text-caption text-ink-muted tabular-nums">{t('webhooks.deliveries.pageOf', { page: meta.current_page, last: meta.last_page })}</span>
            <Button variant="ghost" icon="chevronRight" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} aria-label={t('webhooks.deliveries.next')} />
          </div>
        ) : null}
      </div>
      <DeliveryDialog deliveryId={open} onClose={() => setOpen(null)} />
    </section>
  )
}
