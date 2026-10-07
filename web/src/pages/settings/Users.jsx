import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { ConfirmDialog } from './ConfirmDialog'
import { scopeLabel, USER_TONES, useRoles, useScopes } from './users/assignments'
import { RoleList } from './users/RoleList'

const PER_PAGE = 50
const TABS = ['active', 'invitations', 'deactivated']
/** Downloads the access review CSV with the bearer token (RBAC-11). */
async function exportAccessReview() {
  const { blob, filename } = await api.download('access-review?format=csv')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  // Content-Disposition is not exposed to the page by CORS, so the name usually comes from today's local date.
  const today = new Date()
  const date = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-')
  link.download = filename ?? `access-review-${date}.csv`
  document.body.append(link)
  link.click()
  link.remove()
  // Revoked after the click has handed the file to the browser.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

function UsersTable({ status, canInvite, onInvite }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const users = useQuery({
    queryKey: ['users', status, page],
    queryFn: () => api.get(`users?status=${status}&per_page=${PER_PAGE}&page=${page}`),
  })
  const lastPage = users.data?.meta?.last_page ?? 1

  const columns = [
    { key: 'name', label: t('users.columns.name'), render: (user) => <span className="font-medium text-ink">{user.name}</span> },
    { key: 'contact', label: t('users.columns.contact'), render: (user) => user.email ?? user.phone ?? '' },
    { key: 'roles', label: t('users.columns.roles'), render: (user) => <RoleList assignments={user.roles} /> },
    {
      key: 'status',
      label: t('users.columns.status'),
      render: (user) => <StatusBadge tone={USER_TONES[user.status]}>{t(`users.status.${user.status}`)}</StatusBadge>,
    },
  ]

  return (
    <>
      {users.isError ? <Alert tone="danger" title={errorMessage(users.error)} action={<Button onClick={() => users.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t(`users.tabs.${status}`)}
        columns={columns}
        rows={users.data?.data ?? []}
        onRowClick={(user) => navigate(`/settings/users/${user.id}`)}
        emptyText={
          users.isPending ? (
            t('common.loading')
          ) : status === 'active' && canInvite ? (
            <span className="flex flex-col items-center gap-3">
              {t('users.empty.active')}
              <Button variant="primary" icon="plus" onClick={onInvite}>
                {t('users.inviteUser')}
              </Button>
            </span>
          ) : (
            t(`users.empty.${status}`)
          )
        }
      />
      {lastPage > 1 ? (
        <nav aria-label={t('users.pages')} className="flex items-center justify-end gap-3">
          <span className="text-caption text-ink-muted">{t('users.pageOf', { page, last: lastPage })}</span>
          <Button variant="ghost" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>
            {t('users.previous')}
          </Button>
          <Button variant="ghost" disabled={page >= lastPage} onClick={() => setPage((current) => current + 1)}>
            {t('users.next')}
          </Button>
        </nav>
      ) : null}
    </>
  )
}

/** Pending invitations with revoke (AUTH-05). Assignments carry ids only; names come from the role and scope lists. */
function InvitationsTable({ canInvite, onInvite }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const [revoking, setRevoking] = useState(null)
  const invitations = useQuery({ queryKey: ['invitations'], queryFn: () => api.get('invitations?per_page=200') })
  const { roles } = useRoles()
  const scopes = useScopes()

  const names = useMemo(() => {
    const role = new Map(roles.map((r) => [r.id, r.name]))
    const scope = new Map(['company', 'branch', 'location'].flatMap((type) => scopes[type].map((record) => [`${type}:${record.id}`, scopeLabel(type, record)])))
    return { role, scope }
  }, [roles, scopes])

  const revoke = useMutation({
    mutationFn: (invitation) => api.post(`invitations/${invitation.id}/revoke`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['invitations'] })
      setRevoking(null)
    },
  })

  const rows = (invitations.data?.data ?? []).filter((invitation) => invitation.status === 'pending' || invitation.status === 'expired')
  const describe = (assignment) =>
    t('users.roleAt', {
      role: names.role.get(assignment.role_id) ?? t('users.unknownRole'),
      scope:
        assignment.scope_type === 'tenant'
          ? t('users.scopeTypes.tenant')
          : (names.scope.get(`${assignment.scope_type}:${assignment.scope_id}`) ?? t(`users.scopeTypes.${assignment.scope_type}`)),
    })

  const columns = [
    { key: 'name', label: t('users.columns.name'), render: (invitation) => <span className="font-medium text-ink">{invitation.name}</span> },
    { key: 'contact', label: t('users.columns.contact'), render: (invitation) => invitation.email ?? invitation.phone ?? '' },
    {
      key: 'roles',
      label: t('users.columns.roles'),
      render: (invitation) => (
        <ul className="flex flex-col">
          {invitation.assignments.map((assignment, index) => (
            <li key={index}>{describe(assignment)}</li>
          ))}
        </ul>
      ),
    },
    {
      key: 'expires',
      label: t('users.columns.expires'),
      render: (invitation) =>
        invitation.status === 'expired' ? (
          <StatusBadge tone="neutral">{t('users.invitationExpired')}</StatusBadge>
        ) : (
          formatDate(invitation.expires_at, locale)
        ),
    },
    {
      key: 'action',
      label: <span className="sr-only">{t('users.columns.actions')}</span>,
      align: 'end',
      render: (invitation) =>
        invitation.status === 'pending' ? (
          <Button
            variant="secondary"
            onClick={(event) => {
              event.stopPropagation()
              setRevoking(invitation)
            }}
            aria-label={t('users.revokeFor', { name: invitation.name })}
          >
            {t('users.revoke')}
          </Button>
        ) : null,
    },
  ]

  if (!invitations.isPending && !invitations.isError && rows.length === 0) {
    return (
      <div className="flex flex-col items-start gap-3 rounded-lg border border-border bg-surface-200 p-5">
        <p className="text-ink-muted">{t('users.empty.invitations')}</p>
        {canInvite ? (
          <Button variant="primary" icon="plus" onClick={onInvite}>
            {t('users.inviteUser')}
          </Button>
        ) : null}
      </div>
    )
  }

  return (
    <>
      {invitations.isError ? <Alert tone="danger" title={errorMessage(invitations.error)} /> : null}
      <DataTable caption={t('users.tabs.invitations')} columns={columns} rows={rows} emptyText={t('common.loading')} />
      <ConfirmDialog
        open={Boolean(revoking)}
        title={revoking ? t('users.revokeTitle', { name: revoking.name }) : ''}
        confirmLabel={t('users.revokeConfirm')}
        cancelLabel={t('users.keepInvitation')}
        pending={revoke.isPending}
        error={errorMessage(revoke.error)}
        failure={revoke.error}
        onConfirm={() => revoke.mutate(revoking)}
        onClose={() => {
          setRevoking(null)
          revoke.reset()
        }}
      >
        {t('users.revokeText')}
      </ConfirmDialog>
    </>
  )
}

/** AUTH-05, AUTH-13, RBAC-04: the people in the user's scope, invitations, and the access review export. */
export default function Users() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [params, setParams] = useSearchParams()
  const { can } = usePermissions()
  const canInvite = can('core.user.invite')
  const visibleTabs = TABS.filter((value) => value !== 'invitations' || canInvite)
  const tab = visibleTabs.includes(params.get('tab')) ? params.get('tab') : 'active'
  const notice = location.state?.notice

  const download = useMutation({ mutationFn: exportAccessReview })
  const invite = () => navigate('/settings/users/invite')

  const tabs = visibleTabs.map((value) => ({ value, label: t(`users.tabs.${value}`) }))

  return (
    <>
      <PageHeader
        title={t('settings.users.title')}
        description={t('settings.users.description')}
        actions={
          <>
            {can('core.access_review.export') ? (
              <Button icon="download" loading={download.isPending} onClick={() => download.mutate()}>
                {t('users.exportAccessReview')}
              </Button>
            ) : null}
            {canInvite ? (
              <Button variant="primary" icon="plus" onClick={invite}>
                {t('users.inviteUser')}
              </Button>
            ) : null}
          </>
        }
      />
      {notice ? <Alert tone="success" title={notice} /> : null}
      {download.isError ? <Alert tone="danger" title={errorMessage(download.error)} /> : null}
      <Tabs items={tabs} value={tab} onChange={(next) => setParams(next === 'active' ? {} : { tab: next }, { replace: true })} />
      {tab === 'invitations' ? <InvitationsTable canInvite={canInvite} onInvite={invite} /> : <UsersTable key={tab} status={tab} canInvite={canInvite} onInvite={invite} />}
    </>
  )
}
