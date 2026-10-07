import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { CopyRoleDialog } from './roles/CopyRoleDialog'

/** RBAC-02, RBAC-03: the tenant's roles. System roles are marked and copied; custom roles open for editing. */
export default function Roles() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { tenantWide } = usePermissions()
  const [copying, setCopying] = useState(null)
  const roles = useQuery({ queryKey: ['roles', 'list'], queryFn: () => api.get('roles?per_page=200') })
  const canCreate = tenantWide('core.role.create')

  const columns = [
    {
      key: 'name',
      label: t('roles.columns.name'),
      render: (role) => (
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span className="font-medium text-ink">{role.name}</span>
          {role.is_system ? <StatusBadge tone="neutral">{t('roles.system')}</StatusBadge> : null}
        </span>
      ),
    },
    { key: 'description', label: t('roles.columns.description'), render: (role) => <span className="text-ink-muted">{role.description ?? ''}</span> },
    {
      key: 'permissions',
      label: t('roles.columns.permissions'),
      align: 'end',
      numeric: true,
      render: (role) => t('roles.permissionCount', { count: role.permissions?.length ?? 0 }),
    },
    {
      key: 'twoFactor',
      label: t('roles.columns.twoFactor'),
      render: (role) => (role.requires_two_factor ? <StatusBadge tone="info">{t('roles.twoFactorRequired')}</StatusBadge> : null),
    },
    {
      key: 'action',
      label: <span className="sr-only">{t('roles.columns.actions')}</span>,
      align: 'end',
      render: (role) =>
        role.is_system && canCreate ? (
          <Button
            variant="ghost"
            icon="copy"
            onClick={(event) => {
              event.stopPropagation()
              setCopying(role)
            }}
            aria-label={t('roles.copyRole', { name: role.name })}
          >
            {t('roles.copy')}
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('settings.roles.title')}
        description={t('settings.roles.description')}
        actions={
          canCreate ? (
            <Button variant="primary" icon="plus" onClick={() => navigate('/settings/roles/new')}>
              {t('roles.create')}
            </Button>
          ) : null
        }
      />
      {roles.isError ? <Alert tone="danger" title={errorMessage(roles.error)} action={<Button onClick={() => roles.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t('settings.roles.title')}
        columns={columns}
        rows={roles.data?.data ?? []}
        onRowClick={(role) => navigate(`/settings/roles/${role.id}`)}
        emptyText={roles.isPending ? t('common.loading') : t('roles.empty')}
      />
      {copying ? <CopyRoleDialog role={copying} onClose={() => setCopying(null)} /> : null}
    </>
  )
}
