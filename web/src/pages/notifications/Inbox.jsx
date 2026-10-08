import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, ListView, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { actionsColumn } from '@/lib/listColumns'
import { notificationPath, useNotificationActions, useUnreadCount } from '@/lib/notifications'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'

/** Tabs and the API's `status` for each: All is every notification not archived. */
const TABS = [
  ['unread', 'unread'],
  ['all', 'active'],
  ['archived', 'archived'],
]
const DEFAULT_TAB = 'all'

/** One tab's list: search, sort, pages, columns and export (EXP-01, LAY-04). */
function NotificationList({ tab, status }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { markRead, archive } = useNotificationActions()

  const open = (notification) => {
    if (!notification.read_at) markRead.mutate(notification)
    const path = notificationPath(notification)
    if (path) navigate(path)
  }

  const stop = (handler) => (event) => {
    event.stopPropagation()
    handler()
  }

  const columns = [
    {
      key: 'read',
      label: t('notifications.columns.state'),
      sortKey: 'read_at',
      render: (notification) =>
        notification.read_at ? (
          <StatusBadge tone="neutral">{t('notifications.state.read')}</StatusBadge>
        ) : (
          <StatusBadge tone="info">{t('notifications.state.unread')}</StatusBadge>
        ),
    },
    {
      key: 'subject',
      label: t('notifications.columns.subject'),
      sortKey: 'subject',
      hideable: false,
      render: (notification) => (
        <div className="flex min-w-0 flex-col">
          <span className={notification.read_at ? 'text-ink' : 'font-medium text-ink'}>{notification.subject}</span>
          {notification.body ? <span className="line-clamp-2 text-caption text-ink-muted">{notification.body}</span> : null}
        </div>
      ),
    },
    { key: 'type', label: t('notifications.columns.type'), render: (notification) => notification.event_label ?? notification.event_type },
    {
      key: 'created_at',
      label: t('notifications.columns.received'),
      sortKey: 'created_at',
      render: (notification) => <span className="tabular-nums">{formatDateTime(notification.created_at, locale)}</span>,
    },
    { key: 'body', label: t('notifications.columns.message'), defaultHidden: true, render: (notification) => notification.body ?? '' },
    actionsColumn(t('notifications.columns.actions'), (notification) => (
      <div className="flex justify-end gap-2">
        {!notification.read_at && !notification.archived_at ? (
          <Button
            variant="ghost"
            loading={markRead.isPending && markRead.variables?.id === notification.id}
            onClick={stop(() => markRead.mutate(notification))}
            aria-label={t('notifications.markReadFor', { subject: notification.subject })}
          >
            {t('notifications.markRead')}
          </Button>
        ) : null}
        {!notification.archived_at ? (
          <Button
            variant="ghost"
            icon="archive"
            loading={archive.isPending && archive.variables?.id === notification.id}
            onClick={stop(() => archive.mutate(notification))}
            aria-label={t('notifications.archiveFor', { subject: notification.subject })}
          >
            {t('notifications.archive')}
          </Button>
        ) : null}
      </div>
    )),
  ]

  const list = useServerList({
    id: 'notifications',
    endpoint: 'notifications',
    queryKey: ['notifications', 'inbox', status],
    params: { status },
    defaultSort: '-created_at',
    columns,
  })
  const failure = markRead.error ?? archive.error

  return (
    <>
      {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
      <ListView
        list={list}
        title={t(`notifications.tabs.${tab}`)}
        searchPlaceholder={t('notifications.searchPlaceholder')}
        onRowClick={open}
        emptyText={list.term ? t('notifications.emptyFiltered') : t(`notifications.empty.${tab}`)}
      />
    </>
  )
}

/** NOT-01: the signed-in user's notifications, unread, all or archived. */
export default function Inbox() {
  const { t } = useTranslation()
  const locale = useLocale()
  const [params, setParams] = useSearchParams()
  const unread = useUnreadCount()
  const { markAllRead } = useNotificationActions()
  const tab = TABS.some(([value]) => value === params.get('tab')) ? params.get('tab') : DEFAULT_TAB
  const status = TABS.find(([value]) => value === tab)[1]
  const count = unread.data ?? 0

  const tabs = TABS.map(([value]) => ({
    value,
    label: t(`notifications.tabs.${value}`),
    count: value === 'unread' && count > 0 ? formatInteger(count, locale) : null,
  }))

  return (
    <>
      <PageHeader
        title={t('notifications.title')}
        description={t('notifications.description')}
        actions={
          <Button disabled={count === 0} loading={markAllRead.isPending} onClick={() => markAllRead.mutate()}>
            {t('notifications.markAllRead')}
          </Button>
        }
      />
      {markAllRead.isError ? <Alert tone="danger" title={errorMessage(markAllRead.error)} /> : null}
      {/* A tab change clears the list's search, sort and page: each tab is its own list. */}
      <Tabs items={tabs} value={tab} onChange={(next) => setParams(next === DEFAULT_TAB ? {} : { tab: next }, { replace: true })} />
      <NotificationList key={tab} tab={tab} status={status} />
    </>
  )
}
