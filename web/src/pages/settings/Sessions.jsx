import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, DataTable, Dialog, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

/** AUTH-09: where the user is signed in, with sign-out per session. */
export default function Sessions() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { signOut } = useAuth()
  const queryClient = useQueryClient()
  const [confirmCurrent, setConfirmCurrent] = useState(false)
  const sessions = useQuery({ queryKey: ['sessions'], queryFn: () => api.get('auth/sessions') })

  const end = useMutation({
    mutationFn: (session) => api.delete(`auth/sessions/${session.id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sessions'] }),
  })

  const columns = [
    {
      key: 'device',
      label: t('sessions.device'),
      render: (session) => (
        <div className="flex min-w-0 flex-col">
          <span className="font-medium text-ink">{session.name || t('sessions.unknownDevice')}</span>
          {session.user_agent ? <span className="truncate text-caption text-ink-muted">{session.user_agent}</span> : null}
        </div>
      ),
    },
    { key: 'ip', label: t('sessions.ip'), render: (session) => session.ip ?? '' },
    {
      key: 'last',
      label: t('sessions.lastActive'),
      render: (session) =>
        session.current ? (
          <StatusBadge tone="success">{t('sessions.thisDevice')}</StatusBadge>
        ) : (
          formatDateTime(session.last_used_at ?? session.created_at, locale)
        ),
    },
    {
      key: 'action',
      label: <span className="sr-only">{t('sessions.actions')}</span>,
      align: 'end',
      render: (session) => (
        <Button
          variant="danger"
          loading={end.isPending && end.variables?.id === session.id}
          onClick={() => (session.current ? setConfirmCurrent(true) : end.mutate(session))}
          aria-label={t('sessions.signOutOf', { device: session.name || t('sessions.unknownDevice') })}
        >
          {t('sessions.signOut')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('sessions.title')} description={t('sessions.description')} />
      {sessions.isError ? (
        <Alert tone="danger" title={sessions.error.message} action={<Button onClick={() => sessions.refetch()}>{t('common.retry')}</Button>} />
      ) : null}
      {end.isError ? <Alert tone="danger" title={end.error.message} /> : null}
      <div className="overflow-x-auto">
        <DataTable
          caption={t('sessions.title')}
          columns={columns}
          rows={sessions.data?.data ?? []}
          emptyText={sessions.isPending ? t('common.loading') : t('sessions.empty')}
        />
      </div>
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
