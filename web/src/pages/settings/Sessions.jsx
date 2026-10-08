import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, Dialog, ListView, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { actionsColumn } from '@/lib/listColumns'
import { useServerList } from '@/lib/useServerList'

/**
 * AUTH-09: where the user is signed in, with sign-out per session; search,
 * sort, pages, columns and export (EXP-01, LAY-04).
 */
export default function Sessions() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { signOut } = useAuth()
  const queryClient = useQueryClient()
  const [confirmCurrent, setConfirmCurrent] = useState(false)

  const end = useMutation({
    mutationFn: (session) => api.delete(`auth/sessions/${session.id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sessions'] }),
  })

  const columns = [
    {
      key: 'device',
      label: t('sessions.device'),
      sortKey: 'device',
      hideable: false,
      render: (session) => (
        <div className="flex min-w-0 flex-col">
          <span className="font-medium text-ink">{session.name || t('sessions.unknownDevice')}</span>
          {session.user_agent ? <span className="truncate text-caption text-ink-muted">{session.user_agent}</span> : null}
        </div>
      ),
    },
    { key: 'ip', label: t('sessions.ip'), sortKey: 'ip', render: (session) => <span className="tabular-nums">{session.ip ?? ''}</span> },
    {
      key: 'last',
      label: t('sessions.lastActive'),
      sortKey: 'last_active',
      exportKey: 'last_active',
      render: (session) =>
        session.current ? (
          <StatusBadge tone="success">{t('sessions.thisDevice')}</StatusBadge>
        ) : (
          formatDateTime(session.last_used_at ?? session.created_at, locale)
        ),
    },
    {
      key: 'created_at',
      label: t('sessions.signedIn'),
      sortKey: 'created_at',
      defaultHidden: true,
      render: (session) => (session.created_at ? formatDateTime(session.created_at, locale) : ''),
    },
    actionsColumn(t('sessions.actions'), (session) => (
      <Button
        variant="danger"
        loading={end.isPending && end.variables?.id === session.id}
        onClick={() => (session.current ? setConfirmCurrent(true) : end.mutate(session))}
        aria-label={t('sessions.signOutOf', { device: session.name || t('sessions.unknownDevice') })}
      >
        {t('sessions.signOut')}
      </Button>
    )),
  ]
  const list = useServerList({ id: 'sessions', endpoint: 'auth/sessions', queryKey: ['sessions'], columns })

  return (
    <>
      <PageHeader title={t('sessions.title')} description={t('sessions.description')} />
      {end.isError ? <Alert tone="danger" title={end.error.message} /> : null}
      <ListView
        list={list}
        title={t('sessions.title')}
        searchPlaceholder={t('sessions.searchPlaceholder')}
        emptyText={list.term ? t('sessions.emptyFiltered') : t('sessions.empty')}
      />
      <Dialog
        open={confirmCurrent}
        title={t('sessions.confirmTitle')}
        onClose={() => setConfirmCurrent(false)}
        footer={
          <>
            <Button onClick={() => setConfirmCurrent(false)}>{t('sessions.stay')}</Button>
            <Button variant="danger" onClick={() => signOut()}>
              {t('sessions.signOutHere')}
            </Button>
          </>
        }
      >
        {t('sessions.confirmText')}
      </Dialog>
    </>
  )
}
