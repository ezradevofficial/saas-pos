import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button, Icon, StatusBadge } from '@/components/ds'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { formatDateTime } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { notificationPath, useLatestNotifications, useNotificationActions, useUnreadCount } from '@/lib/notifications'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'

/**
 * The bell (NOT-01): the unread count, polled every minute and on window
 * focus; its popover lists the latest notifications, unread first. Opening
 * one marks it read and follows its link; "Mark all as read" and "See all"
 * (the inbox) sit at the top and the bottom.
 */
export function NotificationBell({ className }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const unread = useUnreadCount()
  const count = unread.data ?? 0
  const latest = useLatestNotifications({ enabled: open })
  const { markRead, markAllRead } = useNotificationActions()
  const rows = latest.data ?? []

  const label = count > 0 ? t('notifications.bell.labelUnread', { count, formatted: formatInteger(count, locale) }) : t('notifications.bell.label')

  const openNotification = (notification) => {
    if (!notification.read_at) markRead.mutate(notification)
    const path = notificationPath(notification)
    if (path) {
      setOpen(false)
      navigate(path)
    }
  }

  const seeAll = () => {
    setOpen(false)
    navigate('/notifications')
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          aria-label={label}
          className={cn('relative size-icon-btn px-0 text-sidebar-ink hover:bg-sidebar-active hover:text-sidebar-ink-active', className)}
        >
          <Icon name="bell" size={18} />
          {count > 0 ? (
            <span
              aria-hidden="true"
              data-testid="unread-count"
              className="absolute -top-1 -right-1 rounded-pill bg-surface-300 px-1 text-caption text-ink tabular-nums"
            >
              {count > 99 ? '99+' : formatInteger(count, locale)}
            </span>
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        aria-label={t('notifications.bell.title')}
        className="w-panel gap-0 rounded-md border border-border bg-surface-200 p-0 text-body text-ink shadow-lg ring-0"
      >
        <header className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
          <h2 className="text-h3 text-ink">{t('notifications.bell.title')}</h2>
          <Button
            variant="ghost"
            className="px-2"
            disabled={count === 0}
            loading={markAllRead.isPending}
            onClick={() => markAllRead.mutate()}
          >
            {t('notifications.markAllRead')}
          </Button>
        </header>
        <div className="max-h-panel overflow-y-auto">
          {latest.isPending ? (
            <p className="px-4 py-6 text-center text-ink-muted">{t('common.loading')}</p>
          ) : latest.isError ? (
            <p className="px-4 py-6 text-center text-ink-muted">{t('notifications.bell.loadFailed')}</p>
          ) : rows.length === 0 ? (
            <p className="px-4 py-6 text-center text-ink-muted">{t('notifications.bell.empty')}</p>
          ) : (
            <ul aria-label={t('notifications.bell.latest')} className="flex flex-col">
              {rows.map((notification) => (
                <li key={notification.id} className="border-b border-border last:border-b-0">
                  <button
                    type="button"
                    onClick={() => openNotification(notification)}
                    className="flex w-full flex-col gap-1 px-4 py-3 text-left transition-colors hover:bg-surface-300"
                  >
                    <span className={cn('text-body text-ink', !notification.read_at && 'font-medium')}>{notification.subject}</span>
                    {notification.body ? <span className="line-clamp-2 text-caption text-ink-muted">{notification.body}</span> : null}
                    <span className="flex flex-wrap items-center gap-2 text-caption text-ink-muted">
                      {!notification.read_at ? <StatusBadge tone="info">{t('notifications.state.unread')}</StatusBadge> : null}
                      <span>{formatDateTime(notification.created_at, locale)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <footer className="border-t border-border p-2">
          <Button variant="ghost" block onClick={seeAll}>
            {t('notifications.bell.seeAll')}
          </Button>
        </footer>
      </PopoverContent>
    </Popover>
  )
}
