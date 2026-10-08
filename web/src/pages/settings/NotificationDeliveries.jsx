import { useTranslation } from 'react-i18next'
import { ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'

const DELIVERY_STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'skipped', 'pending_digest', 'digested']
const CHANNELS = ['in_app', 'email', 'push', 'sms', 'whatsapp']

/** Status as a dot and a word: only a failure is red; waiting is neutral. */
const TONES = {
  queued: 'neutral',
  pending_digest: 'neutral',
  sending: 'info',
  sent: 'info',
  delivered: 'success',
  digested: 'success',
  skipped: 'warning',
  failed: 'danger',
}

/**
 * NOT-06: every notification the organisation sent, per recipient and
 * channel, with its status, the reason it was skipped or the last error,
 * and the attempts; filtered by status and channel, searched, sorted and
 * exported (EXP-01).
 */
export default function NotificationDeliveries() {
  const { t } = useTranslation()
  const locale = useLocale()
  const date = (value) => (value ? <span className="tabular-nums">{formatDateTime(value, locale)}</span> : '')

  const columns = [
    { key: 'created_at', label: t('notificationDeliveries.columns.created'), sortKey: 'created_at', render: (row) => date(row.created_at) },
    {
      key: 'user',
      label: t('notificationDeliveries.columns.recipient'),
      sortKey: 'user',
      hideable: false,
      render: (row) => (
        <div className="flex min-w-0 flex-col">
          <span className="font-medium text-ink">{row.user?.name ?? t('notificationDeliveries.unknownUser')}</span>
          {row.recipient ? <span className="truncate text-caption text-ink-muted">{row.recipient}</span> : null}
        </div>
      ),
    },
    { key: 'recipient', label: t('notificationDeliveries.columns.sentTo'), defaultHidden: true, render: (row) => row.recipient ?? '' },
    { key: 'type', label: t('notificationDeliveries.columns.type'), sortKey: 'event_type', render: (row) => row.event_label ?? row.event_type },
    { key: 'channel', label: t('notificationDeliveries.columns.channel'), sortKey: 'channel', render: (row) => t(`notificationDeliveries.channels.${row.channel}`) },
    {
      key: 'status',
      label: t('notificationDeliveries.columns.status'),
      sortKey: 'status',
      render: (row) => <StatusBadge tone={TONES[row.status] ?? 'neutral'}>{t(`notificationDeliveries.statuses.${row.status}`)}</StatusBadge>,
    },
    {
      key: 'reason',
      label: t('notificationDeliveries.columns.reason'),
      render: (row) => <span className="text-caption text-ink-muted">{row.reason_label ?? row.error ?? ''}</span>,
    },
    { key: 'error', label: t('notificationDeliveries.columns.error'), defaultHidden: true, render: (row) => row.error ?? '' },
    { key: 'attempts', label: t('notificationDeliveries.columns.attempts'), sortKey: 'attempts', align: 'end', render: (row) => <span className="tabular-nums">{row.attempts ?? 0}</span> },
    { key: 'sent_at', label: t('notificationDeliveries.columns.sent'), sortKey: 'sent_at', defaultHidden: true, render: (row) => date(row.sent_at) },
  ]

  const list = useServerList({
    id: 'notification-deliveries',
    endpoint: 'notification-deliveries',
    queryKey: ['notification-deliveries'],
    filters: { status: '', channel: '' },
    defaultSort: '-created_at',
    columns,
  })
  const filtered = Boolean(list.term || list.filters.status || list.filters.channel)

  return (
    <>
      <PageHeader title={t('notificationDeliveries.title')} description={t('notificationDeliveries.description')} />
      <ListView
        list={list}
        title={t('notificationDeliveries.title')}
        searchPlaceholder={t('notificationDeliveries.searchPlaceholder')}
        filterFields={[
          {
            name: 'status',
            label: t('notificationDeliveries.filters.status'),
            options: [
              { value: '', label: t('notificationDeliveries.filters.allStatuses') },
              ...DELIVERY_STATUSES.map((value) => ({ value, label: t(`notificationDeliveries.statuses.${value}`) })),
            ],
          },
          {
            name: 'channel',
            label: t('notificationDeliveries.filters.channel'),
            options: [
              { value: '', label: t('notificationDeliveries.filters.allChannels') },
              ...CHANNELS.map((value) => ({ value, label: t(`notificationDeliveries.channels.${value}`) })),
            ],
          },
        ]}
        emptyText={filtered ? t('notificationDeliveries.emptyFiltered') : t('notificationDeliveries.empty')}
      />
    </>
  )
}
