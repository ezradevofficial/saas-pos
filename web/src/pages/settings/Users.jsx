import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, ListView, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate, formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { actionsColumn } from '@/lib/listColumns'
import { useServerList } from '@/lib/useServerList'
import { ConfirmDialog } from './ConfirmDialog'
import { scopeLabel, USER_TONES, useRoles, useScopes } from './users/assignments'
import { RoleList } from './users/RoleList'

const TABS = ['active', 'invitations', 'deactivated']
const INVITATION_TONES = { pending: 'warning', expired: 'neutral', accepted: 'success', revoked: 'neutral' }

/** Downloads the access review CSV with the bearer token (RBAC-11). */
async function exportAccessReview() {
  const { blob, filename } = await api.download('access-review?format=csv')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  // The API exposes Content-Disposition through CORS; today's local date is the fallback name.
  const today = new Date()
  const date = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-')
  link.download = filename ?? `access-review-${date}.csv`
  document.body.append(link)
  link.click()
  link.remove()
  // Revoked after the click has handed the file to the browser.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

/** Active or deactivated users (AUTH-13): search, sort, pages, columns and export (EXP-01, LAY-04). */
function UsersTable({ status, canInvite, onInvite }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()

  const columns = [
    { key: 'name', label: t('users.columns.name'), sortKey: 'name', hideable: false, render: (user) => <span className="font-medium text-ink">{user.name}</span> },
    { key: 'email', label: t('users.columns.email'), sortKey: 'email', render: (user) => user.email ?? '' },
    { key: 'phone', label: t('users.columns.phone'), sortKey: 'phone', render: (user) => <span className="tabular-nums">{user.phone ?? ''}</span> },
    { key: 'roles', label: t('users.columns.roles'), render: (user) => <RoleList assignments={user.roles} /> },
    {
      key: 'status',
      label: t('users.columns.status'),
      sortKey: 'status',
      render: (user) => <StatusBadge tone={USER_TONES[user.status]}>{t(`users.status.${user.status}`)}</StatusBadge>,
    },
    {
      key: 'two_factor',
      label: t('users.columns.twoFactor'),
      defaultHidden: true,
      render: (user) => (user.two_factor_enabled ? t('users.twoFactorOn') : t('users.twoFactorOff')),
    },
    {
      key: 'last_sign_in_at',
      label: t('users.columns.lastSignIn'),
      sortKey: 'last_sign_in_at',
      defaultHidden: true,
      render: (user) => (user.last_sign_in_at ? formatDateTime(user.last_sign_in_at, locale) : ''),
    },
  ]

  const list = useServerList({ id: 'users', endpoint: 'users', queryKey: ['users', status], params: { status }, columns })

  return (
    <ListView
      list={list}
      title={t(`users.tabs.${status}`)}
      searchPlaceholder={t('users.searchPlaceholder')}
      onRowClick={(user) => navigate(`/settings/users/${user.id}`)}
      emptyText={
        list.term ? (
          t('users.emptyFiltered')
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
  )
}

/**
 * Invitations with revoke (AUTH-05): every status, newest first, with
 * search, sort, pages, columns and export (EXP-01). Assignments carry ids
 * only; names come from the role and scope lists.
 */
function InvitationsTable({ canInvite, onInvite }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const [revoking, setRevoking] = useState(null)
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

  const describe = (assignment) =>
    t('users.roleAt', {
      role: names.role.get(assignment.role_id) ?? t('users.unknownRole'),
      scope:
        assignment.scope_type === 'tenant'
          ? t('users.scopeTypes.tenant')
          : (names.scope.get(`${assignment.scope_type}:${assignment.scope_id}`) ?? t(`users.scopeTypes.${assignment.scope_type}`)),
    })

  const columns = [
    {
      key: 'name',
      label: t('users.columns.name'),
      sortKey: 'name',
      hideable: false,
      render: (invitation) => <span className="font-medium text-ink">{invitation.name}</span>,
    },
    { key: 'email', label: t('users.columns.email'), sortKey: 'email', render: (invitation) => invitation.email ?? '' },
    { key: 'phone', label: t('users.columns.phone'), sortKey: 'phone', render: (invitation) => <span className="tabular-nums">{invitation.phone ?? ''}</span> },
    {
      key: 'roles',
      label: t('users.columns.roles'),
      render: (invitation) => (
        <ul className="flex flex-col">
          {(invitation.assignments ?? []).map((assignment, index) => (
            <li key={index}>{describe(assignment)}</li>
          ))}
        </ul>
      ),
    },
    {
      key: 'status',
      label: t('users.columns.status'),
      render: (invitation) => (
        <StatusBadge tone={INVITATION_TONES[invitation.status] ?? 'neutral'}>{t(`users.invitationStatus.${invitation.status}`)}</StatusBadge>
      ),
    },
    { key: 'expires_at', label: t('users.columns.expires'), sortKey: 'expires_at', render: (invitation) => formatDate(invitation.expires_at, locale) },
    {
      key: 'created_at',
      label: t('users.columns.invited'),
      sortKey: 'created_at',
      defaultHidden: true,
      render: (invitation) => (invitation.created_at ? formatDate(invitation.created_at, locale) : ''),
    },
    actionsColumn(t('users.columns.actions'), (invitation) =>
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
    ),
  ]

  const list = useServerList({ id: 'invitations', endpoint: 'invitations', queryKey: ['invitations'], columns })

  return (
    <>
      <ListView
        list={list}
        title={t('users.tabs.invitations')}
        searchPlaceholder={t('users.searchPlaceholder')}
        emptyText={
          list.term ? (
            t('users.emptyFiltered')
          ) : canInvite ? (
            <span className="flex flex-col items-center gap-3">
              {t('users.empty.invitations')}
              <Button variant="primary" icon="plus" onClick={onInvite}>
                {t('users.inviteUser')}
              </Button>
            </span>
          ) : (
            t('users.empty.invitations')
          )
        }
      />
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
      {/* A tab change clears the list's search, sort and page: each tab is its own list. */}
      <Tabs items={tabs} value={tab} onChange={(next) => setParams(next === 'active' ? {} : { tab: next }, { replace: true })} />
      {tab === 'invitations' ? <InvitationsTable canInvite={canInvite} onInvite={invite} /> : <UsersTable key={tab} status={tab} canInvite={canInvite} onInvite={invite} />}
    </>
  )
}
