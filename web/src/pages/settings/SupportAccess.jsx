import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, Dialog, Select, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ACTIVE_SUPPORT_KEY } from '@/layouts/supportKeys'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

const KEY = ['support-access']
const TONES = { pending: 'warning', approved: 'success', denied: 'neutral', cancelled: 'neutral', revoked: 'danger', expired: 'neutral', ended: 'neutral' }

/**
 * RBAC-13b, RBAC-13e: Settings → Security → Support access. Our support
 * team sees nothing of the business until someone here approves a
 * request, for the hours it asks, read-only or not, with or without
 * payroll, HR and payment data. An approval can be revoked at any time,
 * and every request the session made is listed.
 */
export default function SupportAccess() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { tenantWide } = usePermissions()
  const manage = tenantWide('core.support_access.manage')
  const [deciding, setDeciding] = useState(null)
  const [viewing, setViewing] = useState(null)
  const grants = useQuery({ queryKey: KEY, queryFn: () => api.get('support-access') })
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: KEY }), queryClient.invalidateQueries({ queryKey: ACTIVE_SUPPORT_KEY })])
  const act = useMutation({ mutationFn: ({ id, action }) => api.post(`support-access/${id}/${action}`, {}), onSuccess: refresh })
  const rows = grants.data?.data ?? []
  const pending = rows.filter((row) => row.status === 'pending')
  const active = rows.filter((row) => row.active)
  const when = (value) => (value ? formatDateTime(value, locale) : '')
  const history = [
    { key: 'staff', label: t('supportAccess.columns.staff'), render: (row) => <span className="text-ink">{row.staff_name}</span> },
    { key: 'reason', label: t('supportAccess.columns.reason'), render: (row) => row.reason },
    { key: 'mode', label: t('supportAccess.columns.mode'), render: (row) => t(`supportAccess.modes.${row.mode}`) },
    { key: 'status', label: t('supportAccess.columns.status'), render: (row) => <StatusBadge tone={TONES[row.status] ?? 'neutral'}>{t(`supportAccess.statuses.${row.status}`)}</StatusBadge> },
    { key: 'requested', label: t('supportAccess.columns.requested'), render: (row) => when(row.requested_at) },
    { key: 'requests', label: t('supportAccess.columns.requests'), render: (row) => <span className="tabular-nums">{row.requests}</span> },
  ]
  return (
    <>
      <PageHeader
        eyebrow={<Link to="/settings/security" className="text-caption text-ink-muted hover:text-ink">{t('nav.security')}</Link>}
        title={t('supportAccess.title')}
        description={t('supportAccess.description')}
      />
      {grants.error ? <Alert tone="danger" title={errorMessage(grants.error)} /> : null}
      {act.error ? <Alert tone="danger" title={errorMessage(act.error)} /> : null}

      <section className="flex flex-col gap-3">
        <h2 className="text-h2 text-ink">{t('supportAccess.pendingTitle')}</h2>
        {pending.length === 0 ? <p className="text-body text-ink-muted">{t('supportAccess.pendingEmpty')}</p> : null}
        {pending.map((grant) => (
          <article key={grant.id} aria-label={t('supportAccess.requestTitle', { name: grant.staff_name })} className="flex flex-col gap-3 rounded-lg border border-border bg-surface-200 px-5 py-4">
            <div className="flex flex-col gap-1">
              <h3 className="text-h3 text-ink">{t('supportAccess.requestTitle', { name: grant.staff_name })}</h3>
              <p className="text-caption text-ink-muted">{t('supportAccess.requestMeta', { mode: t(`supportAccess.modes.${grant.mode}`), count: grant.hours, date: when(grant.requested_at) })}</p>
            </div>
            <p className="text-body text-ink">{grant.reason}</p>
            {manage ? (
              <div className="flex flex-wrap justify-end gap-2">
                <Button variant="ghost" onClick={() => act.mutate({ id: grant.id, action: 'deny' })}>{t('supportAccess.deny')}</Button>
                <Button variant="pay" onClick={() => setDeciding(grant)}>{t('supportAccess.approve')}</Button>
              </div>
            ) : null}
          </article>
        ))}
      </section>

      <Card title={t('supportAccess.activeTitle')} subtitle={t('supportAccess.activeText')}>
        {active.length === 0 ? <p className="px-5 py-4 text-body text-ink-muted">{t('supportAccess.activeEmpty')}</p> : null}
        <ul className="flex flex-col">
          {active.map((grant) => (
            <li key={grant.id} className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 first:border-t-0">
              <div className="flex min-w-0 flex-col gap-1">
                <span className="text-body text-ink">{grant.staff_name}</span>
                <span className="text-caption text-ink-muted">
                  {t('supportAccess.activeLine', {
                    mode: t(`supportAccess.modes.${grant.mode}`),
                    until: when(grant.expires_at),
                    sensitive: grant.include_sensitive ? t('supportAccess.sensitiveIncluded') : t('supportAccess.sensitiveHidden'),
                  })}
                </span>
              </div>
              <div className="flex gap-2">
                <Button variant="ghost" onClick={() => setViewing(grant)}>{t('supportAccess.activity')}</Button>
                {manage ? <Button variant="danger" onClick={() => act.mutate({ id: grant.id, action: 'revoke' })}>{t('supportAccess.revoke')}</Button> : null}
              </div>
            </li>
          ))}
        </ul>
      </Card>

      <section className="flex flex-col gap-3">
        <h2 className="text-h2 text-ink">{t('supportAccess.historyTitle')}</h2>
        <DataTable caption={t('supportAccess.historyTitle')} columns={history} rows={rows} loading={grants.isPending} emptyText={t('supportAccess.historyEmpty')} onRowClick={(row) => setViewing(row)} />
      </section>

      {deciding ? <ApproveDialog grant={deciding} onClose={() => setDeciding(null)} onDone={refresh} /> : null}
      {viewing ? <ActivityDialog grant={viewing} onClose={() => setViewing(null)} /> : null}
    </>
  )
}

/** RBAC-13b: the approval records the mode and whether sensitive data is included (off unless ticked). */
function ApproveDialog({ grant, onClose, onDone }) {
  const { t } = useTranslation()
  const [mode, setMode] = useState(grant.mode)
  const [sensitive, setSensitive] = useState(false)
  const approve = useMutation({
    mutationFn: () => api.post(`support-access/${grant.id}/approve`, { include_sensitive: sensitive, mode }),
    onSuccess: async () => {
      await onDone()
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('supportAccess.approveTitle', { name: grant.staff_name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="pay" loading={approve.isPending} onClick={() => approve.mutate()}>{t('supportAccess.approveFor', { count: grant.hours })}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink">{grant.reason}</p>
        <Select
          label={t('supportAccess.columns.mode')}
          value={mode}
          onChange={(event) => setMode(event.target.value)}
          options={[
            { value: 'read', label: t('supportAccess.modes.read') },
            ...(grant.mode === 'write' ? [{ value: 'write', label: t('supportAccess.modes.write') }] : []),
          ]}
        />
        <Checkbox label={t('supportAccess.includeSensitive')} help={t('supportAccess.includeSensitiveHelp')} checked={sensitive} onChange={(event) => setSensitive(event.target.checked)} />
        {approve.error ? <Alert tone="danger" title={errorMessage(approve.error)} /> : null}
      </div>
    </Dialog>
  )
}

/** RBAC-13e: what the session did, from the organisation's own audit log. */
function ActivityDialog({ grant, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const activity = useQuery({ queryKey: [...KEY, grant.id, 'activity'], queryFn: () => api.get(`support-access/${grant.id}/activity`) })
  const columns = [
    { key: 'when', label: t('supportAccess.columns.when'), render: (row) => formatDateTime(row.occurred_at, locale) },
    { key: 'what', label: t('supportAccess.columns.what'), render: (row) => (row.method ? <span className="font-mono text-caption">{`${row.method} ${row.path}`}</span> : t(`supportAccess.actions.${row.action.split('.').pop()}`, { defaultValue: row.action })) },
    { key: 'status', label: t('supportAccess.columns.result'), render: (row) => (row.status ? <span className="tabular-nums">{row.status}</span> : '') },
  ]
  return (
    <Dialog open size="lg" title={t('supportAccess.activityTitle', { name: grant.staff_name })} onClose={onClose}>
      {activity.error ? <Alert tone="danger" title={errorMessage(activity.error)} /> : null}
      <DataTable caption={t('supportAccess.activityTitle', { name: grant.staff_name })} columns={columns} rows={(activity.data?.data ?? []).map((row) => ({ ...row, id: row.seq }))} loading={activity.isPending} emptyText={t('supportAccess.activityEmpty')} />
    </Dialog>
  )
}
