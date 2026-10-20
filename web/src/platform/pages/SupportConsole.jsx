import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from '../platformClient'

const KEY = ['platform', 'support', 'requests']

const SUPPORT_TONES = { pending: 'warning', approved: 'success', denied: 'neutral', cancelled: 'neutral', revoked: 'danger', expired: 'neutral', ended: 'neutral' }

/**
 * RBAC-13, ADM-03: the staff member's support requests. An approved one
 * opens a support session in the tenant back office (a new tab): the
 * console hands over a one-time code, never the session's token.
 */
export default function SupportConsole() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const requests = useQuery({ queryKey: KEY, queryFn: () => platformApi.get('platform/support/requests'), refetchInterval: 30_000 })
  const act = useMutation({
    mutationFn: async ({ id, action }) => {
      const response = await platformApi.post(`platform/support/requests/${id}/${action}`, {})
      if (action === 'session') {
        // The tenant web exchanges the code (60 seconds, once) for the session.
        window.open(`${window.location.origin}/support/enter#code=${encodeURIComponent(response.data.entry_code)}`, '_blank', 'noopener')
      }
      return response
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
  const when = (value) => (value ? formatDateTime(value, locale) : '')
  const columns = [
    { key: 'tenant', label: t('admin.support.tenant'), render: (row) => <span className="text-ink">{row.tenant_name}</span> },
    { key: 'staff', label: t('admin.support.staff'), render: (row) => row.staff?.name ?? '' },
    { key: 'mode', label: t('admin.support.mode'), render: (row) => t(`admin.support.modes.${row.mode}`) },
    { key: 'sensitive', label: t('admin.support.sensitive'), render: (row) => (row.include_sensitive ? t('admin.support.included') : t('admin.support.hidden')) },
    { key: 'status', label: t('admin.support.status'), render: (row) => <StatusBadge tone={SUPPORT_TONES[row.status] ?? 'neutral'}>{t(`admin.support.statuses.${row.status}`)}</StatusBadge> },
    { key: 'expires', label: t('admin.support.until'), render: (row) => (row.active ? when(row.expires_at) : t('admin.support.hoursAsked', { count: row.hours })) },
    {
      key: 'actions',
      label: t('subscription.invoices.columns.actions'),
      render: (row) =>
        !row.mine ? null : row.active ? (
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" loading={act.isPending && act.variables?.id === row.id && act.variables?.action === 'session'} onClick={() => act.mutate({ id: row.id, action: 'session' })}>
              {t('admin.support.open')}
            </Button>
            <Button variant="ghost" onClick={() => act.mutate({ id: row.id, action: 'end' })}>{t('admin.support.end')}</Button>
          </div>
        ) : row.status === 'pending' ? (
          <Button variant="ghost" onClick={() => act.mutate({ id: row.id, action: 'cancel' })}>{t('admin.support.cancel')}</Button>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader title={t('admin.support.title')} description={t('admin.support.description')} />
      {requests.error ? <Alert tone="danger" title={errorMessage(requests.error)} /> : null}
      {act.error ? <Alert tone="danger" title={errorMessage(act.error)} /> : null}
      <DataTable caption={t('admin.support.title')} columns={columns} rows={requests.data?.data ?? []} loading={requests.isPending} emptyText={t('admin.support.empty')} />
    </>
  )
}
